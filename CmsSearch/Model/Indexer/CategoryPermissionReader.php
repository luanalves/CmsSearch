<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\CatalogPermissions\Model\ResourceModel\Permission\Index as PermissionIndex;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;

/**
 * Which categories a visitor who is NOT logged in may browse in a store view (privacy: the CMS search index is public,
 * so a category document is written only when guests can see the category page). Mirrors the storefront
 * (Magento_CatalogPermissions ApplyCategoryPermissionObserver + ApplyPermissionsOnCategory, Helper\Data::isAllowedGrant):
 *   - catalog permissions disabled for the store view => every category is public;
 *   - the guest row (customer group 0) of the website in the permission index decides: deny (-2) hides, allow (-1)
 *     shows; without a row (or "use parent" left after inheritance) the config default decides: "Yes, for everyone",
 *     "No", or "Yes, for specified customer groups" that must list group 0.
 * B2B shared catalogs: when the shared catalog is active on the website, a guest DENY recorded in
 * sharedcatalog_category_permissions (website-specific or all websites) also hides the category, even before the
 * shared catalog's asynchronous consumer copies it into the catalog permissions (conservative: never index what the
 * merchant restricted). Only the guest group is checked: a category hidden from one logged-in group but public is
 * indexed (known limit). Two queries at most per call (one per source), only when the feature is on.
 */
class CategoryPermissionReader
{
    public const XML_PATH_ENABLED = 'catalog/magento_catalogpermissions/enabled';
    public const XML_PATH_GRANT_CATEGORY_VIEW = 'catalog/magento_catalogpermissions/grant_catalog_category_view';
    public const XML_PATH_SHARED_CATALOG_ACTIVE = 'btob/website_configuration/sharedcatalog_active';
    /** Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID */
    public const GUEST_GROUP_ID = 0;
    /** Magento\CatalogPermissions\Model\Permission::PERMISSION_ALLOW / PERMISSION_DENY */
    private const PERMISSION_ALLOW = -1;
    private const PERMISSION_DENY = -2;
    /** Magento\CatalogPermissions\App\ConfigInterface::GRANT_ALL / GRANT_CUSTOMER_GROUP */
    private const GRANT_ALL = 1;
    private const GRANT_CUSTOMER_GROUP = 2;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PermissionIndex $permissionIndex,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param list<int> $categoryIds
     * @return list<int> the ids guests may browse in the store view, in the given order
     */
    public function visibleToGuests(array $categoryIds, int $storeId, int $websiteId): array
    {
        if ($categoryIds === []
            || !$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)
        ) {
            return $categoryIds;
        }
        $byDefault = $this->guestsMayBrowseByDefault($storeId);
        $rows = $this->permissionIndex->getIndexForCategory($categoryIds, self::GUEST_GROUP_ID, $websiteId);
        $sharedDenied = array_flip($this->sharedCatalogDenials($categoryIds, $websiteId));
        $visible = [];
        foreach ($categoryIds as $categoryId) {
            if (isset($sharedDenied[$categoryId])) {
                continue;
            }
            $grant = $rows[$categoryId]['grant_catalog_category_view'] ?? null;
            $grant = $grant === null ? 0 : (int) $grant;
            $allowed = $grant === self::PERMISSION_DENY ? false : ($grant === self::PERMISSION_ALLOW || $byDefault);
            if ($allowed) {
                $visible[] = $categoryId;
            }
        }
        return $visible;
    }

    private function guestsMayBrowseByDefault(int $storeId): bool
    {
        $mode = (int) $this->scopeConfig->getValue(self::XML_PATH_GRANT_CATEGORY_VIEW, ScopeInterface::SCOPE_STORE, $storeId);
        if ($mode === self::GRANT_CUSTOMER_GROUP) {
            $groups = (string) $this->scopeConfig->getValue(
                self::XML_PATH_GRANT_CATEGORY_VIEW . '_groups',
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            return $groups !== '' && in_array((string) self::GUEST_GROUP_ID, array_map('trim', explode(',', $groups)), true);
        }
        return $mode === self::GRANT_ALL;
    }

    /**
     * @param list<int> $categoryIds
     * @return list<int>
     */
    private function sharedCatalogDenials(array $categoryIds, int $websiteId): array
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_SHARED_CATALOG_ACTIVE, ScopeInterface::SCOPE_WEBSITE, $websiteId)) {
            return [];
        }
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()->distinct()
                ->from($this->resource->getTableName('sharedcatalog_category_permissions'), ['category_id'])
                ->where('category_id IN (?)', $categoryIds)
                ->where('customer_group_id = ?', self::GUEST_GROUP_ID)
                ->where('permission = ?', self::PERMISSION_DENY)
                ->where('website_id IS NULL OR website_id = ?', $websiteId)
        ));
    }
}
