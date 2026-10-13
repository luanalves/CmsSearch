<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostFacts;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSelector;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetInstance;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetPageGroup;
use Thedevkitchen\CmsSearch\Model\Reference\DirectiveParser;
use Thedevkitchen\CmsSearch\Model\Reference\StoreThemeResolver;
use Thedevkitchen\CmsSearch\Model\Reference\WidgetInstanceResolver;

class WidgetInstanceResolverTest extends TestCase
{
    private const CMS = 'Magento\\Cms\\Block\\Widget\\Block';
    private const BANNER = 'Magento\\Banner\\Block\\Widget\\Banner';

    private function resolver(): WidgetInstanceResolver
    {
        return new WidgetInstanceResolver(new DirectiveParser(new TextExtractor()));
    }

    private static function instance(string $type, array $parameters, array $groups = [], int $themeId = 3, array $storeIds = [0]): WidgetInstance
    {
        return new WidgetInstance(18, $type, $themeId, $storeIds, $parameters, $groups);
    }

    /**
     * @param list<HostSelector> $selectors
     * @return list<string>
     */
    private static function keys(array $selectors): array
    {
        return array_map(static fn (HostSelector $s): string => $s->key(), $selectors);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<int>, 4: list<string>}> */
    public static function pageGroups(): array
    {
        return [
            // USER DECISION: generic site chrome (footer, header, menu, links) placed on all pages is not indexed.
            'all pages (real instance 2) is ignored' => ['all_pages', 'default', 'all', [], []],
            'all pages with entities is still ignored' => ['all_pages', 'default', 'specific', [5], []],
            'specified page: default handle is ignored like all pages' => ['pages', 'default', 'all', [], []],
            'specified page: every CMS page' => ['pages', 'cms_page_view', 'all', [], ['page:all:']],
            'specified page: home page' => ['pages', 'cms_index_index', 'all', [], ['page:home:']],
            'specified page: one CMS page handle' => ['pages', 'cms_page_view_id_about_us', 'all', [], ['page:page_handle:about_us']],
            'specified page: every product page' => ['pages', 'catalog_product_view', 'all', [], ['product:all:']],
            'specified page: product type handle' => ['pages', 'catalog_product_view_type_bundle', 'all', [], ['product:product_type:bundle']],
            'specified page: one product handle' => ['pages', 'catalog_product_view_id_42', 'all', [], ['product:ids:42']],
            'product handle id 0 is not resolved' => ['pages', 'catalog_product_view_id_0', 'all', [], []],
            'product handle id above the bound is not resolved' => ['pages', 'catalog_product_view_id_9999999999', 'all', [], []],
            'product sku handle is not resolved' => ['pages', 'catalog_product_view_sku_24-MB01', 'all', [], []],
            'specified page: every category page' => ['pages', 'catalog_category_view', 'all', [], ['category:all:']],
            'specified page: anchor category handle' => ['pages', 'catalog_category_view_type_layered', 'all', [], ['category:anchor:']],
            'specified page: non-anchor category handle' => ['pages', 'catalog_category_view_type_default', 'all', [], ['category:non_anchor:']],
            'specified page: one category handle' => ['pages', 'catalog_category_view_id_37', 'all', [], ['category:ids:37']],
            'category handle id above the bound is not resolved' => ['pages', 'catalog_category_view_id_4294967296', 'all', [], []],
            'category display mode handle is not resolved' => ['pages', 'catalog_category_view_displaymode_page', 'all', [], []],
            'contact page (real instance 1) is no host' => ['pages', 'contact_index_index', 'all', [], []],
            'login page (real instance 17) is no host' => ['pages', 'customer_account_login', 'all', [], []],
            'cart is no host' => ['pages', 'checkout_cart_index', 'all', [], []],
            'CMS no-route page is no host' => ['pages', 'cms_index_noroute', 'all', [], []],
            'leaf-only anchor handle is not resolved' => ['pages', 'catalog_category_view_type_layered_without_children', 'all', [], []],
            'page layouts are not resolved' => ['page_layouts', '1column', 'all', [], []],
            'all anchor categories' => ['anchor_categories', 'catalog_category_view_type_layered', 'all', [], ['category:anchor:']],
            'specific anchor categories (real instances 3-18)' => ['anchor_categories', 'catalog_category_view_type_layered', 'specific', [37], ['category:ids:37']],
            'all non-anchor categories' => ['notanchor_categories', 'catalog_category_view_type_default', 'all', [], ['category:non_anchor:']],
            'specific non-anchor categories' => ['notanchor_categories', 'catalog_category_view_type_default', 'specific', [5, 6], ['category:ids:5,6']],
            'all products' => ['all_products', 'catalog_product_view', 'all', [], ['product:all:']],
            'specific products' => ['all_products', 'catalog_product_view', 'specific', [7], ['product:ids:7']],
            'all simple products' => ['simple_products', 'catalog_product_view_type_simple', 'all', [], ['product:product_type:simple']],
            'specific configurable products' => ['configurable_products', 'catalog_product_view_type_configurable', 'specific', [9], ['product:ids:9']],
            'specific without entities' => ['anchor_categories', 'catalog_category_view_type_layered', 'specific', [], []],
            'malformed product type group' => ['Bad-Type_products', 'catalog_product_view', 'all', [], []],
            'unknown group' => ['checkout', 'checkout_index_index', 'all', [], []],
        ];
    }

    #[DataProvider('pageGroups')]
    public function testEveryPageGroupResolvesToTheThreeHostsOnly(string $group, string $handle, string $for, array $entities, array $expected): void
    {
        $this->assertSame($expected, self::keys($this->resolver()->selectorsOf(new WidgetPageGroup($group, $handle, $for, $entities))));
    }

    /** @return array<string, array{0: HostSelector, 1: HostFacts, 2: bool}> */
    public static function matching(): array
    {
        return [
            'every page' => [new HostSelector('page', 'all'), new HostFacts('page', 4, 'news'), true],
            'other host type' => [new HostSelector('page', 'all'), new HostFacts('product', 4), false],
            'listed id' => [new HostSelector('category', 'ids', [37, 38]), new HostFacts('category', 38), true],
            'unlisted id' => [new HostSelector('category', 'ids', [37]), new HostFacts('category', 39), false],
            'listed id of another host type' => [new HostSelector('category', 'ids', [37]), new HostFacts('product', 37), false],
            'anchor category' => [new HostSelector('category', 'anchor'), new HostFacts('category', 5, '', '', true), true],
            'anchor selector, non-anchor category' => [new HostSelector('category', 'anchor'), new HostFacts('category', 5), false],
            'non-anchor category' => [new HostSelector('category', 'non_anchor'), new HostFacts('category', 5), true],
            'non-anchor selector, anchor category' => [new HostSelector('category', 'non_anchor'), new HostFacts('category', 5, '', '', true), false],
            'product type' => [new HostSelector('product', 'product_type', ['bundle']), new HostFacts('product', 7, '', 'bundle'), true],
            'other product type' => [new HostSelector('product', 'product_type', ['bundle']), new HostFacts('product', 7, '', 'simple'), false],
            'home page' => [new HostSelector('page', 'home'), new HostFacts('page', 2, 'home'), true],
            'not the home page' => [new HostSelector('page', 'home'), new HostFacts('page', 3, 'about'), false],
            'page handle with a slash' => [new HostSelector('page', 'page_handle', ['news_offers']), new HostFacts('page', 3, 'news/offers'), true],
            'page handle of another page' => [new HostSelector('page', 'page_handle', ['news_offers']), new HostFacts('page', 3, 'news'), false],
            'unknown mode' => [new HostSelector('page', 'everything'), new HostFacts('page', 3, 'news'), false],
        ];
    }

    #[DataProvider('matching')]
    public function testSelectorsMatchHostFacts(HostSelector $selector, HostFacts $host, bool $expected): void
    {
        $this->assertSame($expected, $this->resolver()->matches($selector, $host, 'home'));
    }

    public function testAnEmptyHomeIdentifierMatchesNoPage(): void
    {
        $this->assertFalse($this->resolver()->matches(new HostSelector('page', 'home'), new HostFacts('page', 2, ''), ''));
    }

    public function testTheFooterLinksInstanceOnAllPagesReachesNoHost(): void
    {
        // Real instance 2: CMS block 2 (footer_links_block), theme Luma, all store views, page group all_pages.
        $resolver = $this->resolver();
        $footer = new WidgetInstance(2, self::CMS, 3, [0], ['block_id' => '2'], [
            new WidgetPageGroup('all_pages', 'default', 'all', []),
        ]);
        $this->assertSame(['block#2'], array_map(static fn (BlockRef $r): string => $r->key(), $resolver->blockRefs($footer)));
        $this->assertSame([], $resolver->selectors($footer), 'all_pages placements publish nothing');
        $hosts = [
            new HostFacts('page', 2, 'home'),
            new HostFacts('page', 3, 'about-us'),
            new HostFacts('category', 37, '', '', true),
            new HostFacts('category', 5),
            new HostFacts('product', 7, '', 'simple'),
        ];
        foreach ($resolver->selectors($footer) as $selector) {
            foreach ($hosts as $host) {
                $this->assertFalse($resolver->matches($selector, $host, 'home'));
            }
        }
    }

    public function testAnAllPagesGroupNextToARealPlacementKeepsOnlyTheRealPlacement(): void
    {
        $instance = self::instance(self::CMS, ['block_id' => '3'], [
            new WidgetPageGroup('all_pages', 'default', 'all', []),
            new WidgetPageGroup('anchor_categories', 'catalog_category_view_type_layered', 'specific', [37]),
            new WidgetPageGroup('pages', 'contact_index_index', 'all', []),
        ]);
        $this->assertSame(['category:ids:37'], self::keys($this->resolver()->selectors($instance)));
    }

    public function testAnInstanceWithoutPageGroupsHasNoSelectors(): void
    {
        // Real instance 14 has no widget_instance_page row.
        $this->assertSame([], $this->resolver()->selectors(self::instance(self::CMS, ['block_id' => '15'])));
    }

    public function testAnInstanceAppliesToItsThemeAndStoreViewsOnly(): void
    {
        $resolver = $this->resolver();
        $allStores = self::instance(self::CMS, ['block_id' => '3'], [], 3, [0]);
        $twoAndThree = self::instance(self::CMS, ['block_id' => '3'], [], 5, [2, 3]);
        $this->assertTrue($resolver->appliesToStore($allStores, 1, 3));
        $this->assertTrue($resolver->appliesToStore($allStores, 7, 3), 'store 0 means every store view of the theme');
        $this->assertFalse($resolver->appliesToStore($allStores, 2, 5), 'Luma instances do not reach Local/* child themes');
        $this->assertTrue($resolver->appliesToStore($twoAndThree, 3, 5));
        $this->assertFalse($resolver->appliesToStore($twoAndThree, 4, 5));
        $this->assertFalse($resolver->appliesToStore($twoAndThree, 2, 3), 'listed store view with another theme');
    }

    public function testBlockReferencesOfTheTwoSupportedWidgets(): void
    {
        $keys = fn (WidgetInstance $i): array => array_map(static fn (BlockRef $r): string => $r->key(), $this->resolver()->blockRefs($i));
        $this->assertSame(['block#3'], $keys(self::instance(self::CMS, ['block_id' => '3'])));
        $this->assertSame(['block@footer'], $keys(self::instance(self::CMS, ['block_id' => 'footer'])));
        $this->assertSame([], $keys(self::instance(self::CMS, ['block_id' => ['3']])));
        $this->assertSame([], $keys(self::instance(self::CMS, [])));
        $this->assertSame(['dynamic_block#4', 'dynamic_block#5'], $keys(self::instance(self::BANNER, ['display_mode' => 'fixed', 'banner_ids' => '4,5'])));
        $this->assertSame(['dynamic_block#6'], $keys(self::instance(self::BANNER, ['banner_ids' => ['6', 'x']])));
        $this->assertSame([], $keys(self::instance(self::BANNER, ['display_mode' => 'catalogrule', 'banner_ids' => '4'])));
        // storefront: an empty display_mode is fixed (Banner::getDisplayMode), the comparison is case-sensitive
        $this->assertSame(['dynamic_block#4'], $keys(self::instance(self::BANNER, ['display_mode' => '', 'banner_ids' => '4'])));
        $this->assertSame(['dynamic_block#4'], $keys(self::instance(self::BANNER, ['display_mode' => null, 'banner_ids' => '4'])));
        $this->assertSame(['dynamic_block#4'], $keys(self::instance(self::BANNER, ['banner_ids' => '4'])));
        $this->assertSame([], $keys(self::instance(self::BANNER, ['display_mode' => 'FIXED', 'banner_ids' => '4'])));
        $this->assertSame([], $keys(self::instance(self::BANNER, ['display_mode' => ['fixed'], 'banner_ids' => '4'])));
        $this->assertSame([], $keys(self::instance('Magento\\CatalogWidget\\Block\\Product\\ProductsList', ['block_id' => '3'])));
    }

    public function testSelectorsOfAnInstanceAreUnique(): void
    {
        $instance = self::instance(self::CMS, ['block_id' => '3'], [
            new WidgetPageGroup('anchor_categories', 'catalog_category_view_type_layered', 'all', []),
            new WidgetPageGroup('pages', 'catalog_category_view_type_layered', 'all', []),
            new WidgetPageGroup('all_products', 'catalog_product_view', 'specific', [7]),
            new WidgetPageGroup('simple_products', 'catalog_product_view_type_simple', 'specific', [7]),
        ]);
        $this->assertSame(['category:anchor:', 'product:ids:7'], self::keys($this->resolver()->selectors($instance)));
    }

    public function testTheStoreThemeIsTheStoreScopedDesignSetting(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->expects($this->once())->method('getValue')
            ->with('design/theme/theme_id', 'store', 4)->willReturn('8');
        $this->assertSame(8, (new StoreThemeResolver($config))->themeId(4));
    }
}
