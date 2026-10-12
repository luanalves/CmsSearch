<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Thedevkitchen\CmsSearch\Model\Indexer\Data\ContentTypeState;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\TermState;

/**
 * Is a CMS page searchable in a store view? (spec 4.4, D5, D6, D9). Everything is read from the CURRENT state at index
 * time: the page link may be stale (store views changed through REST/grid/Staging), so the term's website is compared
 * with the store view's website here. The store view belongs to the page (checked first), so this is exactly
 * "the term's website is one of the page's websites AND it is the website of this store view".
 *
 * Shadowing: Magento serves a CMS page by identifier with `store_id IN (0, S) ORDER BY store_id DESC LIMIT 1`
 * (Magento\Cms\Model\ResourceModel\Page::checkIdentifier, active pages only), so a page of All Store Views whose
 * identifier is also used by an active page assigned to store view S is never served in S. The caller computes that
 * fact in batch (ShadowedPageReader) and passes it as $shadowed.
 */
class VisibilityChecker
{
    public const VISIBLE = 'visible';
    public const PAGE_MISSING = 'page_missing';
    public const PAGE_INACTIVE = 'page_inactive';
    public const NOT_IN_STORE = 'not_in_store';
    public const SHADOWED = 'shadowed';
    public const NOT_CLASSIFIED = 'not_classified';
    public const TYPE_MISSING = 'type_missing';
    public const TYPE_INACTIVE = 'type_inactive';
    public const TERM_MISSING = 'term_missing';
    public const TERM_INACTIVE = 'term_inactive';
    public const TERM_OTHER_WEBSITE = 'term_other_website';

    /**
     * @param array{type_id: int, term_id: int}|null $link
     * @param bool $shadowed another active page with the same identifier is assigned to this store view
     */
    public function check(
        ?PageSnapshot $page,
        ?array $link,
        ?ContentTypeState $type,
        ?TermState $term,
        StoreContext $store,
        bool $shadowed = false
    ): string {
        if ($page === null) {
            return self::PAGE_MISSING;
        }
        if (!$page->isActive()) {
            return self::PAGE_INACTIVE;
        }
        $storeIds = $page->getStoreIds();
        if (!in_array(0, $storeIds, true) && !in_array($store->getStoreId(), $storeIds, true)) {
            return self::NOT_IN_STORE;
        }
        if ($shadowed && !in_array($store->getStoreId(), $storeIds, true)) {
            return self::SHADOWED; // served here only through store 0, and store 0 loses to the store view's page
        }
        if ($link === null) {
            return self::NOT_CLASSIFIED;
        }
        if ($type === null || $type->getTypeId() !== $link['type_id']) {
            return self::TYPE_MISSING;
        }
        if (!$type->isActive()) {
            return self::TYPE_INACTIVE;
        }
        if ($term === null || $term->getTermId() !== $link['term_id']) {
            return self::TERM_MISSING;
        }
        if (!$term->isEffectiveActive()) {
            return self::TERM_INACTIVE;
        }
        if ($term->getWebsiteId() !== $store->getWebsiteId()) {
            return self::TERM_OTHER_WEBSITE;
        }
        return self::VISIBLE;
    }
}
