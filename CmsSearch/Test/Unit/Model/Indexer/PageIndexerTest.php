<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\ClassificationReader;
use Thedevkitchen\CmsSearch\Model\Indexer\ContentTypeReader;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\ContentTypeState;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PreparedContent;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\TermState;
use Thedevkitchen\CmsSearch\Model\Indexer\DocumentBuilder;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexManager;
use Thedevkitchen\CmsSearch\Model\Indexer\PageIndexer;
use Thedevkitchen\CmsSearch\Model\Indexer\PageLinkReader;
use Thedevkitchen\CmsSearch\Model\Indexer\PageSnapshotReader;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;
use Thedevkitchen\CmsSearch\Model\Indexer\VisibilityChecker;
use Thedevkitchen\CmsSearch\Model\Reference\BlockTextResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostEnrichment;

class PageIndexerTest extends TestCase
{
    private const ALIAS = 'magento2_thedevkitchen_cmssearch_';

    private PageSnapshotReader&MockObject $pages;
    private PageLinkReader&MockObject $links;
    private ContentTypeReader&MockObject $types;
    private ClassificationReader&MockObject $terms;
    private ShadowedPageReader&MockObject $shadows;
    private IndexManager&MockObject $indexManager;
    private BlockTextResolver&MockObject $blockText;
    /** @var list<array{0: string, 1: list<string>, 2: bool}> target, "action id" list, incremental */
    private array $bulks = [];
    /** @var list<array{0: string, 1: string, 2: int}> target, document id, store_view_id of the document */
    private array $documents = [];
    /** @var array<int, int> page id => prepare() calls */
    private array $prepared = [];
    /** @var array<int, list<int>> page id => store views where it is shadowed */
    private array $shadowed = [];
    /** @var array<int, list<int>> page id => store ids (default [2, 3]) */
    private array $storesOf = [];

    protected function setUp(): void
    {
        $this->blockText = $this->createMock(BlockTextResolver::class); // unconfigured: [] = no block text
        $this->pages = $this->createMock(PageSnapshotReader::class);
        $this->links = $this->createMock(PageLinkReader::class);
        $this->types = $this->createMock(ContentTypeReader::class);
        $this->terms = $this->createMock(ClassificationReader::class);
        $this->shadows = $this->createMock(ShadowedPageReader::class);
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->indexManager->method('ensureAlias')->willReturnCallback(
            static fn (StoreContext $store): string => self::ALIAS . $store->getStoreId()
        );
        $this->indexManager->method('bulk')->willReturnCallback(function (string $target, array $operations, bool $incremental): void {
            $this->record($target, $operations, $incremental);
        });
        // pages 1..9 exist (store views 2 and 3 unless $storesOf says otherwise), linked to active type 3 / term 9 of
        // website 2; page 4 is disabled
        $this->pages->method('read')->willReturnCallback(function (array $ids): array {
            $snapshots = [];
            foreach ($ids as $id) {
                if ($id <= 9) {
                    $snapshots[$id] = new PageSnapshot($id, 'p' . $id, 'Page ' . $id, '', '<p>x</p>', '', $id !== 4, $this->storesOf[$id] ?? [2, 3], null);
                }
            }
            return $snapshots;
        });
        $this->links->method('read')->willReturnCallback(
            static fn (array $ids): array => array_fill_keys(array_values(array_filter($ids, static fn (int $id): bool => $id <= 9)), ['type_id' => 3, 'term_id' => 9])
        );
        $this->types->method('read')->willReturn([3 => new ContentTypeState(3, 'news', true)]);
        $this->terms->method('read')->willReturn([9 => new TermState(9, 2, '9', [9], true)]);
        $this->shadows->method('read')->willReturnCallback(
            fn (array $pages, array $storeIds): array => array_intersect_key($this->shadowed, $pages)
        );
    }

    /** @param list<array<string, mixed>> $operations */
    private function record(string $target, array $operations, bool $incremental): void
    {
        $this->bulks[] = [$target, array_map(static fn (array $op): string => $op['action'] . ' ' . $op['id'], $operations), $incremental];
        foreach ($operations as $op) {
            if ($op['action'] === 'index') {
                $this->documents[] = [$target, $op['id'], $op['document']['store_view_id']];
            }
        }
    }

    private function indexer(int $batchSize = 200): PageIndexer
    {
        $prepared = &$this->prepared;
        $builder = new class(new TextExtractor(), new NullLogger(), $prepared) extends DocumentBuilder {
            /** @param array<int, int> $calls */
            public function __construct(TextExtractor $extractor, NullLogger $logger, private array &$calls)
            {
                parent::__construct($extractor, $logger);
            }

            public function prepare(PageSnapshot $page): PreparedContent
            {
                $this->calls[$page->getPageId()] = ($this->calls[$page->getPageId()] ?? 0) + 1;
                return parent::prepare($page);
            }
        };
        return new PageIndexer(
            $this->pages,
            $this->links,
            $this->types,
            $this->terms,
            $this->shadows,
            new VisibilityChecker(),
            $builder,
            $this->indexManager,
            $this->blockText,
            $batchSize
        );
    }

    private function store(int $storeId, int $websiteId = 2): StoreContext
    {
        return new StoreContext($storeId, $websiteId, 's' . $storeId, 'pt_BR', 'https://s.test/', 'https://s.test/media/', 'home');
    }

    public function testVisiblePagesAreUpsertedIntoEveryStoreViewAlias(): void
    {
        $this->indexManager->expects($this->exactly(2))->method('ensureAlias');
        $result = $this->indexer()->reindex([1, 2], [$this->store(2), $this->store(3)]);
        $this->assertSame(['upserted' => 4, 'deleted' => 0], $result);
        $this->assertSame([
            [self::ALIAS . '2', ['index page_1', 'index page_2'], true],
            [self::ALIAS . '3', ['index page_1', 'index page_2'], true],
        ], $this->bulks);
        $this->assertSame([
            [self::ALIAS . '2', 'page_1', 2],
            [self::ALIAS . '2', 'page_2', 2],
            [self::ALIAS . '3', 'page_1', 3],
            [self::ALIAS . '3', 'page_2', 3],
        ], $this->documents, 'each store view gets its own document');
    }

    public function testHiddenAndMissingPagesAreDeletedByTheirDeterministicId(): void
    {
        $result = $this->indexer()->reindex([4, 50, 1], [$this->store(2), $this->store(5, 3)]);
        $this->assertSame(['upserted' => 1, 'deleted' => 5], $result);
        $this->assertSame([
            [self::ALIAS . '2', ['index page_1', 'delete page_4', 'delete page_50'], true],
            [self::ALIAS . '5', ['delete page_1', 'delete page_4', 'delete page_50'], true],
        ], $this->bulks);
    }

    public function testEveryStoreViewIsEvaluatedSoAStaleDocumentIsDeleted(): void
    {
        $this->storesOf = [1 => [2]]; // page 1 was moved out of store view 3
        $result = $this->indexer()->reindex([1], [$this->store(2), $this->store(3), $this->store(4)]);
        $this->assertSame(['upserted' => 1, 'deleted' => 2], $result);
        $this->assertSame([
            [self::ALIAS . '2', ['index page_1'], true],
            [self::ALIAS . '3', ['delete page_1'], true],
            [self::ALIAS . '4', ['delete page_1'], true],
        ], $this->bulks);
    }

    public function testPagesAreReadAndWrittenInBatches(): void
    {
        $this->pages->expects($this->exactly(3))->method('read');
        $this->links->expects($this->exactly(3))->method('read');
        $this->types->expects($this->exactly(3))->method('read')->with([3]);
        $this->terms->expects($this->exactly(3))->method('read')->with([9]);
        $this->shadows->expects($this->exactly(3))->method('read');
        $this->indexManager->expects($this->once())->method('ensureAlias');
        $this->indexer(2)->reindex([5, 1, 2, 3, 3, 6], [$this->store(2)]);
        $this->assertSame([
            [self::ALIAS . '2', ['index page_1', 'index page_2'], true],
            [self::ALIAS . '2', ['index page_3', 'index page_5'], true],
            [self::ALIAS . '2', ['index page_6'], true],
        ], $this->bulks);
    }

    public function testEachBatchReadsOnlyItsOwnPagesAndTheTypesAndTermsOfTheirLinks(): void
    {
        $this->pages = $this->createMock(PageSnapshotReader::class);
        $calls = [];
        $this->pages->expects($this->exactly(2))->method('read')->willReturnCallback(function (array $ids) use (&$calls): array {
            $calls[] = $ids;
            return [];
        });
        $this->links->expects($this->exactly(2))->method('read');
        $this->indexer(2)->reindex([60, 2, 1], [$this->store(2)]);
        $this->assertSame([
            [self::ALIAS . '2', ['delete page_1', 'delete page_2'], true],
            [self::ALIAS . '2', ['delete page_60'], true],
        ], $this->bulks);
        $this->assertSame([[1, 2], [60]], $calls);
    }

    public function testTheShadowReaderGetsTheSnapshotsOfTheBatchAndTheStoreViews(): void
    {
        $this->shadows->expects($this->once())->method('read')->with(
            $this->callback(static fn (array $pages): bool => array_keys($pages) === [1, 4] && $pages[1] instanceof PageSnapshot),
            [2, 5]
        )->willReturn([]);
        $this->indexer()->reindex([1, 4, 50], [$this->store(2), $this->store(5, 3)]);
    }

    public function testAShadowedPageOfAllStoreViewsIsDeletedWhereAStoreViewPageWins(): void
    {
        $this->storesOf = [2 => [0], 3 => [0]];
        $this->shadowed = [2 => [2]]; // e.g. `home` of store 0 vs the `home` page of store view 2
        $result = $this->indexer()->reindex([2, 3], [$this->store(2), $this->store(3)]);
        $this->assertSame(['upserted' => 3, 'deleted' => 1], $result);
        $this->assertSame([
            [self::ALIAS . '2', ['delete page_2', 'index page_3'], true],
            [self::ALIAS . '3', ['index page_2', 'index page_3'], true],
        ], $this->bulks);
    }

    public function testAShadowedPageIsNotAddedByAFullRebuild(): void
    {
        $this->storesOf = [2 => [0]];
        $this->shadowed = [2 => [2]];
        $result = $this->indexer()->reindex([1, 2], [$this->store(2)], [2 => self::ALIAS . '2_v8']);
        $this->assertSame(['upserted' => 1, 'deleted' => 0], $result);
        $this->assertSame([[self::ALIAS . '2_v8', ['index page_1'], false]], $this->bulks);
    }

    public function testContentIsPreparedOncePerVisiblePageAndNeverForAnInvisibleOne(): void
    {
        $this->storesOf = [3 => [3], 5 => [7]];
        // 1, 2: visible in 2 and 3; 3: visible in 3 only (prepared lazily there); 4: disabled; 5: other store view;
        // 50: missing
        $this->indexer()->reindex([1, 2, 3, 4, 5, 50], [$this->store(2), $this->store(3), $this->store(5, 3)]);
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], $this->prepared);
    }

    public function testNothingIsPreparedWhenNoStoreViewShowsThePage(): void
    {
        $this->indexer()->reindex([1, 4], [$this->store(5, 3), $this->store(6, 3)]);
        $this->assertSame([], $this->prepared);
        $this->assertSame([
            [self::ALIAS . '5', ['delete page_1', 'delete page_4'], true],
            [self::ALIAS . '6', ['delete page_1', 'delete page_4'], true],
        ], $this->bulks);
    }

    public function testAFullRebuildOnlyAddsVisiblePagesToTheNewIndex(): void
    {
        $this->indexManager->expects($this->never())->method('ensureAlias');
        $result = $this->indexer()->reindex([1, 4, 50], [$this->store(2)], [2 => self::ALIAS . '2_v8']);
        $this->assertSame(['upserted' => 1, 'deleted' => 0], $result);
        $this->assertSame([[self::ALIAS . '2_v8', ['index page_1'], false]], $this->bulks);
    }

    public function testAFullRebuildWithoutVisiblePagesWritesNothing(): void
    {
        $this->indexManager->expects($this->never())->method('bulk');
        $this->assertSame(['upserted' => 0, 'deleted' => 0], $this->indexer()->reindex([4, 50], [$this->store(2)], [2 => self::ALIAS . '2_v8']));
    }

    public function testAFullRebuildNeedsATargetForEveryStoreViewAndNothingElse(): void
    {
        $this->indexManager->expects($this->never())->method('bulk');
        $this->pages->expects($this->never())->method('read');
        foreach ([
            [[$this->store(2), $this->store(3)], [2 => self::ALIAS . '2_v8']],
            [[$this->store(2)], [2 => self::ALIAS . '2_v8', 3 => self::ALIAS . '3_v2']],
        ] as [$stores, $targets]) {
            try {
                $this->indexer()->reindex([1], $stores, $targets);
                $this->fail('LogicException expected');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('rebuild target', $exception->getMessage());
            }
        }
    }

    public function testAFailedStoreViewDoesNotStopTheOthersAndTheCallFailsAtTheEnd(): void
    {
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->indexManager->method('ensureAlias')->willReturnCallback(
            static fn (StoreContext $store): string => self::ALIAS . $store->getStoreId()
        );
        $this->indexManager->method('bulk')->willReturnCallback(function (string $target, array $operations, bool $incremental): void {
            if ($target === self::ALIAS . '2') {
                throw new IndexingException('1 of 2 bulk operation(s) failed on ' . $target . '; first: index page_1 (400 mapper_parsing_exception)');
            }
            $this->record($target, $operations, $incremental);
        });
        try {
            $this->indexer(1)->reindex([1, 2], [$this->store(2), $this->store(3)]);
            $this->fail('IndexingException expected');
        } catch (IndexingException $exception) {
            $this->assertSame(
                'CMS page indexing failed in 1 of 2 store view(s); store view 2: 1 of 2 bulk operation(s) failed on '
                . self::ALIAS . '2; first: index page_1 (400 mapper_parsing_exception)',
                $exception->getMessage()
            );
            $this->assertNull($exception->getPrevious());
        }
        // store view 3 got both batches; the failed store view 2 was not written again after its first failure
        $this->assertSame([
            [self::ALIAS . '3', ['index page_1'], true],
            [self::ALIAS . '3', ['index page_2'], true],
        ], $this->bulks);
    }

    public function testAStoreViewWhoseAliasCannotBeCreatedIsSkippedAndReported(): void
    {
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->indexManager->method('ensureAlias')->willReturnCallback(static function (StoreContext $store): string {
            if ($store->getStoreId() === 3) {
                throw new IndexingException('OpenSearch request failed (create x): timeout');
            }
            return self::ALIAS . $store->getStoreId();
        });
        $this->indexManager->method('bulk')->willReturnCallback(function (string $target, array $operations, bool $incremental): void {
            $this->record($target, $operations, $incremental);
        });
        try {
            $this->indexer()->reindex([1], [$this->store(2), $this->store(3), $this->store(4)]);
            $this->fail('IndexingException expected');
        } catch (IndexingException $exception) {
            $this->assertStringStartsWith('CMS page indexing failed in 1 of 3 store view(s); store view 3: OpenSearch request failed', $exception->getMessage());
        }
        // page 1 belongs to store views 2 and 3: store view 4 still gets its (delete) write
        $this->assertSame([[self::ALIAS . '2', ['index page_1'], true], [self::ALIAS . '4', ['delete page_1'], true]], $this->bulks);
    }

    public function testAProgrammingErrorIsNotTreatedAsAStoreViewFailure(): void
    {
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->indexManager->method('ensureAlias')->willReturn(self::ALIAS . '2');
        $this->indexManager->expects($this->once())->method('bulk')->willThrowException(new \InvalidArgumentException('bad target'));
        $this->expectException(\InvalidArgumentException::class);
        $this->indexer()->reindex([1], [$this->store(2), $this->store(3)]);
    }

    public function testAReadFailurePropagatesBeforeAnyWrite(): void
    {
        $this->pages = $this->createMock(PageSnapshotReader::class);
        $this->pages->method('read')->willThrowException(new \RuntimeException('database gone'));
        $this->indexManager->expects($this->never())->method('bulk');
        $this->expectExceptionMessage('database gone');
        $this->indexer()->reindex([1], [$this->store(2)]);
    }

    public function testVisiblePagesGetTheBlockTextOfEachStoreView(): void
    {
        $this->blockText->expects($this->exactly(2))->method('forHosts')
            ->willReturnCallback(static fn (string $type, array $ids, int $storeId): array => $type === 'page' && $ids === [1, 3]
                ? [1 => new HostEnrichment('Block of store ' . $storeId, [7]), 3 => new HostEnrichment('', [])]
                : []);
        $documents = [];
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->indexManager->method('ensureAlias')->willReturnCallback(static fn (StoreContext $store): string => 'alias_' . $store->getStoreId());
        $this->indexManager->method('bulk')->willReturnCallback(function (string $target, array $operations) use (&$documents): void {
            foreach ($operations as $operation) {
                $documents[$target][$operation['id']] = $operation['action'] === 'index'
                    ? [$operation['document']['text'], $operation['document']['segment_ids']]
                    : 'delete';
            }
        });
        $this->indexer()->reindex([1, 3, 4], [$this->store(2), $this->store(3)]);
        $this->assertSame([
            'alias_2' => ['page_1' => ['x Block of store 2', [7]], 'page_3' => ['x', []], 'page_4' => 'delete'],
            'alias_3' => ['page_1' => ['x Block of store 3', [7]], 'page_3' => ['x', []], 'page_4' => 'delete'],
        ], $documents);
        $this->assertSame([1 => 1, 3 => 1], $this->prepared, 'the page content is still extracted once per page');
    }

    public function testBlockTextIsReadPerStoreViewForThePagesVisibleThereAndPerBatch(): void
    {
        $this->storesOf = [3 => [3]]; // page 3 is visible in store view 3 only
        $calls = [];
        $this->blockText->method('forHosts')->willReturnCallback(function (string $type, array $ids, int $storeId) use (&$calls): array {
            $calls[] = [$type, $ids, $storeId];
            return [];
        });
        $this->indexer(2)->reindex([1, 3, 4, 5], [$this->store(2), $this->store(3)]);
        $this->assertSame([
            ['page', [1], 2],
            ['page', [1, 3], 3],
            ['page', [5], 2],
            ['page', [5], 3],
        ], $calls);
    }

    public function testNoBlockTextIsReadWhenNoPageIsVisible(): void
    {
        $this->blockText->expects($this->never())->method('forHosts');
        $this->indexer()->reindex([4, 99], [$this->store(2)]);
    }

    public function testAFullRebuildAlsoCarriesTheBlockText(): void
    {
        $this->blockText->method('forHosts')->willReturn([1 => new HostEnrichment('Rebuilt block', [5])]);
        $texts = [];
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->indexManager->method('bulk')->willReturnCallback(function (string $target, array $operations) use (&$texts): void {
            foreach ($operations as $operation) {
                $texts[$operation['id']] = [$operation['document']['text'], $operation['document']['segment_ids']];
            }
        });
        $this->indexer()->reindex([1], [$this->store(2)], [2 => self::ALIAS . '2_v8']);
        $this->assertSame(['page_1' => ['x Rebuilt block', [5]]], $texts);
    }

    public function testAStoreViewWhoseAliasFailedReadsNoBlockText(): void
    {
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->indexManager->method('ensureAlias')->willReturnCallback(static function (StoreContext $store): string {
            if ($store->getStoreId() === 3) {
                throw new IndexingException('alias failed');
            }
            return 'alias_' . $store->getStoreId();
        });
        $stores = [];
        $this->blockText->method('forHosts')->willReturnCallback(function (string $type, array $ids, int $storeId) use (&$stores): array {
            $stores[] = $storeId;
            return [];
        });
        try {
            $this->indexer()->reindex([1], [$this->store(2), $this->store(3)]);
            $this->fail('an IndexingException was expected');
        } catch (IndexingException) {
        }
        $this->assertSame([2], $stores);
    }

    public function testNothingToDoMeansNoReadAndNoWrite(): void
    {
        $this->pages->expects($this->never())->method('read');
        $this->indexManager->expects($this->never())->method('bulk');
        $this->indexManager->expects($this->never())->method('ensureAlias');
        $this->assertSame(['upserted' => 0, 'deleted' => 0], $this->indexer()->reindex([0, -1], [$this->store(2)]));
        $this->assertSame(['upserted' => 0, 'deleted' => 0], $this->indexer()->reindex([1], []));
    }
}
