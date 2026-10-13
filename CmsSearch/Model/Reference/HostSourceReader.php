<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\CategorySnapshotReader;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\PageSnapshotReader;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostFacts;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSource;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * Hosts as the reference graph sees them in one store view. A host that does not exist or is not shown there is
 * absent, so its references are removed: a page that is inactive, of other store views, or of All Store Views but
 * shadowed by the store view's own page with the same URL key (ShadowedPageReader); a category that is inactive or
 * outside the store view's root (Catalog\Helper\Category::canShow); a product outside the store view's website.
 * Product status and visibility are left to the native product index.
 */
class HostSourceReader
{
    public function __construct(
        private readonly PageSnapshotReader $pageSnapshotReader,
        private readonly ProductSourceReader $productSourceReader,
        private readonly CategorySnapshotReader $categorySnapshotReader,
        private readonly StoreManagerInterface $storeManager,
        private readonly ShadowedPageReader $shadowedPageReader
    ) {
    }

    /**
     * @param array<int|string> $hostIds the caller's batch (each reader chunks its queries)
     * @return array<int, HostSource>
     */
    public function read(string $hostType, array $hostIds, int $storeId): array
    {
        if (!in_array($hostType, HostType::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown host type "%s".', substr($hostType, 0, 40)));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $hostIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        return match ($hostType) {
            HostType::PAGE => $this->pages($ids, $storeId),
            HostType::PRODUCT => $this->productSourceReader->read($ids, $storeId),
            HostType::CATEGORY => $this->categories($ids, $storeId),
        };
    }

    /**
     * @param list<int> $ids
     * @return array<int, HostSource>
     */
    private function pages(array $ids, int $storeId): array
    {
        /** @var array<int, PageSnapshot> $shown */
        $shown = [];
        foreach ($this->pageSnapshotReader->read($ids) as $pageId => $page) {
            $stores = $page->getStoreIds();
            if ($page->isActive() && (in_array(0, $stores, true) || in_array($storeId, $stores, true))) {
                $shown[(int) $pageId] = $page;
            }
        }
        if ($shown === []) {
            return [];
        }
        $shadowed = $this->shadowedPageReader->read($shown, [$storeId]);
        $sources = [];
        foreach ($shown as $pageId => $page) {
            if (in_array($storeId, $shadowed[$pageId] ?? [], true)) {
                continue;
            }
            $sources[$pageId] = new HostSource(
                new HostFacts(HostType::PAGE, $pageId, $page->getIdentifier()),
                trim($page->getContent()) === '' ? [] : [$page->getContent()]
            );
        }
        ksort($sources);
        return $sources;
    }

    /**
     * @param list<int> $ids
     * @return array<int, HostSource>
     */
    private function categories(array $ids, int $storeId): array
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            return [];
        }
        $rootId = $store instanceof Store ? (int) $store->getRootCategoryId() : 0;
        if ($rootId <= 0) {
            return [];
        }
        $sources = [];
        foreach ($this->categorySnapshotReader->read($ids, $storeId) as $categoryId => $category) {
            if (!$category->isActive() || !$category->isInTree($rootId)) {
                continue;
            }
            $landing = $category->getLandingBlockId();
            $sources[(int) $categoryId] = new HostSource(
                new HostFacts(HostType::CATEGORY, (int) $categoryId, '', '', $category->isAnchor()),
                trim($category->getDescription()) === '' ? [] : [$category->getDescription()],
                $landing >= 1 && $landing <= ReindexMessage::MAX_BLOCK_ID ? [new BlockRef(BlockRef::KIND_BLOCK, $landing)] : []
            );
        }
        return $sources;
    }
}
