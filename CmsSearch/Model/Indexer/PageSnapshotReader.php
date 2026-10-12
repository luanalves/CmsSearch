<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page\IdentityMap;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;

/**
 * Reads the CURRENT version of each page through PageRepositoryInterface by page_id: under Content Staging the
 * repository resolves the active row_id, so scheduled versions are never indexed before they start (D9, TS-13).
 *
 * Cost: one repository load (several queries) per page, bounded by the processor's batch size.
 *
 * Long-lived consumer: Staging caches the current version id in VersionHistory (loaded once per object, cleared only
 * by _resetState at the end of a request). read() resets it first, so a version applied by the Staging cron while the
 * consumer runs is seen by the very next read.
 */
class PageSnapshotReader
{
    /**
     * @param IdentityMap $identityMap the shared core identity map that PageRepository::getById() fills with every
     *        loaded page (full content): released after each snapshot so a consumer does not accumulate pages
     */
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly IdentityMap $identityMap,
        private readonly VersionHistoryInterface $versionHistory
    ) {
    }

    /**
     * @param array<int|string> $pageIds
     * @return array<int, PageSnapshot> pages that no longer exist are absent
     */
    public function read(array $pageIds): array
    {
        if ($this->versionHistory instanceof ResetAfterRequestInterface) {
            $this->versionHistory->_resetState();
        }
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
            try {
                $snapshots[$pageId] = $this->snapshot($pageId, $page);
            } finally {
                $this->identityMap->remove($pageId);
            }
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
