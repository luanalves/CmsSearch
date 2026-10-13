<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSelector;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * Expands a widget placement (HostSelector) into host ids of one store view: one query per selector, none for listed
 * ids (a "specific" placement is the layout handle catalog_*_view_id_<id>: that one entity only, whatever its anchor
 * flag). Pages: every page / the home page identifier / the cms_page_view_id_<identifier> handle (store view and
 * shadowing are filtered by HostSourceReader). Products: of the store view's website. Categories: below the store
 * view's root category; anchor by the global is_anchor value, a category without the value is NOT anchor (the
 * category controller tests getIsAnchor(), no default applied on load). Hosts that turn out hidden are filtered later
 * by HostSourceReader; this class only has to be complete. Staged tables (cms_page, catalog_*_entity) are read in
 * their current version: the cached Staging version is reloaded before each query (long-lived consumer).
 *
 * Memory: the result is one list of ints, about 2 MB per 100k hosts (plus a transient ~5 MB of fetched strings); the
 * caller reads the hosts themselves in batches (HostSourceReader and its readers chunk every query).
 */
class HostCatalog
{
    private const CATEGORY_MODES = [HostSelector::MODE_ALL, HostSelector::MODE_ANCHOR, HostSelector::MODE_NON_ANCHOR];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly VersionHistoryInterface $versionHistory
    ) {
    }

    /**
     * @return list<int> ascending
     */
    public function ids(HostSelector $selector, StoreContext $store): array
    {
        if ($selector->getMode() === HostSelector::MODE_IDS) {
            return self::sorted($selector->getValues());
        }
        $select = match ($selector->getHostType()) {
            HostType::PAGE => $this->pages($selector, $store),
            HostType::PRODUCT => $this->products($selector, $store),
            HostType::CATEGORY => $this->categories($selector, $store->getStoreId()),
            default => null,
        };
        if ($select === null) {
            return [];
        }
        if ($this->versionHistory instanceof ResetAfterRequestInterface) {
            $this->versionHistory->_resetState();
        }
        return self::sorted($this->resource->getConnection()->fetchCol($select));
    }

    private function pages(HostSelector $selector, StoreContext $store): ?Select
    {
        $mode = $selector->getMode();
        if ($mode === HostSelector::MODE_HOME && $store->getHomeIdentifier() === '') {
            return null;
        }
        if (!in_array($mode, [HostSelector::MODE_ALL, HostSelector::MODE_HOME, HostSelector::MODE_PAGE_HANDLE], true)) {
            return null;
        }
        $select = $this->resource->getConnection()->select()->distinct()
            ->from($this->resource->getTableName('cms_page'), ['page_id']);
        return match ($mode) {
            HostSelector::MODE_HOME => $select->where('identifier = ?', $store->getHomeIdentifier()),
            HostSelector::MODE_PAGE_HANDLE => $select->where("REPLACE(identifier, '/', '_') IN (?)", array_map('strval', $selector->getValues())),
            default => $select,
        };
    }

    private function products(HostSelector $selector, StoreContext $store): ?Select
    {
        $mode = $selector->getMode();
        if ($mode !== HostSelector::MODE_ALL && $mode !== HostSelector::MODE_PRODUCT_TYPE) {
            return null;
        }
        $select = $this->resource->getConnection()->select()->distinct()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['entity_id'])
            ->join(['w' => $this->resource->getTableName('catalog_product_website')], 'w.product_id = e.entity_id', [])
            ->where('w.website_id = ?', $store->getWebsiteId());
        return $mode === HostSelector::MODE_PRODUCT_TYPE
            ? $select->where('e.type_id IN (?)', array_map('strval', $selector->getValues()))
            : $select;
    }

    private function categories(HostSelector $selector, int $storeId): ?Select
    {
        if (!in_array($selector->getMode(), self::CATEGORY_MODES, true)) {
            return null;
        }
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            return null;
        }
        $rootId = $store instanceof Store ? (int) $store->getRootCategoryId() : 0;
        if ($rootId <= 0) {
            return null;
        }
        $select = $this->resource->getConnection()->select()->distinct()
            ->from(['e' => $this->resource->getTableName('catalog_category_entity')], ['entity_id'])
            ->where('e.path LIKE ?', '1/' . $rootId . '/%')
            ->where('e.level >= ?', 2);
        if ($selector->getMode() !== HostSelector::MODE_ALL) {
            $link = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
            $anchorId = (int) $this->eavConfig->getAttribute('catalog_category', 'is_anchor')->getAttributeId();
            $select->joinLeft(
                ['a' => $this->resource->getTableName('catalog_category_entity_int')],
                sprintf('a.%1$s = e.%1$s AND a.attribute_id = %2$d AND a.store_id = 0', $link, $anchorId),
                []
            )->where('COALESCE(a.value, 0) = ?', $selector->getMode() === HostSelector::MODE_ANCHOR ? 1 : 0);
        }
        return $select;
    }

    /**
     * @param array<int|string> $values
     * @return list<int>
     */
    private static function sorted(array $values): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }
}
