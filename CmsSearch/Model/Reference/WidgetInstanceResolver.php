<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostFacts;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSelector;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetInstance;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetPageGroup;

/**
 * Decision table "where does a widget instance show its block" reduced to the three hosts (D3, engineering 5.5,
 * TS-19, TS-22). Page groups and handles follow Magento\Widget\Model\Widget\Instance; host handles follow the CMS page
 * helper (cms_page_view, cms_page_view_id_<identifier with / as _>), the category controller
 * (catalog_category_view_type_layered|default by is_anchor) and the product view helper
 * (catalog_product_view_type_<type>, catalog_product_view_id_<id>). Everything else - cart, checkout, account, contact,
 * page layouts, the leaf-only "_without_children" handles - is no host and is ignored without error.
 *
 * USER DECISION (Plan 6, decision 7): placements on every page (page group all_pages, or a specified page with the
 * "default" handle) carry generic site chrome - footer, header, menu, links blocks - that is not searchable content:
 * they publish to NO host. The index covers only the content of CMS pages, category pages and product pages.
 */
class WidgetInstanceResolver
{
    public const SUPPORTED_TYPES = [DirectiveParser::CMS_BLOCK_WIDGET, DirectiveParser::DYNAMIC_BLOCK_WIDGET];

    /** Page group of the "All Pages" placement (handle "default"): ignored, see the class comment. */
    public const ALL_PAGES_GROUP = 'all_pages';

    private const PRODUCT_TYPE_GROUP = '/^([a-z0-9_]+)_products$/';

    public function __construct(private readonly DirectiveParser $directiveParser)
    {
    }

    /**
     * @return list<BlockRef>
     */
    public function blockRefs(WidgetInstance $instance): array
    {
        $parameters = $instance->getParameters();
        if ($instance->getType() === DirectiveParser::CMS_BLOCK_WIDGET) {
            $value = $parameters['block_id'] ?? null;
            $ref = is_scalar($value) ? $this->directiveParser->cmsBlockRef((string) $value) : null;
            return $ref === null ? [] : [$ref];
        }
        if ($instance->getType() === DirectiveParser::DYNAMIC_BLOCK_WIDGET) {
            $ids = $parameters['banner_ids'] ?? '';
            $ids = is_array($ids) ? implode(',', array_filter($ids, 'is_scalar')) : (is_scalar($ids) ? (string) $ids : '');
            $mode = $parameters['display_mode'] ?? '';
            // empty (also false/[]) = fixed like the storefront; any other non-scalar value is no display mode
            $mode = is_scalar($mode) ? (string) $mode : (empty($mode) ? '' : 'invalid');
            return $this->directiveParser->dynamicBlockRefs($ids, $mode);
        }
        return [];
    }

    /**
     * Widget instances are layout updates of ONE theme (layout_link.theme_id, no inheritance) and of the store views in
     * store_ids (0 = all).
     */
    public function appliesToStore(WidgetInstance $instance, int $storeId, int $storeThemeId): bool
    {
        return $instance->getThemeId() === $storeThemeId
            && (in_array(0, $instance->getStoreIds(), true) || in_array($storeId, $instance->getStoreIds(), true));
    }

    /**
     * @return list<HostSelector> unique, in page group order
     */
    public function selectors(WidgetInstance $instance): array
    {
        $selectors = [];
        foreach ($instance->getPageGroups() as $group) {
            foreach ($this->selectorsOf($group) as $selector) {
                $selectors[$selector->key()] ??= $selector;
            }
        }
        return array_values($selectors);
    }

    /**
     * @return list<HostSelector>
     */
    public function selectorsOf(WidgetPageGroup $group): array
    {
        $name = $group->getGroup();
        if ($name === self::ALL_PAGES_GROUP) {
            return []; // site chrome, not indexed (user decision)
        }
        if ($name === 'pages') {
            return $this->byHandle($group->getLayoutHandle());
        }
        $categories = in_array($name, ['anchor_categories', 'notanchor_categories'], true);
        $products = $name === 'all_products' || preg_match(self::PRODUCT_TYPE_GROUP, $name) === 1;
        if (!$categories && !$products) {
            return []; // page_layouts and anything unknown
        }
        if ($group->isSpecific()) {
            return $group->getEntities() === []
                ? []
                : [new HostSelector($categories ? HostType::CATEGORY : HostType::PRODUCT, HostSelector::MODE_IDS, $group->getEntities())];
        }
        if ($categories) {
            $mode = $name === 'anchor_categories' ? HostSelector::MODE_ANCHOR : HostSelector::MODE_NON_ANCHOR;
            return [new HostSelector(HostType::CATEGORY, $mode)];
        }
        if ($name === 'all_products') {
            return [new HostSelector(HostType::PRODUCT, HostSelector::MODE_ALL)];
        }
        preg_match(self::PRODUCT_TYPE_GROUP, $name, $match);
        return [new HostSelector(HostType::PRODUCT, HostSelector::MODE_PRODUCT_TYPE, [$match[1]])];
    }

    public function matches(HostSelector $selector, HostFacts $host, string $homeIdentifier): bool
    {
        if ($selector->getHostType() !== $host->getHostType()) {
            return false;
        }
        return match ($selector->getMode()) {
            HostSelector::MODE_ALL => true,
            HostSelector::MODE_IDS => in_array($host->getHostId(), $selector->getValues(), true),
            HostSelector::MODE_ANCHOR => $host->isAnchor(),
            HostSelector::MODE_NON_ANCHOR => !$host->isAnchor(),
            HostSelector::MODE_PRODUCT_TYPE => in_array($host->getProductType(), $selector->getValues(), true),
            HostSelector::MODE_HOME => $homeIdentifier !== '' && $host->getIdentifier() === $homeIdentifier,
            HostSelector::MODE_PAGE_HANDLE => in_array(str_replace('/', '_', $host->getIdentifier()), $selector->getValues(), true),
            default => false,
        };
    }

    /**
     * @return list<HostSelector>
     */
    private function byHandle(string $handle): array
    {
        $id = static fn (string $value): ?int => (int) $value <= ReindexMessage::MAX_ENTITY_ID ? (int) $value : null;
        if ($handle === 'cms_page_view') {
            return [new HostSelector(HostType::PAGE, HostSelector::MODE_ALL)];
        }
        if ($handle === 'cms_index_index') {
            return [new HostSelector(HostType::PAGE, HostSelector::MODE_HOME)];
        }
        if (preg_match('/^cms_page_view_id_(.+)$/', $handle, $match) === 1) {
            return [new HostSelector(HostType::PAGE, HostSelector::MODE_PAGE_HANDLE, [$match[1]])];
        }
        if ($handle === 'catalog_product_view') {
            return [new HostSelector(HostType::PRODUCT, HostSelector::MODE_ALL)];
        }
        if (preg_match('/^catalog_product_view_id_([1-9][0-9]{0,9})$/', $handle, $match) === 1 && $id($match[1]) !== null) {
            return [new HostSelector(HostType::PRODUCT, HostSelector::MODE_IDS, [(int) $match[1]])];
        }
        if (preg_match('/^catalog_product_view_type_([a-z0-9_]+)$/', $handle, $match) === 1) {
            return [new HostSelector(HostType::PRODUCT, HostSelector::MODE_PRODUCT_TYPE, [$match[1]])];
        }
        if ($handle === 'catalog_category_view') {
            return [new HostSelector(HostType::CATEGORY, HostSelector::MODE_ALL)];
        }
        if ($handle === 'catalog_category_view_type_layered') {
            return [new HostSelector(HostType::CATEGORY, HostSelector::MODE_ANCHOR)];
        }
        if ($handle === 'catalog_category_view_type_default') {
            return [new HostSelector(HostType::CATEGORY, HostSelector::MODE_NON_ANCHOR)];
        }
        if (preg_match('/^catalog_category_view_id_([1-9][0-9]{0,9})$/', $handle, $match) === 1 && $id($match[1]) !== null) {
            return [new HostSelector(HostType::CATEGORY, HostSelector::MODE_IDS, [(int) $match[1]])];
        }
        return []; // "default" (every page: site chrome, user decision) and every handle without a host
    }
}
