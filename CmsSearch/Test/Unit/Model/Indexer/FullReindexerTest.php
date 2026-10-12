<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\FullReindexer;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexManager;
use Thedevkitchen\CmsSearch\Model\Indexer\PageIndexer;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ResourceConnectionStubTrait;

class FullReindexerTest extends TestCase
{
    use ResourceConnectionStubTrait;

    private StoreContextProvider&MockObject $stores;
    private PageIndexer&MockObject $pageIndexer;
    private IndexManager&MockObject $indexManager;
    private LockManagerInterface&MockObject $locks;
    /** @var list<string> */
    private array $calls = [];
    /** @var array<string, true> locks held by "another process" */
    private array $heldElsewhere = [];

    protected function setUp(): void
    {
        $this->stores = $this->createMock(StoreContextProvider::class);
        $this->pageIndexer = $this->createMock(PageIndexer::class);
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->indexManager->method('createIndex')->willReturnCallback(function (StoreContext $store): string {
            $this->calls[] = 'create ' . $store->getStoreId();
            return 'idx_' . $store->getStoreId() . '_v2';
        });
        $this->indexManager->method('refresh')->willReturnCallback(function (string $index): void {
            $this->calls[] = 'refresh ' . $index;
        });
        $this->indexManager->method('switchAlias')->willReturnCallback(function (StoreContext $store, string $index): array {
            $this->calls[] = 'switch ' . $index;
            return ['deleted' => ['idx_' . $store->getStoreId() . '_v1'], 'leftovers' => []];
        });
        $this->indexManager->method('dropIndex')->willReturnCallback(function (string $index): void {
            $this->calls[] = 'drop ' . $index;
        });
        $this->locks = $this->createMock(LockManagerInterface::class);
        $this->locks->method('lock')->willReturnCallback(function (string $name, int $timeout): bool {
            $this->calls[] = 'lock ' . $name . ' ' . $timeout;
            return !isset($this->heldElsewhere[$name]);
        });
        $this->locks->method('unlock')->willReturnCallback(function (string $name): bool {
            $this->calls[] = 'unlock ' . $name;
            return true;
        });
    }

    private function store(int $id): StoreContext
    {
        return new StoreContext($id, 2, 's' . $id, 'pt_BR', 'https://s.test/', 'https://s.test/media/', 'home');
    }

    private function reindexer(ResourceConnection $resource): FullReindexer
    {
        return new FullReindexer($resource, $this->stores, $this->pageIndexer, $this->indexManager, $this->locks, 2);
    }

    private function fillRecorder(): void
    {
        $this->pageIndexer->method('reindex')->willReturnCallback(function (array $ids, array $stores, array $targets): array {
            $this->calls[] = 'fill ' . implode(',', $ids) . ' -> '
                . implode(',', array_map(static fn (StoreContext $s): int => $s->getStoreId(), $stores)) . ':'
                . implode(',', array_map(static fn ($k, $v): string => $k . '=' . $v, array_keys($targets), $targets));
            return ['upserted' => count($ids) - 1, 'deleted' => 0];
        });
    }

    public function testEveryStoreViewGetsANewIndexFilledInBatchesBeforeTheAliasMoves(): void
    {
        $this->stores->method('all')->willReturn([$this->store(1), $this->store(2)]);
        $this->fillRecorder();
        $this->indexManager->expects($this->once())->method('dropOrphans')->with()->willReturnCallback(function (): array {
            $this->calls[] = 'orphans';
            return ['idx_9_v1'];
        });
        $result = $this->reindexer($this->resourceReturning([], [['1', '2'], ['3'], ['1', '2'], ['3']]))->rebuild();
        $lock = 'lock thedevkitchen_cmssearch_rebuild_';
        $this->assertSame([
            $lock . '1 0', 'create 1', 'fill 1,2 -> 1:1=idx_1_v2', 'fill 3 -> 1:1=idx_1_v2', 'refresh idx_1_v2', 'switch idx_1_v2',
            'unlock thedevkitchen_cmssearch_rebuild_1',
            $lock . '2 0', 'create 2', 'fill 1,2 -> 2:2=idx_2_v2', 'fill 3 -> 2:2=idx_2_v2', 'refresh idx_2_v2', 'switch idx_2_v2',
            'unlock thedevkitchen_cmssearch_rebuild_2',
            'orphans',
        ], $this->calls);
        $this->assertSame([
            'stores' => [
                ['store_id' => 1, 'index' => 'idx_1_v2', 'documents' => 1, 'removed' => ['idx_1_v1'], 'leftovers' => []],
                ['store_id' => 2, 'index' => 'idx_2_v2', 'documents' => 1, 'removed' => ['idx_2_v1'], 'leftovers' => []],
            ],
            'orphans' => ['idx_9_v1'],
        ], $result);
        $this->assertSame(
            [['page_id > ?', 0], ['page_id > ?', 2], ['page_id > ?', 0], ['page_id > ?', 2]],
            $this->wheres
        );
    }

    public function testAFullLastBatchIsFollowedByOneMoreQuery(): void
    {
        $this->stores->method('get')->willReturn($this->store(2));
        $this->fillRecorder();
        $result = $this->reindexer($this->resourceReturning([], [['1', '2'], []]))->rebuild(2);
        $this->assertSame([['page_id > ?', 0], ['page_id > ?', 2]], $this->wheres);
        $this->assertSame(1, $result['stores'][0]['documents']);
    }

    public function testAStoreViewWithoutPagesStillGetsAnEmptyIndexBehindItsAlias(): void
    {
        $this->stores->method('get')->willReturn($this->store(2));
        $this->pageIndexer->expects($this->never())->method('reindex');
        $result = $this->reindexer($this->resourceReturning([], [[]]))->rebuild(2);
        $this->assertContains('switch idx_2_v2', $this->calls);
        $this->assertSame(0, $result['stores'][0]['documents']);
    }

    public function testASingleStoreRebuildKeepsTheOtherIndices(): void
    {
        $this->stores->method('get')->with(2)->willReturn($this->store(2));
        $this->stores->expects($this->never())->method('all');
        $this->pageIndexer->method('reindex')->willReturn(['upserted' => 1, 'deleted' => 0]);
        $this->indexManager->expects($this->never())->method('dropOrphans');
        $result = $this->reindexer($this->resourceReturning([], [['5']]))->rebuild(2);
        $this->assertSame(
            [['store_id' => 2, 'index' => 'idx_2_v2', 'documents' => 1, 'removed' => ['idx_2_v1'], 'leftovers' => []]],
            $result['stores']
        );
        $this->assertSame([], $result['orphans']);
        $this->assertSame(['create 2', 'refresh idx_2_v2', 'switch idx_2_v2'], array_values(array_filter(
            $this->calls,
            static fn (string $call): bool => preg_match('/^(create|refresh|switch|drop) /', $call) === 1
        )));
    }

    public function testAnUnknownStoreViewIsRefusedBeforeAnythingIsCreated(): void
    {
        $this->stores->method('get')->willReturn(null);
        $this->indexManager->expects($this->never())->method('createIndex');
        $this->locks->expects($this->never())->method('lock');
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('Store view 99 does not exist.');
        $this->reindexer($this->resourceReturning())->rebuild(99);
    }

    public function testAFailedRebuildDropsTheNewIndexAndLeavesTheAliasAlone(): void
    {
        $this->stores->method('get')->willReturn($this->store(2));
        $this->pageIndexer->method('reindex')->willThrowException(new IndexingException('1 of 2 bulk operation(s) failed'));
        $this->indexManager->expects($this->never())->method('switchAlias');
        try {
            $this->reindexer($this->resourceReturning([], [['5']]))->rebuild(2);
            $this->fail('IndexingException expected');
        } catch (IndexingException $exception) {
            $this->assertSame('Rebuild of store view 2 failed: 1 of 2 bulk operation(s) failed', $exception->getMessage());
        }
        $this->assertSame([
            'lock thedevkitchen_cmssearch_rebuild_2 0', 'create 2', 'drop idx_2_v2', 'unlock thedevkitchen_cmssearch_rebuild_2',
        ], $this->calls);
    }

    public function testAFailedRefreshDropsTheNewIndexBeforeTheAliasCouldMove(): void
    {
        $this->stores->method('get')->willReturn($this->store(2));
        $this->pageIndexer->method('reindex')->willReturn(['upserted' => 1, 'deleted' => 0]);
        $refresh = $this->createMock(IndexManager::class);
        $refresh->method('createIndex')->willReturn('idx_2_v2');
        $refresh->method('refresh')->willThrowException(new IndexingException('OpenSearch request failed (refresh)'));
        $refresh->expects($this->never())->method('switchAlias');
        $refresh->expects($this->once())->method('dropIndex')->with('idx_2_v2');
        $this->indexManager = $refresh;
        $this->expectExceptionMessage('Rebuild of store view 2 failed: OpenSearch request failed (refresh)');
        $this->reindexer($this->resourceReturning([], [['5']]))->rebuild(2);
    }

    /** switchAlias() throws only when the alias did NOT move, so the new version is still safe to drop. */
    public function testAFailedSwitchDropsTheNewIndex(): void
    {
        $this->stores->method('get')->willReturn($this->store(2));
        $this->pageIndexer->method('reindex')->willReturn(['upserted' => 1, 'deleted' => 0]);
        $switch = $this->createMock(IndexManager::class);
        $switch->method('createIndex')->willReturn('idx_2_v2');
        $switch->method('switchAlias')->willThrowException(new IndexingException('does not have the current mapping'));
        $switch->expects($this->once())->method('dropIndex')->with('idx_2_v2');
        $this->indexManager = $switch;
        $this->expectExceptionMessage('Rebuild of store view 2 failed: does not have the current mapping');
        $this->reindexer($this->resourceReturning([], [['5']]))->rebuild(2);
    }

    public function testALaterFailureNeverDropsAnIndexThatIsAlreadyLive(): void
    {
        $this->stores->method('all')->willReturn([$this->store(1), $this->store(2)]);
        $this->pageIndexer->method('reindex')->willReturnCallback(function (array $ids, array $stores): array {
            if ($stores[0]->getStoreId() === 2) {
                throw new IndexingException('No alive nodes');
            }
            return ['upserted' => 1, 'deleted' => 0];
        });
        $this->indexManager->expects($this->never())->method('dropOrphans');
        try {
            $this->reindexer($this->resourceReturning([], [['5'], ['5']]))->rebuild();
            $this->fail('IndexingException expected');
        } catch (IndexingException $exception) {
            $this->assertSame(
                'Rebuild of store view 2 failed: No alive nodes (store view(s) already rebuilt: 1)',
                $exception->getMessage()
            );
        }
        $this->assertSame(['drop idx_2_v2'], array_values(array_filter(
            $this->calls,
            static fn (string $call): bool => str_starts_with($call, 'drop ')
        )));
        $this->assertContains('switch idx_1_v2', $this->calls);
    }

    public function testAFailingCleanupKeepsTheOriginalError(): void
    {
        $this->stores->method('get')->willReturn($this->store(2));
        $this->pageIndexer->method('reindex')->willThrowException(new IndexingException('bulk failed'));
        $drop = $this->createMock(IndexManager::class);
        $drop->method('createIndex')->willReturn('idx_2_v2');
        $drop->expects($this->once())->method('dropIndex')->willThrowException(new IndexingException('cluster gone'));
        $this->indexManager = $drop;
        $this->expectExceptionMessage('Rebuild of store view 2 failed: bulk failed');
        $this->reindexer($this->resourceReturning([], [['5']]))->rebuild(2);
    }

    public function testAnIndexThatCannotBeCreatedFailsTheRebuildWithoutCleanup(): void
    {
        $this->stores->method('get')->willReturn($this->store(2));
        $create = $this->createMock(IndexManager::class);
        $create->method('createIndex')->willThrowException(new IndexingException('OpenSearch request failed (create)'));
        $create->expects($this->never())->method('dropIndex');
        $create->expects($this->never())->method('switchAlias');
        $this->indexManager = $create;
        $this->expectExceptionMessage('Rebuild of store view 2 failed: OpenSearch request failed (create)');
        try {
            $this->reindexer($this->resourceReturning())->rebuild(2);
        } finally {
            $this->assertSame(
                ['lock thedevkitchen_cmssearch_rebuild_2 0', 'unlock thedevkitchen_cmssearch_rebuild_2'],
                $this->calls
            );
        }
    }

    public function testASecondRebuildOfTheSameStoreViewFailsFastAndTouchesNothing(): void
    {
        $this->heldElsewhere['thedevkitchen_cmssearch_rebuild_2'] = true;
        $this->stores->method('get')->willReturn($this->store(2));
        $this->indexManager->expects($this->never())->method('createIndex');
        $this->indexManager->expects($this->never())->method('dropIndex');
        $this->locks->expects($this->never())->method('unlock');
        try {
            $this->reindexer($this->resourceReturning())->rebuild(2);
            $this->fail('IndexingException expected');
        } catch (IndexingException $exception) {
            $this->assertSame(
                'Another full rebuild of store view 2 is running; nothing was changed. Try again when it has finished.',
                $exception->getMessage()
            );
        }
        $this->assertSame(['lock thedevkitchen_cmssearch_rebuild_2 0'], $this->calls);
    }

    public function testTheLockIsPerStoreView(): void
    {
        $this->heldElsewhere['thedevkitchen_cmssearch_rebuild_2'] = true;
        $this->stores->method('get')->willReturn($this->store(3));
        $this->pageIndexer->method('reindex')->willReturn(['upserted' => 1, 'deleted' => 0]);
        $result = $this->reindexer($this->resourceReturning([], [['5']]))->rebuild(3);
        $this->assertSame(3, $result['stores'][0]['store_id']);
    }

    public function testLeftoverOldVersionsAreReported(): void
    {
        $this->stores->method('get')->willReturn($this->store(2));
        $this->pageIndexer->method('reindex')->willReturn(['upserted' => 1, 'deleted' => 0]);
        $leftover = $this->createMock(IndexManager::class);
        $leftover->method('createIndex')->willReturn('idx_2_v3');
        $leftover->method('switchAlias')->willReturn(['deleted' => ['idx_2_v1'], 'leftovers' => ['idx_2_v2']]);
        $leftover->expects($this->never())->method('dropIndex');
        $this->indexManager = $leftover;
        $result = $this->reindexer($this->resourceReturning([], [['5']]))->rebuild(2);
        $this->assertSame(['idx_2_v1'], $result['stores'][0]['removed']);
        $this->assertSame(['idx_2_v2'], $result['stores'][0]['leftovers']);
    }

    public function testProgressIsReported(): void
    {
        $this->stores->method('get')->willReturn($this->store(2));
        $this->fillRecorder();
        $lines = [];
        $this->reindexer($this->resourceReturning([], [['1', '2'], ['3']]))
            ->rebuild(2, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });
        $this->assertSame([
            'Store 2: building idx_2_v2.',
            'Store 2: 2 page(s) read, 1 document(s) written (last page id 2).',
            'Store 2: 3 page(s) read, 1 document(s) written (last page id 3).',
            'Store 2: idx_2_v2 refreshed; switching the alias.',
        ], $lines);
    }
}
