<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\HostReindexer;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreListRefresher;
use Thedevkitchen\CmsSearch\Model\Reference\AffectedHosts;

/**
 * The reindex work of the consumer thedevkitchen.cmssearch.reindex (Plans 4 and 6): resolves the hosts a message may
 * change (CMS pages, categories, products - directly or through the block reference graph, AffectedHosts) and, in
 * every concerned store view, rebuilds their graph and then their document or product block text (HostReindexer).
 *
 * - Store views: the message store view (set only by the reindex command) or every store view, restricted to the
 *   message website when it has one (terms: their pages are only visible in the store views of that website).
 *   The store list is compared with the database before every message (StoreListRefresher): a store view added,
 *   deleted or re-rooted since the consumer started is seen without a restart.
 *   Without any concerned store view the hosts are not even read.
 * - Hosts are processed chunk by chunk as AffectedHosts streams them (at most 1000 hosts per chunk), for every
 *   entity type: page/content type/term messages through keyset page ids, block and widget instance messages through
 *   keyset pages of the reference graph with one int per host and store view kept for deduplication. A block placed
 *   on every product is bounded in memory; its time stays linear in the number of hosts (TECHNICAL-REFERENCE.md known limits).
 * - A missing entity or store view is a success (its rows/documents are removed or never existed).
 *   InvalidReindexMessageException (unsupported entity type, from AffectedHosts) is permanent; every other exception
 *   propagates unchanged and is retried by ReindexHandler (the work is idempotent).
 * - Never publishes and never saves through repositories or models (that would loop through the after-commit flush
 *   plugin); it holds no per-message state, so the long-running consumer does not grow. One debug line per message,
 *   ids and counts only.
 */
class HostReindexProcessor implements ReindexProcessorInterface
{
    public function __construct(
        private readonly AffectedHosts $affectedHosts,
        private readonly StoreContextProvider $storeContextProvider,
        private readonly HostReindexer $hostReindexer,
        private readonly LoggerInterface $logger,
        private readonly StoreListRefresher $storeListRefresher
    ) {
    }

    public function process(ReindexMessageInterface $message): void
    {
        if ($this->storeListRefresher->refreshIfChanged()) {
            $this->logger->info('store list changed since the consumer started: reinitialised');
        }
        $chunks = $this->affectedHosts->resolve($message); // throws now for an unsupported entity type
        $stores = $this->storesOf($message);
        $hosts = 0;
        $counts = ['page' => 0, 'category' => 0, 'product' => 0];
        if ($stores !== []) {
            foreach ($chunks as $chunk) {
                if ($chunk === []) {
                    continue;
                }
                $hosts += count($chunk);
                foreach ($this->hostReindexer->reindex($chunk, $stores) as $type => $count) {
                    $counts[$type] = ($counts[$type] ?? 0) + $count;
                }
            }
        }
        $this->logger->debug(sprintf(
            'reindexed %s %d (%s): %d host(s), %d store view(s); refreshed %d page(s), %d category(ies), %d product(s)',
            $message->getEntityType(),
            $message->getEntityId(),
            $message->getAction(),
            $hosts,
            count($stores),
            $counts['page'],
            $counts['category'],
            $counts['product']
        ));
    }

    /**
     * @return list<StoreContext>
     */
    private function storesOf(ReindexMessageInterface $message): array
    {
        $storeId = $message->getStoreId();
        if ($storeId === null) {
            $stores = $this->storeContextProvider->all();
        } else {
            // a deleted store view: nothing to write; its index is dropped by the next full rebuild (--sync)
            $store = $this->storeContextProvider->get($storeId);
            $stores = $store === null ? [] : [$store];
        }
        $websiteId = $message->getWebsiteId();
        if ($websiteId === null) {
            return $stores;
        }
        return array_values(array_filter(
            $stores,
            static fn (StoreContext $store): bool => $store->getWebsiteId() === $websiteId
        ));
    }
}
