<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

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
            $this->assertCount(1, $stores);
            $this->calls[] = sprintf('pages %s @%d', implode(',', $ids), $stores[0]->getStoreId());
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
        return new HostReindexer($graph, $pages, $categories, $products, $batchSize);
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
            'graph page 4 @1', 'pages 4 @1', 'graph category 37 @1', 'categories 37 @1', 'graph product 7 @1', 'products 7 @1',
            'graph page 4,5 @2', 'pages 4,5 @2', 'graph category 37 @2', 'categories 37 @2', 'graph product 7 @2', 'products 7 @2',
        ], $this->calls);
        $this->assertSame(['page' => 3, 'category' => 2, 'product' => 2], $counts);
    }

    public function testHostsAreProcessedInBatches(): void
    {
        $this->reindexer(2)->reindex(array_map(
            static fn (int $id): array => ['host_type' => 'product', 'host_id' => $id, 'store_id' => 1],
            [5, 1, 3, 3]
        ), [self::store(1)]);
        $this->assertSame(['graph product 1,3 @1', 'products 1,3 @1', 'graph product 5 @1', 'products 5 @1'], $this->calls);
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
        $this->assertSame(['graph page 1 @1', 'pages 1 @1'], $this->calls);
    }
}
