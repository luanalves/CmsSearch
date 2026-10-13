<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\CatalogPermissions\Model\ResourceModel\Permission\Index as PermissionIndex;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Which categories a visitor who is NOT logged in may browse in a store view (privacy: the CMS search index is public,
 * so a category document is written only when guests can see the category page). Mirrors the storefront
 * (Magento_CatalogPermissions ApplyCategoryPermissionObserver + ApplyPermissionsOnCategory, Helper\Data::isAllowedGrant):
 *   - catalog permissions disabled for the store view => every category is public;
 *   - the guest row (customer group 0) of the website in the permission index decides: deny (-2) hides, allow (-1)
 *     shows; without a row (or "use parent" left after inheritance) the config default decides: "Yes, for everyone",
 *     "No", or "Yes, for specified customer groups" that must list group 0.
 * B2B visibility rules (shared catalog, company, customer group) are OUT OF SCOPE (D1): a shared catalog deny counts
 * only once its consumer has copied it into the catalog permissions (then it is in the index read here, after the next
 * --sync); a deny still waiting in sharedcatalog_category_permissions is not what the storefront shows and is ignored.
 * Only the guest group is checked: a category hidden from one logged-in group but public is indexed (known limit).
 * One query per call, only when catalog permissions are enabled.
 */
class CategoryPermissionReader
{
    public const XML_PATH_ENABLED = 'catalog/magento_catalogpermissions/enabled';
    public const XML_PATH_GRANT_CATEGORY_VIEW = 'catalog/magento_catalogpermissions/grant_catalog_category_view';
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
        private readonly PermissionIndex $permissionIndex
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
        $visible = [];
        foreach ($categoryIds as $categoryId) {
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
}
