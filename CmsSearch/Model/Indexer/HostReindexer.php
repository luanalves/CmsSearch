<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReferenceResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * Consumer work for a set of hosts. Before a host is written, its reference graph is rebuilt from its current content
 * (so the text read next, and the next block/widget message, see the current references). A host bound to a store
 * view outside $stores is skipped.
 *
 * - Pages: grouped by the set of store views they are written to, in ascending batches of at most $batchSize ids. Per
 *   batch the graph is refreshed in every store view of the group and then ONE PageIndexer call writes the batch to
 *   all of them, so a page's content is read and extracted once per message, not once per store view (PageIndexer
 *   prepares a page once for every store view it is given).
 * - Categories and products: per store view (their documents / block text rows hold store-view values), in batches.
 *
 * Used only by the queue processor (the consumer). Never publishes messages, never saves through models or
 * repositories; failures propagate unchanged (the queue handler retries: every step is idempotent).
 */
class HostReindexer
{
    public function __construct(
        private readonly BlockReferenceResolver $blockReferenceResolver,
        private readonly PageIndexer $pageIndexer,
        private readonly CategoryIndexer $categoryIndexer,
        private readonly ProductBlockTextWriter $productBlockTextWriter,
        private readonly ResourceConnection $resource,
        private readonly int $batchSize = 200
    ) {
    }

    /**
     * @param list<array{host_type: string, host_id: int, store_id: ?int}> $hosts store_id null = every given store view
     * @param list<StoreContext> $stores
     * @return array{page: int, category: int, product: int} host/store-view pairs processed per type
     * @throws \InvalidArgumentException for an unknown host type (before anything is written)
     */
    public function reindex(array $hosts, array $stores): array
    {
        $counts = array_fill_keys(HostType::ALL, 0);
        /** @var array<int, StoreContext> $byId */
        $byId = [];
        foreach ($stores as $store) {
            $byId[$store->getStoreId()] = $store;
        }
        /** @var array<string, array<int, array<int, true>>> $plan host type => host id => store id => true */
        $plan = [];
        foreach ($hosts as $host) {
            if (!in_array($host['host_type'], HostType::ALL, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown host type "%s".', substr((string) $host['host_type'], 0, 40)));
            }
            $storeId = $host['store_id'];
            $targets = $storeId === null
                ? array_keys($byId)
                : (isset($byId[(int) $storeId]) ? [(int) $storeId] : []);
            foreach ($targets as $target) {
                $plan[$host['host_type']][(int) $host['host_id']][$target] = true;
            }
        }
        foreach ($this->pageGroups($plan[HostType::PAGE] ?? []) as $storeIds => $pageIds) {
            $groupStores = array_map(
                static fn (string $id): StoreContext => $byId[(int) $id],
                explode(',', (string) $storeIds)
            );
            foreach (array_chunk($pageIds, max(1, $this->batchSize)) as $batch) {
                $this->reindexBatch(HostType::PAGE, $batch, $groupStores);
                $counts[HostType::PAGE] += count($batch) * count($groupStores);
            }
        }
        foreach ($stores as $store) {
            foreach ([HostType::CATEGORY, HostType::PRODUCT] as $type) {
                $ids = [];
                foreach ($plan[$type] ?? [] as $hostId => $storeSet) {
                    if (isset($storeSet[$store->getStoreId()])) {
                        $ids[] = (int) $hostId;
                    }
                }
                sort($ids);
                foreach (array_chunk($ids, max(1, $this->batchSize)) as $batch) {
                    $this->reindexBatch($type, $batch, [$store]);
                    $counts[$type] += count($batch);
                }
            }
        }
        return $counts;
    }

    /**
     * @param array<int, array<int, true>> $pages page id => store id => true
     * @return array<int|string, list<int>> "<store id>,<store id>,..." (ascending) => ascending page ids, in key order
     */
    private function pageGroups(array $pages): array
    {
        $groups = [];
        foreach ($pages as $pageId => $storeSet) {
            $storeIds = array_keys($storeSet);
            sort($storeIds);
            $groups[implode(',', $storeIds)][] = (int) $pageId;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as $key => $pageIds) {
            sort($pageIds);
            $groups[$key] = $pageIds;
        }
        return $groups;
    }

    /**
     * Graph refresh(es) and index write of one batch are ONE database transaction. The graph is the only record of which
     * hosts a deleted/re-placed widget or a deleted/renamed block used to reach: if the write failed after the graph
     * had committed, the retry would recompute the hosts from the new graph, miss the old ones and leave their removed
     * text searchable. The OpenSearch write is not transactional, but on failure the rollback restores the old graph of
     * EVERY store view of the batch (the retry sees the old hosts) and every write is idempotent. ReferenceStore's own
     * transaction nests; this is the outermost one on the consumer, which holds no pending producer messages (the
     * after-commit flush plugin finds an empty buffer; the rollback discard likewise).
     *
     * @param list<int> $batch
     * @param list<StoreContext> $stores pages: every store view of the batch's group; categories/products: one
     */
    private function reindexBatch(string $type, array $batch, array $stores): void
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($stores as $store) {
                $this->blockReferenceResolver->refreshForHosts($type, $batch, $store->getStoreId());
            }
            match ($type) {
                HostType::PAGE => $this->pageIndexer->reindex($batch, $stores),
                HostType::CATEGORY => $this->categoryIndexer->reindex($batch, $stores),
                HostType::PRODUCT => $this->productBlockTextWriter->refresh($batch, $stores[0]->getStoreId()),
            };
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }
}
