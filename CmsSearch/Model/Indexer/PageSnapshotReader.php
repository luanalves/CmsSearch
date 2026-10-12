<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page\IdentityMap;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;

/**
 * Reads the CURRENT version of each page through PageRepositoryInterface by page_id: under Content Staging the
 * repository resolves the active row_id, so scheduled versions are never indexed before they start (D9, TS-13).
 */
class PageSnapshotReader
{
    /**
     * @param IdentityMap|null $identityMap core identity map that PageRepository::getById() fills with every loaded
     *        page: released after each snapshot so a long-running consumer does not accumulate pages
     */
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ?IdentityMap $identityMap = null
    ) {
    }

    /**
     * @param array<int|string> $pageIds
     * @return array<int, PageSnapshot> pages that no longer exist are absent
     */
    public function read(array $pageIds): array
    {
        $snapshots = [];
        foreach (array_unique(array_map('intval', $pageIds)) as $pageId) {
            if ($pageId <= 0) {
                continue;
            }
            try {
                $page = $this->pageRepository->getById($pageId);
            } catch (NoSuchEntityException) {
                continue; // deleted: its documents are removed by the caller
            }
            $snapshots[$pageId] = $this->snapshot($pageId, $page);
            $this->identityMap?->remove($pageId);
        }
        return $snapshots;
    }

    private function snapshot(int $pageId, PageInterface $page): PageSnapshot
    {
        $stores = $page instanceof DataObject ? ($page->getData('store_id') ?? $page->getData('stores') ?? []) : [];
        $storeIds = array_values(array_unique(array_map('intval', (array) $stores)));
        $updateTime = $page->getUpdateTime();
        return new PageSnapshot(
            $pageId,
            (string) $page->getIdentifier(),
            (string) $page->getTitle(),
            (string) $page->getContentHeading(),
            (string) $page->getContent(),
            (string) $page->getMetaDescription(),
            (bool) $page->isActive(),
            $storeIds,
            $updateTime === null || $updateTime === '' ? null : (string) $updateTime
        );
    }
}
