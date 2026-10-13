<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\CategoryIndexer;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\HostReindexer;
use Thedevkitchen\CmsSearch\Model\Indexer\PageIndexer;
use Thedevkitchen\CmsSearch\Model\Indexer\ProductBlockTextWriter;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReferenceResolver;

class HostReindexerTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];
    private ?\Throwable $failure = null;

    private function reindexer(int $batchSize = 200): HostReindexer
    {
        $graph = $this->createMock(BlockReferenceResolver::class);
        $graph->method('refreshForHosts')->willReturnCallback(function (string $type, array $ids, int $storeId): int {
            $this->calls[] = sprintf('graph %s %s @%d', $type, implode(',', $ids), $storeId);
            return 0;
        });
        $pages = $this->createMock(PageIndexer::class);
        $pages->method('reindex')->willReturnCallback(function (array $ids, array $stores, array $targets = []): array {
            $this->assertSame([], $targets, 'the consumer always writes incrementally');
            $this->calls[] = sprintf(
                'pages %s @%s',
                implode(',', $ids),
                implode(',', array_map(static fn (StoreContext $store): int => $store->getStoreId(), $stores))
            );
            if ($this->failure !== null) {
                throw $this->failure;
            }
            return ['upserted' => 0, 'deleted' => 0];
        });
        $categories = $this->createMock(CategoryIndexer::class);
        $categories->method('reindex')->willReturnCallback(function (array $ids, array $stores, array $targets = []): array {
            $this->assertSame([], $targets, 'the consumer always writes incrementally');
            $this->assertCount(1, $stores);
            $this->calls[] = sprintf('categories %s @%d', implode(',', $ids), $stores[0]->getStoreId());
            return ['upserted' => 0, 'deleted' => 0];
        });
        $products = $this->createMock(ProductBlockTextWriter::class);
        $products->method('refresh')->willReturnCallback(function (array $ids, int $storeId): array {
            $this->calls[] = sprintf('products %s @%d', implode(',', $ids), $storeId);
            return ['written' => 0, 'deleted' => 0, 'unchanged' => 0];
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('beginTransaction')->willReturnCallback(function () use ($connection) {
            $this->calls[] = 'BEGIN';
            return $connection;
        });
        $connection->method('commit')->willReturnCallback(function () use ($connection) {
            $this->calls[] = 'COMMIT';
            return $connection;
        });
        $connection->method('rollBack')->willReturnCallback(function () use ($connection) {
            $this->calls[] = 'ROLLBACK';
            return $connection;
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        return new HostReindexer($graph, $pages, $categories, $products, $resource, $batchSize);
    }

    private static function store(int $id): StoreContext
    {
        return new StoreContext($id, 1, 's' . $id, 'en_US', 'https://s/', 'https://s/media/', 'home');
    }

    public function testEachStoreViewRefreshesTheGraphBeforeIndexingEveryHostType(): void
    {
        $counts = $this->reindexer()->reindex([
            ['host_type' => 'product', 'host_id' => 7, 'store_id' => null],
            ['host_type' => 'page', 'host_id' => 5, 'store_id' => 2],
            ['host_type' => 'category', 'host_id' => 37, 'store_id' => null],
            ['host_type' => 'page', 'host_id' => 4, 'store_id' => null],
            ['host_type' => 'page', 'host_id' => 9, 'store_id' => 6], // a store view this message does not cover
        ], [self::store(1), self::store(2)]);
        $this->assertSame([
            'BEGIN', 'graph page 4 @1', 'graph page 4 @2', 'pages 4 @1,2', 'COMMIT',
            'BEGIN', 'graph page 5 @2', 'pages 5 @2', 'COMMIT',
            'BEGIN', 'graph category 37 @1', 'categories 37 @1', 'COMMIT', 'BEGIN', 'graph product 7 @1', 'products 7 @1', 'COMMIT',
            'BEGIN', 'graph category 37 @2', 'categories 37 @2', 'COMMIT', 'BEGIN', 'graph product 7 @2', 'products 7 @2', 'COMMIT',
        ], $this->calls);
        $this->assertSame(['page' => 3, 'category' => 2, 'product' => 2], $counts);
    }

    public function testThePagesOfEveryStoreViewAreWrittenByOneIndexerCallPerBatch(): void
    {
        $stores = array_map(static fn (int $id): StoreContext => self::store($id), range(1, 7));
        $counts = $this->reindexer(2)->reindex([
            ['host_type' => 'page', 'host_id' => 3, 'store_id' => null],
            ['host_type' => 'page', 'host_id' => 1, 'store_id' => null],
            ['host_type' => 'page', 'host_id' => 2, 'store_id' => null],
        ], $stores);
        $graph = static fn (string $ids): array => array_map(static fn (int $store): string => "graph page $ids @$store", range(1, 7));
        // ONE PageIndexer call per batch for the 7 store views: the page content is extracted once, not 7 times
        $this->assertSame(array_merge(
            ['BEGIN'],
            $graph('1,2'),
            ['pages 1,2 @1,2,3,4,5,6,7', 'COMMIT'],
            ['BEGIN'],
            $graph('3'),
            ['pages 3 @1,2,3,4,5,6,7', 'COMMIT']
        ), $this->calls);
        $this->assertSame(['page' => 21, 'category' => 0, 'product' => 0], $counts);
    }

    public function testHostsAreProcessedInBatches(): void
    {
        $this->reindexer(2)->reindex(array_map(
            static fn (int $id): array => ['host_type' => 'product', 'host_id' => $id, 'store_id' => 1],
            [5, 1, 3, 3]
        ), [self::store(1)]);
        $this->assertSame([
            'BEGIN', 'graph product 1,3 @1', 'products 1,3 @1', 'COMMIT', 'BEGIN', 'graph product 5 @1', 'products 5 @1', 'COMMIT',
        ], $this->calls);
    }

    public function testNoHostOrNoStoreViewDoesNothing(): void
    {
        $this->assertSame(['page' => 0, 'category' => 0, 'product' => 0], $this->reindexer()->reindex([], [self::store(1)]));
        $this->assertSame(
            ['page' => 0, 'category' => 0, 'product' => 0],
            $this->reindexer()->reindex([['host_type' => 'page', 'host_id' => 5, 'store_id' => null]], [])
        );
        $this->assertSame([], $this->calls);
    }

    public function testAnUnknownHostTypeIsRefusedBeforeAnyWrite(): void
    {
        try {
            $this->reindexer()->reindex([
                ['host_type' => 'page', 'host_id' => 1, 'store_id' => null],
                ['host_type' => 'cart', 'host_id' => 1, 'store_id' => null],
            ], [self::store(1)]);
            $this->fail('an unknown host type must be refused');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Unknown host type "cart".', $exception->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    public function testAWriteFailurePropagatesSoTheHandlerRetries(): void
    {
        $this->failure = new \RuntimeException('No alive nodes');
        try {
            $this->reindexer()->reindex([['host_type' => 'page', 'host_id' => 1, 'store_id' => null]], [self::store(1), self::store(2)]);
            $this->fail('the failure must propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame($this->failure, $exception);
        }
        // graph refresh and write are one transaction: the failure rolls the new graph back (the retry sees the old hosts)
        $this->assertSame(['BEGIN', 'graph page 1 @1', 'graph page 1 @2', 'pages 1 @1,2', 'ROLLBACK'], $this->calls);
    }

    public function testAFailingGraphRefreshRollsBackAndNothingIsWritten(): void
    {
        $graph = $this->createMock(BlockReferenceResolver::class);
        $graph->method('refreshForHosts')->willThrowException(new \RuntimeException('deadlock'));
        $pages = $this->createMock(PageIndexer::class);
        $pages->expects($this->never())->method('reindex');
        $rolledBack = 0;
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('commit');
        $connection->method('rollBack')->willReturnCallback(function () use (&$rolledBack, $connection) {
            $rolledBack++;
            return $connection;
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $reindexer = new HostReindexer(
            $graph,
            $pages,
            $this->createMock(CategoryIndexer::class),
            $this->createMock(ProductBlockTextWriter::class),
            $resource
        );
        try {
            $reindexer->reindex([['host_type' => 'page', 'host_id' => 1, 'store_id' => 1]], [self::store(1)]);
            $this->fail('the failure must propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('deadlock', $exception->getMessage());
        }
        $this->assertSame(1, $rolledBack);
    }
}
