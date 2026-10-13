<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReader;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReferenceResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockSource;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostFacts;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSource;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetInstance;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetPageGroup;
use Thedevkitchen\CmsSearch\Model\Reference\DirectiveParser;
use Thedevkitchen\CmsSearch\Model\Reference\HostSourceReader;
use Thedevkitchen\CmsSearch\Model\Reference\ReferenceStore;
use Thedevkitchen\CmsSearch\Model\Reference\StoreThemeResolver;
use Thedevkitchen\CmsSearch\Model\Reference\WidgetInstanceReader;
use Thedevkitchen\CmsSearch\Model\Reference\WidgetInstanceResolver;
use Thedevkitchen\CmsSearch\Test\Unit\Support\CountingTextExtractor;
use Thedevkitchen\CmsSearch\Test\Unit\Support\RecordingLogger;

class BlockReferenceResolverTest extends TestCase
{
    private HostSourceReader&MockObject $hosts;
    private WidgetInstanceReader&MockObject $widgets;
    private StoreContextProvider&MockObject $stores;
    private BlockReader&MockObject $blocks;
    private ReferenceStore&MockObject $store;
    /** @var list<array{0: string, 1: list<int>, 2: int, 3: list<array<string, mixed>>}> */
    private array $replaced = [];
    /** @var list<list<string>> keys of the refs of each BlockReader::read() call */
    private array $reads = [];

    protected function setUp(): void
    {
        $this->hosts = $this->createMock(HostSourceReader::class);
        $this->widgets = $this->createMock(WidgetInstanceReader::class);
        $this->stores = $this->createMock(StoreContextProvider::class);
        $this->stores->method('get')->willReturnCallback(
            static fn (int $id): ?StoreContext => $id === 2 ? new StoreContext(2, 2, 's2', 'pt_BR', 'https://s/', 'https://s/media/', 'home') : null
        );
        $this->blocks = $this->createMock(BlockReader::class);
        $this->store = $this->createMock(ReferenceStore::class);
        $this->store->method('replace')->willReturnCallback(function (string $type, array $ids, int $storeId, array $rows): void {
            $this->replaced[] = [$type, $ids, $storeId, $rows];
        });
    }

    private ?CountingTextExtractor $extractor = null;
    private ?RecordingLogger $logger = null;

    private function resolver(): BlockReferenceResolver
    {
        $this->extractor = new CountingTextExtractor();
        $this->logger = new RecordingLogger();
        $parser = new DirectiveParser($this->extractor);
        $theme = $this->createMock(StoreThemeResolver::class);
        $theme->method('themeId')->willReturn(5);
        return new BlockReferenceResolver(
            $this->hosts,
            $parser,
            $this->widgets,
            new WidgetInstanceResolver($parser),
            $theme,
            $this->stores,
            $this->blocks,
            $this->store,
            $this->logger
        );
    }

    private static function page(int $id, string $content): HostSource
    {
        return new HostSource(new HostFacts('page', $id, 'p' . $id), [$content]);
    }

    private static function cms(int $id, string $identifier, string $content): BlockSource
    {
        return new BlockSource('block', $id, $identifier, $content);
    }

    /** @return list<string> "host kind id identifier source source_id depth segments" */
    private function rows(): array
    {
        $this->assertCount(1, $this->replaced);
        return array_map(
            static fn (array $r): string => sprintf(
                '%d %s %d %s %s %d %d %s',
                $r['host_id'],
                $r['block_kind'],
                $r['block_id'],
                $r['block_identifier'] === '' ? '-' : $r['block_identifier'],
                $r['source'],
                $r['source_id'],
                $r['depth'],
                $r['segment_ids'] ?? '-'
            ),
            $this->replaced[0][3]
        );
    }

    /** BlockReader stub: each ref key maps to a source (absent keys show nothing); counts the calls. */
    private function blocksFrom(array $sources, int $expectedCalls): void
    {
        $this->blocks->expects($this->exactly($expectedCalls))->method('read')->willReturnCallback(
            function (array $refs, int $storeId) use ($sources): array {
                $this->assertSame(2, $storeId);
                $this->reads[] = array_map(static fn (BlockRef $ref): string => $ref->key(), $refs);
                $out = [];
                foreach ($refs as $ref) {
                    if (isset($sources[$ref->key()])) {
                        $out[$ref->key()] = $sources[$ref->key()];
                    }
                }
                return $out;
            }
        );
    }

    public function testDirectReferencesOfPageContent(): void
    {
        $this->hosts->method('read')->with('page', [10], 2)->willReturn([10 => self::page(
            10,
            '{{block id="footer"}}{{widget type="Magento\Cms\Block\Widget\Block" block_id="4"}}'
            . '{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids="3"}}{{block id="missing"}}'
        )]);
        $this->blocksFrom([
            'block@footer' => self::cms(7, 'footer', '<p>f</p>'),
            'block#4' => self::cms(4, 'four', '<p>4</p>'),
            'dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '<p>d</p>'),
        ], 1);
        $this->assertSame(4, $this->resolver()->refreshForHosts('page', [10, 10], 2));
        $this->assertSame(['page', [10], 2], array_slice($this->replaced[0], 0, 3));
        $this->assertSame([
            '10 block 7 footer content 0 0 -',
            '10 block 4 - content 0 0 -',
            '10 dynamic_block 3 - content 0 0 -',
            '10 block 0 missing content 0 0 -',
        ], $this->rows());
    }

    public function testNestedBlocksAreRecordedLevelByLevel(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{block id="a"}}')]);
        $this->blocksFrom([
            'block@a' => self::cms(1, 'a', '{{block id="b"}}'),
            'block@b' => self::cms(2, 'b', '{{widget type="Magento\Cms\Block\Widget\Block" block_id="3"}}'),
            'block#3' => self::cms(3, 'c', '<p>c</p>'),
        ], 3);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $this->assertSame(['10 block 1 a content 0 0 -', '10 block 2 b content 0 1 -', '10 block 3 - content 0 2 -'], $this->rows());
    }

    public function testACycleStopsWhenABlockComesBack(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{block id="a"}}')]);
        $this->blocksFrom([
            'block@a' => self::cms(1, 'a', '{{block id="b"}}'),
            'block@b' => self::cms(2, 'b', '{{block id="a"}}'),
        ], 2);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $this->assertSame(['10 block 1 a content 0 0 -', '10 block 2 b content 0 1 -'], $this->rows());
    }

    public function testASelfReferencingBlockIsReadOnce(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{block id="5"}}')]);
        $this->blocksFrom(['block#5' => self::cms(5, 'self', '<p>x</p>{{block id="5"}}')], 1);
        $this->assertSame(1, $this->resolver()->refreshForHosts('page', [10], 2));
        $this->assertSame(['10 block 5 - content 0 0 -'], $this->rows());
    }

    public function testABlockReachedDirectlyAndNestedIsRecordedOnceAtTheShallowestDepth(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{block id="a"}}{{block id="b"}}')]);
        $this->blocksFrom([
            'block@a' => self::cms(1, 'a', '{{block id="b"}}'),
            'block@b' => self::cms(2, 'b', '<p>b</p>'),
        ], 1);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $this->assertSame(['10 block 1 a content 0 0 -', '10 block 2 b content 0 0 -'], $this->rows());
    }

    public function testNestingStopsAtTheDepthLimit(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{block id="1"}}')]);
        $chain = [];
        for ($i = 1; $i <= 8; $i++) {
            $chain['block#' . $i] = self::cms($i, 'b' . $i, '<p>' . $i . '</p>{{block id="' . ($i + 1) . '"}}');
        }
        $this->blocksFrom($chain, BlockReferenceResolver::MAX_DEPTH + 1);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $this->assertCount(BlockReferenceResolver::MAX_DEPTH + 1, $this->rows());
        $this->assertSame('10 block 6 - content 0 5 -', $this->rows()[5]);
    }

    public function testAHiddenBlockKeepsItsStructuralRowButIsNotFollowed(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{block id="9"}}{{block id="gone"}}')]);
        $this->blocksFrom([], 1);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $this->assertSame(['10 block 9 - content 0 0 -', '10 block 0 gone content 0 0 -'], $this->rows());
    }

    public function testASegmentRestrictedDynamicBlockStoresItsSegmentsAndIsNotFollowed(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids="3"}}')]);
        $this->blocksFrom(['dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '{{block id="x"}}', [4, 7])], 1);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $this->assertSame(['10 dynamic_block 3 - content 0 0 4,7'], $this->rows());
    }

    public function testABlockSharedByManyHostsIsParsedOncePerCall(): void
    {
        $pages = [];
        foreach (range(10, 209) as $id) {
            $pages[$id] = self::page($id, '{{block id="1"}}');
        }
        $this->hosts->method('read')->willReturn($pages);
        $this->blocksFrom([
            'block#1' => self::cms(1, 'a', '<p>a</p>{{block id="2"}}'),
            'block#2' => self::cms(2, 'b', '<p>b</p>'),
        ], 2);
        $this->assertSame(400, $this->resolver()->refreshForHosts('page', range(10, 209), 2));
        $this->assertSame(1, $this->extractor->calls['<p>a</p>{{block id="2"}}']);
        $this->assertSame(1, $this->extractor->calls['<p>b</p>']);
    }

    public function testABlockWhoseExtractionFailsKeepsItsRowIsNotFollowedAndIsLoggedByIdOnly(): void
    {
        $this->hosts->method('read')->willReturn([
            10 => self::page(10, '{{block id="1"}}{{block id="4"}}'),
            11 => self::page(11, '{{block id="1"}}'),
        ]);
        $this->blocksFrom([
            'block#1' => self::cms(1, 'a', '<p>SECRET ' . CountingTextExtractor::FAIL_MARKER . '</p>{{block id="3"}}'),
            'block#4' => self::cms(4, 'd', '<p>d</p>'),
        ], 1);
        $this->resolver()->refreshForHosts('page', [10, 11], 2);
        $this->assertSame(['10 block 1 - content 0 0 -', '10 block 4 - content 0 0 -', '11 block 1 - content 0 0 -'], $this->rows());
        $this->assertCount(1, $this->logger->records);
        $this->assertSame(['block_kind' => 'block', 'block_id' => 1], $this->logger->records[0][2]);
        $this->assertStringNotContainsString('SECRET', json_encode($this->logger->records));
    }

    public function testSpellingsTheCollationConsidersEqualGiveOneRow(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{block id="cafe"}}{{block id="Café"}}{{block id="Straße"}}{{block id="strase"}}')]);
        $this->blocksFrom(['block@cafe' => self::cms(8, 'Café', '<p>c</p>')], 1);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $this->assertSame(['10 block 8 cafe content 0 0 -', '10 block 0 Straße content 0 0 -'], $this->rows());
    }

    public function testARuleRestrictedDynamicBlockKeepsItsRowAndIsNotFollowed(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids="3"}}')]);
        $this->blocksFrom(['dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '{{block id="x"}}', [], true)], 1);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $this->assertSame(['10 dynamic_block 3 - content 0 0 -'], $this->rows());
    }

    public function testAnUnrestrictedDynamicBlockIsFollowed(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids="3"}}')]);
        $this->blocksFrom([
            'dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '{{block id="x"}}'),
            'block@x' => self::cms(8, 'x', '<p>x</p>'),
        ], 2);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $this->assertSame(['10 dynamic_block 3 - content 0 0 -', '10 block 8 x content 0 1 -'], $this->rows());
    }

    public function testSegmentIdsLongerThanTheColumnAreCutAtAnIdBoundary(): void
    {
        $this->hosts->method('read')->willReturn([10 => self::page(10, '{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids="3"}}')]);
        $this->blocksFrom(['dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '<p>d</p>', range(100, 199))], 1);
        $this->resolver()->refreshForHosts('page', [10], 2);
        $segments = $this->replaced[0][3][0]['segment_ids'];
        $this->assertSame(implode(',', range(100, 163)), $segments);
        $this->assertSame(BlockReferenceResolver::MAX_SEGMENT_IDS_LENGTH, strlen($segments));
    }

    public function testWidgetInstancesOfTheStoreThemeAndViewPublishOnMatchingHosts(): void
    {
        $anchor = [new WidgetPageGroup('anchor_categories', 'catalog_category_view_type_layered', 'all', [])];
        $this->widgets->method('all')->willReturn([
            new WidgetInstance(18, DirectiveParser::CMS_BLOCK_WIDGET, 5, [0], ['block_id' => '12'], $anchor),
            new WidgetInstance(19, DirectiveParser::CMS_BLOCK_WIDGET, 3, [0], ['block_id' => '13'], $anchor),
            new WidgetInstance(20, DirectiveParser::CMS_BLOCK_WIDGET, 5, [3], ['block_id' => '14'], $anchor),
            new WidgetInstance(
                21,
                DirectiveParser::CMS_BLOCK_WIDGET,
                5,
                [0],
                ['block_id' => '15'],
                [new WidgetPageGroup('pages', 'checkout_cart_index', 'all', [])]
            ),
        ]);
        $this->hosts->method('read')->willReturn([
            37 => new HostSource(new HostFacts('category', 37, '', '', true), []),
            38 => new HostSource(new HostFacts('category', 38, '', '', false), []),
        ]);
        $this->blocksFrom(['block#12' => self::cms(12, 'w', '<p>w</p>')], 1);
        $this->resolver()->refreshForHosts('category', [37, 38], 2);
        $this->assertSame(['category', [37, 38], 2], array_slice($this->replaced[0], 0, 3));
        $this->assertSame(['37 block 12 - widget 18 0 -'], $this->rows());
    }

    /** USER DECISION (Plan 6 decision 7): site chrome placed on All Pages (footer links) reaches NO host. */
    public function testAnAllPagesWidgetInstancePublishesOnNoHost(): void
    {
        $this->widgets->method('all')->willReturn([
            new WidgetInstance(
                2,
                DirectiveParser::CMS_BLOCK_WIDGET,
                5,
                [0],
                ['block_id' => '2'],
                [new WidgetPageGroup('all_pages', 'default', 'all', [])]
            ),
        ]);
        $this->hosts->method('read')->willReturnCallback(static fn (string $type, array $ids): array => match ($type) {
            'page' => [10 => self::page(10, '<p>no directive</p>')],
            'category' => [37 => new HostSource(new HostFacts('category', 37, '', '', true), [])],
            'product' => [7 => new HostSource(new HostFacts('product', 7, '', 'simple'), [])],
        });
        $this->blocks->expects($this->never())->method('read');
        foreach (['page' => 10, 'category' => 37, 'product' => 7] as $type => $id) {
            $this->assertSame(0, $this->resolver()->refreshForHosts($type, [$id], 2));
        }
        $this->assertSame([['page', [10], 2, []], ['category', [37], 2, []], ['product', [7], 2, []]], $this->replaced);
    }

    public function testACategoryLandingBlockAndAWidgetOfTheSameBlockAreBothRecorded(): void
    {
        $anchor = [new WidgetPageGroup('anchor_categories', 'catalog_category_view_type_layered', 'all', [])];
        $this->widgets->method('all')->willReturn([
            new WidgetInstance(18, DirectiveParser::CMS_BLOCK_WIDGET, 5, [2], ['block_id' => '12'], $anchor),
        ]);
        $this->hosts->method('read')->willReturn([
            37 => new HostSource(new HostFacts('category', 37, '', '', true), ['<p>desc</p>'], [new BlockRef('block', 12)]),
        ]);
        $this->blocksFrom(['block#12' => self::cms(12, 'w', '<p>w</p>')], 1);
        $this->assertSame(2, $this->resolver()->refreshForHosts('category', [37], 2));
        $this->assertSame(['37 block 12 - content 0 0 -', '37 block 12 - widget 18 0 -'], $this->rows());
    }

    public function testEveryHostOfTheBatchSharesOneReadPerLevel(): void
    {
        $this->hosts->method('read')->willReturn([
            10 => self::page(10, '{{block id="a"}}'),
            11 => self::page(11, '{{block id="a"}}{{block id="7"}}'),
        ]);
        $this->blocksFrom([
            'block@a' => self::cms(1, 'a', '{{block id="9"}}'),
            'block#7' => self::cms(7, 's', '<p>s</p>'),
        ], 2);
        $this->assertSame(5, $this->resolver()->refreshForHosts('page', [10, 11], 2));
        $this->assertSame([['block@a', 'block@a', 'block#7'], ['block#9', 'block#9']], $this->reads);
        $this->assertSame([
            '10 block 1 a content 0 0 -',
            '11 block 1 a content 0 0 -',
            '11 block 7 - content 0 0 -',
            '10 block 9 - content 0 1 -',
            '11 block 9 - content 0 1 -',
        ], $this->rows());
    }

    public function testAMissingHostOnlyLosesItsRows(): void
    {
        $this->hosts->method('read')->willReturn([]);
        $this->blocks->expects($this->never())->method('read');
        $this->widgets->expects($this->never())->method('all');
        $this->assertSame(0, $this->resolver()->refreshForHosts('product', [7, 8], 2));
        $this->assertSame([['product', [7, 8], 2, []]], $this->replaced);
    }

    public function testADeletedStoreViewWritesNothing(): void
    {
        $this->hosts->expects($this->never())->method('read');
        $this->assertSame(0, $this->resolver()->refreshForHosts('page', [10], 9));
        $this->assertSame([], $this->replaced);
    }

    public function testInvalidHostIdsAreDroppedAndAnEmptyBatchTouchesNothing(): void
    {
        $this->stores = $this->createMock(StoreContextProvider::class);
        $this->stores->expects($this->never())->method('get');
        $this->hosts->expects($this->never())->method('read');
        $this->assertSame(0, $this->resolver()->refreshForHosts('page', [0, -3, 'x'], 2));
        $this->assertSame([], $this->replaced);
    }

    public function testRefreshForHostDelegatesToTheBatch(): void
    {
        $this->hosts->expects($this->once())->method('read')->with('category', [37], 2)->willReturn([]);
        $this->resolver()->refreshForHost('category', 37, 2);
        $this->assertSame([['category', [37], 2, []]], $this->replaced);
    }

    public function testReverseLookupsUseTheCurrentIdentifierForCmsBlocksOnly(): void
    {
        $pages = [[['store_id' => 2, 'host_type' => 'page', 'host_id' => 10]]];
        $this->blocks->expects($this->once())->method('identifierOf')->with(7)->willReturn('footer');
        $this->store->expects($this->exactly(2))->method('hostsForBlock')
            ->willReturnCallback(static fn (string $kind, int $id, string $identifier): array => $kind === 'block' && $identifier === 'footer' ? $pages : []);
        $this->store->expects($this->once())->method('hostsForSource')->with('widget', 18)->willReturn($pages);
        $resolver = $this->resolver();
        $this->assertSame($pages, iterator_to_array($resolver->hostsForBlock('block', 7), false));
        $this->assertSame([], iterator_to_array($resolver->hostsForBlock('dynamic_block', 3), false));
        $this->assertSame($pages, iterator_to_array($resolver->hostsForWidgetInstance(18), false));
    }

    public function testADeletedBlockIsLookedUpByItsIdOnly(): void
    {
        $this->blocks->method('identifierOf')->willReturn(null);
        $this->store->expects($this->once())->method('hostsForBlock')->with('block', 7, '')->willReturn([]);
        $this->assertSame([], iterator_to_array($this->resolver()->hostsForBlock('block', 7), false));
    }

    public function testAnUnknownHostTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver()->refreshForHosts('cart', [1], 2);
    }
}
