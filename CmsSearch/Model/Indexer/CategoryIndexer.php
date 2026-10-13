<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\CategorySnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Reference\BlockTextResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * Category documents of a set of categories in each store view (same contract as PageIndexer::reindex): a category
 * that is active (store value), below the store view's root category and browsable by visitors who are not logged in
 * (catalog permissions / B2B shared catalog: CategoryPermissionReader) is upserted with the text of the blocks it
 * publishes; anything else (missing, inactive, other tree, restricted) is deleted by its deterministic id. The CALLER
 * refreshes the reference graph of the categories first (FullReindexer, the queue processor); this class never
 * writes the graph. Category values are store-scoped, so categories are read per store view and batch.
 *
 * Modes: incremental ($targets empty) writes through each store view's alias (created when missing) and deletes;
 * full rebuild ($targets = store id => new version) only adds visible categories. Failures propagate (the queue
 * handler retries the whole, idempotent call; the full rebuild drops its unfinished version).
 */
class CategoryIndexer
{
    public function __construct(
        private readonly CategorySnapshotReader $categorySnapshotReader,
        private readonly BlockTextResolver $blockTextResolver,
        private readonly CategoryDocumentBuilder $documentBuilder,
        private readonly IndexManager $indexManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryPermissionReader $permissionReader,
        private readonly int $batchSize = 200
    ) {
    }

    /**
     * @param array<int|string> $categoryIds
     * @param list<StoreContext> $stores
     * @param array<int, string> $targets store id => new index of a full rebuild (no deletes); empty = incremental
     * @return array{upserted: int, deleted: int}
     * @throws \LogicException when a full rebuild does not name a target for every store view
     */
    public function reindex(array $categoryIds, array $stores, array $targets = []): array
    {
        $result = ['upserted' => 0, 'deleted' => 0];
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn (int $id): bool => $id > 0)));
        sort($ids);
        if ($ids === [] || $stores === []) {
            return $result;
        }
        $full = $targets !== [];
        foreach ($stores as $store) {
            if ($full && !isset($targets[$store->getStoreId()])) {
                throw new \LogicException(sprintf('No rebuild target for store view %d.', $store->getStoreId()));
            }
        }
        foreach ($stores as $store) {
            $storeId = $store->getStoreId();
            $target = $full ? $targets[$storeId] : $this->indexManager->ensureAlias($store);
            $rootId = $this->rootCategoryId($storeId);
            foreach (array_chunk($ids, max(1, $this->batchSize)) as $batch) {
                $visible = array_filter(
                    $this->categorySnapshotReader->read($batch, $storeId),
                    static fn (CategorySnapshot $category): bool => $category->isActive() && $category->isInTree($rootId)
                );
                if ($visible !== []) {
                    $public = $this->permissionReader->visibleToGuests(array_keys($visible), $storeId, $store->getWebsiteId());
                    $visible = array_intersect_key($visible, array_flip($public));
                }
                $enrichments = $visible === []
                    ? []
                    : $this->blockTextResolver->forHosts(HostType::CATEGORY, array_keys($visible), $storeId);
                $operations = [];
                foreach ($batch as $categoryId) {
                    $id = CategoryDocumentBuilder::documentId($categoryId);
                    if (isset($visible[$categoryId])) {
                        $operations[] = [
                            'action' => 'index',
                            'id' => $id,
                            'document' => $this->documentBuilder->build($visible[$categoryId], $store, $enrichments[$categoryId] ?? null),
                        ];
                        $result['upserted']++;
                    } elseif (!$full) {
                        $operations[] = ['action' => 'delete', 'id' => $id];
                        $result['deleted']++;
                    }
                }
                if ($operations !== []) {
                    $this->indexManager->bulk($target, $operations, !$full);
                }
            }
        }
        return $result;
    }

    private function rootCategoryId(int $storeId): int
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            return 0;
        }
        return $store instanceof Store ? (int) $store->getRootCategoryId() : 0;
    }
}
