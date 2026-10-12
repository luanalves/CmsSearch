<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\PageClassification;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\Page;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Websites a CMS Page is published in, derived from its store views (D6: terms are per website).
 */
class PageWebsites
{
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    /**
     * @param array<int|string> $storeIds store views of the Page; 0 = All Store Views
     * @return int[] sorted, unique website ids
     */
    public function fromStoreIds(array $storeIds): array
    {
        $storeIds = array_map('intval', $storeIds);
        $websiteIds = [];
        if (in_array(0, $storeIds, true)) {
            foreach ($this->storeManager->getWebsites() as $website) {
                $websiteIds[] = (int) $website->getId();
            }
        } else {
            foreach ($this->storeManager->getStores() as $store) {
                if (in_array((int) $store->getId(), $storeIds, true)) {
                    $websiteIds[] = (int) $store->getWebsiteId();
                }
            }
        }
        $websiteIds = array_values(array_unique(array_filter($websiteIds, static fn (int $id): bool => $id > 0)));
        sort($websiteIds);
        return $websiteIds;
    }

    /**
     * Store views of a Page model as posted by the form or loaded from the database.
     *
     * @return int[]
     */
    public function storeIdsOf(PageInterface $page): array
    {
        if (!$page instanceof Page) {
            return [];
        }
        return array_values(array_map('intval', (array) $page->getStores()));
    }
}
