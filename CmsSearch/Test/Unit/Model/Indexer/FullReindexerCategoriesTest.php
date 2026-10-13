<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\CatalogIdSource;
use Thedevkitchen\CmsSearch\Model\Indexer\CategoryIndexer;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\FullReindexer;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexManager;
use Thedevkitchen\CmsSearch\Model\Indexer\PageIndexer;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReferenceResolver;

class FullReindexerCategoriesTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];
    /** @var list<array{0: int, 1: int, 2: int|null}> */
    private array $catalogIdCalls = [];

    private function reindexer(
        CategoryIndexer $categories,
        CatalogIdSource $catalogIds,
        IndexManager $indexManager,
        ?string $failingGraphType = null
    ): FullReindexer {
        $store = new StoreContext(2, 2, 's2', 'pt_BR', 'https://s/', 'https://s/media/', 'home');
        $stores = $this->createMock(StoreContextProvider::class);
        $stores->method('get')->with(2)->willReturn($store);
        $references = $this->createMock(BlockReferenceResolver::class);
        $references->method('refreshForHosts')->willReturnCallback(function (string $type, array $ids, int $storeId) use ($failingGraphType): int {
            if ($type === $failingGraphType) {
                throw new \RuntimeException('graph write failed');
            }
            $this->calls[] = sprintf('graph %s %s %d', $type, implode(',', $ids), $storeId);
            return 0;
        });
        $pages = $this->createMock(PageIndexer::class);
        $pages->method('reindex')->willReturnCallback(function (array $ids, array $s, array $targets): array {
            $this->calls[] = 'pages ' . implode(',', $ids) . ' ' . json_encode($targets);
            return ['upserted' => count($ids), 'deleted' => 0];
        });
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $catalogIds->method('nextPageIds')->willReturnOnConsecutiveCalls([1, 2], [5]);
        return new FullReindexer(
            $stores,
            $pages,
            $indexManager,
            $locks,
            $references,
            $categories,
            $catalogIds,
            2
        );
    }

    private function indexManager(): IndexManager
    {
        $indexManager = $this->createMock(IndexManager::class);
        $indexManager->method('createIndex')->willReturn('idx_2_v2');
        $indexManager->method('refresh')->willReturnCallback(function (string $index): void {
            $this->calls[] = 'refresh ' . $index;
        });
        $indexManager->method('switchAlias')->willReturnCallback(function (StoreContext $store, string $index): array {
            $this->calls[] = 'switch ' . $index;
            return ['deleted' => ['idx_2_v1'], 'leftovers' => []];
        });
        $indexManager->method('dropIndex')->willReturnCallback(function (string $index): void {
            $this->calls[] = 'drop ' . $index;
        });
        return $indexManager;
    }

    public function testPagesAndCategoriesAreRefreshedThenIndexedIntoTheNewIndex(): void
    {
        $categories = $this->createMock(CategoryIndexer::class);
        $categories->method('reindex')->willReturnCallback(function (array $ids, array $s, array $targets): array {
            $this->calls[] = 'categories ' . implode(',', $ids) . ' ' . json_encode($targets);
            return ['upserted' => 1, 'deleted' => 0];
        });
        $catalogIds = $this->createMock(CatalogIdSource::class);
        $batches = [[37, 38], [40]];
        $catalogIds->expects($this->exactly(2))->method('nextCategoryIds')->willReturnCallback(function (int $after, int $limit, ?int $storeId = null) use (&$batches): array {
            $this->catalogIdCalls[] = [$after, $limit, $storeId];
            return array_shift($batches);
        });
        $lines = [];
        $result = $this->reindexer($categories, $catalogIds, $this->indexManager())->rebuild(2, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame([
            'Store 2: 2 categor(ies) read, 1 category document(s) written (last category id 38).',
            'Store 2: 3 categor(ies) read, 2 category document(s) written (last category id 40).',
        ], array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'categor'))));
        $this->assertSame([[0, 2, 2], [38, 2, 2]], $this->catalogIdCalls, 'keyset batches of the batch size, for the store view being built');
        $this->assertSame([
            'graph page 1,2 2', 'pages 1,2 {"2":"idx_2_v2"}',
            'graph page 5 2', 'pages 5 {"2":"idx_2_v2"}',
            'graph category 37,38 2', 'categories 37,38 {"2":"idx_2_v2"}',
            'graph category 40 2', 'categories 40 {"2":"idx_2_v2"}',
            'refresh idx_2_v2', 'switch idx_2_v2',
        ], $this->calls);
        $this->assertSame(
            [['store_id' => 2, 'index' => 'idx_2_v2', 'documents' => 3, 'categories' => 2, 'removed' => ['idx_2_v1'], 'leftovers' => []]],
            $result['stores']
        );
    }

    public function testAFailedCategoryBatchDropsTheNewIndexAndKeepsTheAlias(): void
    {
        $categories = $this->createMock(CategoryIndexer::class);
        $categories->method('reindex')->willThrowException(new \RuntimeException('bulk item error'));
        $catalogIds = $this->createMock(CatalogIdSource::class);
        $catalogIds->method('nextCategoryIds')->willReturn([37]);
        try {
            $this->reindexer($categories, $catalogIds, $this->indexManager())->rebuild(2);
            $this->fail('expected an IndexingException');
        } catch (IndexingException $exception) {
            $this->assertStringContainsString('bulk item error', $exception->getMessage());
        }
        $this->assertContains('drop idx_2_v2', $this->calls);
        $this->assertNotContains('switch idx_2_v2', $this->calls);
        $this->assertNotContains('refresh idx_2_v2', $this->calls);
    }

    public function testAFailedCategoryGraphRefreshAlsoKeepsTheAlias(): void
    {
        $categories = $this->createMock(CategoryIndexer::class);
        $categories->expects($this->never())->method('reindex');
        $catalogIds = $this->createMock(CatalogIdSource::class);
        $catalogIds->method('nextCategoryIds')->willReturn([37]);
        $reindexer = $this->reindexer($categories, $catalogIds, $this->indexManager(), 'category');
        try {
            $reindexer->rebuild(2);
            $this->fail('expected an IndexingException');
        } catch (IndexingException $exception) {
            $this->assertStringContainsString('graph write failed', $exception->getMessage());
        }
        $this->assertSame([
            'graph page 1,2 2', 'pages 1,2 {"2":"idx_2_v2"}', 'graph page 5 2', 'pages 5 {"2":"idx_2_v2"}', 'drop idx_2_v2',
        ], $this->calls);
    }
}
