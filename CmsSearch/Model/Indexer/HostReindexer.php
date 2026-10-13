<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReferenceResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * Consumer work for a set of hosts: per store view and host type, in ascending batches of at most $batchSize ids,
 * first the hosts' reference graph is rebuilt from their current content (so the text read next, and the next
 * block/widget message, see the current references), then their page or category document is upserted/deleted
 * (incremental, through the store view's alias) or their product block text row is written (only when its hash
 * changed; the native fulltext mview picks it up). A host bound to a store view outside $stores is skipped.
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
        $storeIds = array_map(static fn (StoreContext $store): int => $store->getStoreId(), $stores);
        /** @var array<int, array<string, array<int, true>>> $plan store id => host type => host ids */
        $plan = [];
        foreach ($hosts as $host) {
            if (!in_array($host['host_type'], HostType::ALL, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown host type "%s".', substr((string) $host['host_type'], 0, 40)));
            }
            $targets = $host['store_id'] === null
                ? $storeIds
                : (in_array((int) $host['store_id'], $storeIds, true) ? [(int) $host['store_id']] : []);
            foreach ($targets as $storeId) {
                $plan[$storeId][$host['host_type']][(int) $host['host_id']] = true;
            }
        }
        foreach ($stores as $store) {
            $storeId = $store->getStoreId();
            foreach (HostType::ALL as $type) {
                $ids = array_keys($plan[$storeId][$type] ?? []);
                sort($ids);
                foreach (array_chunk($ids, max(1, $this->batchSize)) as $batch) {
                    $this->reindexBatch($type, $batch, $store);
                    $counts[$type] += count($batch);
                }
            }
        }
        return $counts;
    }

    /**
     * Graph refresh and index write of one batch are ONE database transaction. The graph is the only record of which
     * hosts a deleted/re-placed widget or a deleted/renamed block used to reach: if the write failed after the graph
     * had committed, the retry would recompute the hosts from the new graph, miss the old ones and leave their removed
     * text searchable. The OpenSearch write is not transactional, but on failure the rollback restores the old graph
     * (the retry sees the old hosts) and every write is idempotent. ReferenceStore's own transaction nests; this is the
     * outermost one on the consumer, which holds no pending producer messages (the after-commit flush plugin finds an
     * empty buffer; the rollback discard likewise).
     *
     * @param list<int> $batch
     */
    private function reindexBatch(string $type, array $batch, StoreContext $store): void
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->blockReferenceResolver->refreshForHosts($type, $batch, $store->getStoreId());
            match ($type) {
                HostType::PAGE => $this->pageIndexer->reindex($batch, [$store]),
                HostType::CATEGORY => $this->categoryIndexer->reindex($batch, [$store]),
                HostType::PRODUCT => $this->productBlockTextWriter->refresh($batch, $store->getStoreId()),
            };
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }
}
