<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\BlockReferenceResolverInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Model\Indexer\AffectedPages;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Reference\AffectedHosts;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSelector;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetInstance;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetPageGroup;
use Thedevkitchen\CmsSearch\Model\Reference\DirectiveParser;
use Thedevkitchen\CmsSearch\Model\Reference\HostCatalog;
use Thedevkitchen\CmsSearch\Model\Reference\StoreThemeResolver;
use Thedevkitchen\CmsSearch\Model\Reference\WidgetInstanceReader;
use Thedevkitchen\CmsSearch\Model\Reference\WidgetInstanceResolver;

class AffectedHostsTest extends TestCase
{
    private AffectedPages&MockObject $pages;
    private ShadowedPageReader&MockObject $shadows;
    private BlockReferenceResolverInterface&MockObject $graph;
    private WidgetInstanceReader&MockObject $widgets;
    private HostCatalog&MockObject $catalog;

    protected function setUp(): void
    {
        $this->pages = $this->createMock(AffectedPages::class);
        $this->shadows = $this->createMock(ShadowedPageReader::class);
        $this->graph = $this->createMock(BlockReferenceResolverInterface::class);
        $this->widgets = $this->createMock(WidgetInstanceReader::class);
        $this->catalog = $this->createMock(HostCatalog::class);
    }

    private function hosts(): AffectedHosts
    {
        $stores = $this->createMock(StoreContextProvider::class);
        $stores->method('all')->willReturn([
            new StoreContext(1, 1, 'default', 'en_US', 'https://a/', 'https://a/media/', 'home'),
            new StoreContext(2, 2, 's2', 'pt_BR', 'https://b/', 'https://b/media/', 'home'),
        ]);
        $themes = $this->createMock(StoreThemeResolver::class);
        $themes->method('themeId')->willReturnCallback(static fn (int $storeId): int => $storeId === 1 ? 3 : 5);
        return new AffectedHosts(
            $this->pages,
            $this->shadows,
            $this->graph,
            $this->widgets,
            new WidgetInstanceResolver(new DirectiveParser(new TextExtractor())),
            $themes,
            $stores,
            $this->catalog
        );
    }

    private static function message(string $type, int $id, string $action = 'save'): ReindexMessage
    {
        return new ReindexMessage($type, $id, $action, 1760000000);
    }

    /**
     * @param iterable<list<array{host_type: string, host_id: int, store_id: ?int}>> $chunks
     * @return list<list<array{host_type: string, host_id: int, store_id: ?int}>>
     */
    private static function chunks(iterable $chunks): array
    {
        $all = [];
        foreach ($chunks as $chunk) {
            $all[] = $chunk;
        }
        return $all;
    }

    /** @return list<array{host_type: string, host_id: int, store_id: ?int}> */
    private static function flat(iterable $chunks): array
    {
        return array_merge([], ...self::chunks($chunks));
    }

    /** @return array{host_type: string, host_id: int, store_id: ?int} */
    private static function host(string $type, int $id, ?int $storeId = null): array
    {
        return ['host_type' => $type, 'host_id' => $id, 'store_id' => $storeId];
    }

    public function testContentTypesAndTermsGoThroughThePageResolverChunkByChunk(): void
    {
        $this->pages->expects($this->exactly(2))->method('resolve')->willReturnCallback(static fn (): \Generator => (static function (): \Generator {
            yield [1, 3];
            yield [8];
        })());
        $this->shadows->expects($this->never())->method('siblingsOf'); // a type or term never changes which page wins a URL key
        foreach (['content_type', 'classification_term'] as $type) {
            $this->assertSame(
                [[self::host('page', 1), self::host('page', 3)], [self::host('page', 8)]],
                self::chunks($this->hosts()->resolve(self::message($type, 3)))
            );
        }
    }

    public function testChunksAreStreamedNotCollectedFirst(): void
    {
        $this->pages->method('resolve')->willReturn((static function (): \Generator {
            yield [1, 2];
            throw new \RuntimeException('database gone while reading the next chunk');
        })());
        $seen = [];
        try {
            foreach ($this->hosts()->resolve(self::message('content_type', 5)) as $chunk) {
                $seen[] = $chunk;
            }
            $this->fail('the read failure must propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('database gone while reading the next chunk', $exception->getMessage());
        }
        $this->assertSame([[self::host('page', 1), self::host('page', 2)]], $seen);
    }

    public function testAPageMessageAlsoReachesTheStoreZeroPageItShadows(): void
    {
        $this->pages->method('resolve')->willReturn((static function (): \Generator {
            yield [11];
        })());
        $this->shadows->expects($this->once())->method('siblingsOf')->with([11])->willReturn([11, 2, 2]);
        $this->assertSame(
            [[self::host('page', 2), self::host('page', 11)]],
            self::chunks($this->hosts()->resolve(self::message('page', 11)))
        );
    }

    public function testNothingIsReadBeforeTheChunksAreIterated(): void
    {
        // the processor iterates only when a store view is concerned: no graph, page or shadow query before that
        $this->pages->method('resolve')->willReturn((static function (): \Generator {
            throw new \RuntimeException('must not be iterated');
            yield [1]; // @phpstan-ignore-line unreachable, makes this a generator
        })());
        $this->shadows->expects($this->never())->method('siblingsOf');
        $this->graph->expects($this->never())->method('hostsForBlock');
        $this->graph->expects($this->never())->method('hostsForWidgetInstance');
        $this->widgets->expects($this->never())->method('get');
        foreach ([['page', 1], ['block', 12], ['dynamic_block', 3], ['widget_instance', 18]] as [$type, $id]) {
            $this->hosts()->resolve(self::message($type, $id));
        }
        $this->addToAssertionCount(1);
    }

    public function testProductsAndCategoriesAreTheirOwnHost(): void
    {
        $this->pages->expects($this->never())->method('resolve');
        $this->graph->expects($this->never())->method($this->anything());
        $this->assertSame([[self::host('product', 7)]], self::chunks($this->hosts()->resolve(self::message('product', 7, 'delete'))));
        $this->assertSame([[self::host('category', 37)]], self::chunks($this->hosts()->resolve(self::message('category', 37, 'staging'))));
    }

    public function testBlocksAndDynamicBlocksAreExpandedThroughTheGraphOnce(): void
    {
        $rows = [['store_id' => 2, 'host_type' => 'page', 'host_id' => 5], ['store_id' => 2, 'host_type' => 'page', 'host_id' => 5],
            ['store_id' => 1, 'host_type' => 'product', 'host_id' => 7]];
        $this->graph->expects($this->exactly(2))->method('hostsForBlock')
            ->willReturnCallback(static fn (string $kind, int $id): array => $kind === 'block' && $id === 12 ? $rows : []);
        $this->assertSame(
            [[self::host('page', 5, 2), self::host('product', 7, 1)]],
            self::chunks($this->hosts()->resolve(self::message('block', 12)))
        );
        $this->assertSame([], self::flat($this->hosts()->resolve(self::message('dynamic_block', 3, 'delete'))));
    }

    public function testADynamicBlockIsLookedUpAsADynamicBlock(): void
    {
        $this->graph->expects($this->once())->method('hostsForBlock')->with('dynamic_block', 3)
            ->willReturn([['store_id' => 1, 'host_type' => 'category', 'host_id' => 40]]);
        $this->assertSame([self::host('category', 40, 1)], self::flat($this->hosts()->resolve(self::message('dynamic_block', 3))));
    }

    public function testAWidgetInstanceReachesItsOldHostsAndItsCurrentPlacementInMatchingStoreViews(): void
    {
        $this->graph->method('hostsForWidgetInstance')->with(18)->willReturn([['store_id' => 1, 'host_type' => 'category', 'host_id' => 37]]);
        $this->widgets->method('get')->with(18)->willReturn(new WidgetInstance(18, DirectiveParser::CMS_BLOCK_WIDGET, 3, [0], ['block_id' => '12'],
            [new WidgetPageGroup('anchor_categories', 'catalog_category_view_type_layered', 'specific', [38, 37])]));
        $this->catalog->expects($this->once())->method('ids')
            ->with($this->callback(static fn (HostSelector $s): bool => $s->key() === 'category:ids:38,37'), $this->callback(static fn (StoreContext $s): bool => $s->getStoreId() === 1))
            ->willReturn([37, 38]);
        $this->assertSame(
            [self::host('category', 37, 1), self::host('category', 38, 1)],
            self::flat($this->hosts()->resolve(self::message('widget_instance', 18)))
        );
    }

    public function testAMovedWidgetInstanceCleansItsOldHostsInOtherStoreViews(): void
    {
        // the instance moved from the store-2 theme to the store-1 theme: its old store-2 host must be reindexed too
        $this->graph->method('hostsForWidgetInstance')->willReturn([['store_id' => 2, 'host_type' => 'page', 'host_id' => 9]]);
        $this->widgets->method('get')->willReturn(new WidgetInstance(18, DirectiveParser::CMS_BLOCK_WIDGET, 3, [0], ['block_id' => '12'],
            [new WidgetPageGroup('anchor_categories', 'catalog_category_view_type_layered', 'specific', [37])]));
        $this->catalog->method('ids')->willReturn([37]);
        $this->assertSame(
            [self::host('page', 9, 2), self::host('category', 37, 1)],
            self::flat($this->hosts()->resolve(self::message('widget_instance', 18)))
        );
    }

    public function testADeletedWidgetInstanceOnlyReachesItsOldHosts(): void
    {
        $this->graph->method('hostsForWidgetInstance')->willReturn([['store_id' => 1, 'host_type' => 'page', 'host_id' => 4]]);
        $this->widgets->method('get')->willReturn(null);
        $this->catalog->expects($this->never())->method('ids');
        $this->assertSame([self::host('page', 4, 1)], self::flat($this->hosts()->resolve(self::message('widget_instance', 18, 'delete'))));
    }

    public function testAWidgetInstanceWithoutABlockHasNoNewPlacement(): void
    {
        $this->graph->method('hostsForWidgetInstance')->willReturn([]);
        $this->widgets->method('get')->willReturn(new WidgetInstance(18, 'Magento\\Catalog\\Block\\Product\\Widget\\NewWidget', 3, [0], [],
            [new WidgetPageGroup('anchor_categories', 'catalog_category_view_type_layered', 'specific', [37])]));
        $this->catalog->expects($this->never())->method('ids');
        $this->assertSame([], self::flat($this->hosts()->resolve(self::message('widget_instance', 18))));
    }

    public function testAnUnsupportedEntityTypeIsPermanentAndThrownBeforeAnyIteration(): void
    {
        $message = $this->createMock(ReindexMessageInterface::class);
        $message->method('getEntityType')->willReturn('blog');
        $message->method('getEntityId')->willReturn(1);
        $this->expectException(InvalidReindexMessageException::class);
        $this->hosts()->resolve($message); // not iterated: the exception must not wait for the first chunk
    }

    public function testReadFailuresPropagateUnchanged(): void
    {
        $failure = new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        $this->graph->method('hostsForBlock')->willThrowException($failure);
        try {
            self::flat($this->hosts()->resolve(self::message('block', 12)));
            $this->fail('the failure must propagate');
        } catch (\Throwable $exception) {
            $this->assertSame($failure, $exception);
        }
    }
}
