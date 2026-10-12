<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\AffectedPages;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\PageIndexer;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;

/**
 * The reindex work of the consumer thedevkitchen.cmssearch.reindex (Plan 4): re-reads the current state of the pages a
 * message may affect and upserts/deletes their documents in every concerned store view (PageIndexer, incremental).
 *
 * - Pages: AffectedPages (page = itself; content type = its pages; classification term = the pages of its CURRENT
 *   subtree), streamed chunk by chunk (memory bounded by the chunk size, never collected first).
 * - Store views: the message store view (set only by the reindex command) or every store view, restricted to the
 *   message website when it has one (terms: their pages are only visible in the store views of that website).
 * - A page message also reindexes the page of All Store Views (store 0) that shares its URL key: whether that page is
 *   served in a store view depends on the store-view page (ShadowedPageReader). One query per chunk, bounded by the
 *   unique URL key per store. Types and terms never change which page wins a URL key, so they skip the lookup.
 * - A page, type, term or store view that no longer exists is a success (its documents are removed or never existed).
 *   InvalidReindexMessageException (unsupported entity type, from AffectedPages) is permanent; every other exception
 *   propagates unchanged and is retried by ReindexHandler (the work is idempotent).
 * - Read-only on the database and never publishes: it holds no per-message state (the readers release what they
 *   load), so the long-running consumer does not grow. One debug line per message, ids and counts only.
 */
class PageReindexProcessor implements ReindexProcessorInterface
{
    public function __construct(
        private readonly AffectedPages $affectedPages,
        private readonly StoreContextProvider $storeContextProvider,
        private readonly PageIndexer $pageIndexer,
        private readonly ShadowedPageReader $shadowedPageReader,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(ReindexMessageInterface $message): void
    {
        $chunks = $this->affectedPages->resolve($message); // throws now for an unsupported entity type
        $stores = $this->storesOf($message);
        $pages = 0;
        $result = ['upserted' => 0, 'deleted' => 0];
        if ($stores !== []) {
            $isPage = $message->getEntityType() === ReindexMessageInterface::ENTITY_PAGE;
            foreach ($chunks as $pageIds) {
                if ($isPage) {
                    $pageIds = array_values(array_unique(array_merge(
                        $pageIds,
                        $this->shadowedPageReader->siblingsOf($pageIds)
                    )));
                    sort($pageIds);
                }
                $pages += count($pageIds);
                $written = $this->pageIndexer->reindex($pageIds, $stores);
                $result['upserted'] += $written['upserted'];
                $result['deleted'] += $written['deleted'];
            }
        }
        $this->logger->debug(sprintf(
            'reindexed %s %d (%s): %d page(s), %d store view(s), %d upserted, %d deleted',
            $message->getEntityType(),
            $message->getEntityId(),
            $message->getAction(),
            $pages,
            count($stores),
            $result['upserted'],
            $result['deleted']
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
