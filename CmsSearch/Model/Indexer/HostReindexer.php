<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

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
                    $this->blockReferenceResolver->refreshForHosts($type, $batch, $storeId);
                    match ($type) {
                        HostType::PAGE => $this->pageIndexer->reindex($batch, [$store]),
                        HostType::CATEGORY => $this->categoryIndexer->reindex($batch, [$store]),
                        HostType::PRODUCT => $this->productBlockTextWriter->refresh($batch, $storeId),
                    };
                    $counts[$type] += count($batch);
                }
            }
        }
        return $counts;
    }
}
