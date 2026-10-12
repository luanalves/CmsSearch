<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PreparedContent;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;

/**
 * Re-reads the current state of a set of CMS pages and writes the result to the index of EVERY given store view: a
 * page visible there is upserted, anything else (missing, disabled, not assigned, unclassified, inactive type or term,
 * other website, shadowed by a store-view page of the same identifier) is deleted by its deterministic id (deleting a
 * missing document is a no-op), so the same call repeated or replayed out of order ends in the same index (TS-30,
 * TS-34) and stale documents of store views the page left are removed.
 *
 * Per batch: one call per reader (no N+1), content extracted at most once per page (lazily, only for a page visible
 * somewhere), one bulk request per store view; memory is bounded by the batch size.
 *
 * Modes: incremental ($targets empty) writes through each store view's alias (created when missing) and deletes;
 * full rebuild ($targets = store id => new version from IndexManager::createIndex()) only adds visible pages, no
 * deletes, no refresh.
 *
 * Failures: an IndexingException of one store view (alias creation or bulk item errors) does not stop the other store
 * views; that store view is skipped for the rest of the call and one IndexingException naming the failed store views
 * is thrown at the end (transient: the handler retries the whole, idempotent, call). Messages carry ids and the
 * already value-free IndexManager text only, never content. Read failures and programming errors propagate as is.
 *
 * Used by the queue processor and by FullReindexer only.
 */
class PageIndexer
{
    public function __construct(
        private readonly PageSnapshotReader $pageReader,
        private readonly PageLinkReader $linkReader,
        private readonly ContentTypeReader $typeReader,
        private readonly ClassificationReader $termReader,
        private readonly ShadowedPageReader $shadowReader,
        private readonly VisibilityChecker $visibilityChecker,
        private readonly DocumentBuilder $documentBuilder,
        private readonly IndexManager $indexManager,
        private readonly int $batchSize = 200
    ) {
    }

    /**
     * @param array<int|string> $pageIds
     * @param list<StoreContext> $stores
     * @param array<int, string> $targets store id => new index version of a full rebuild; empty = incremental
     * @return array{upserted: int, deleted: int}
     * @throws IndexingException when at least one store view could not be written (the others were)
     * @throws \LogicException when a full rebuild does not name exactly one target per store view
     */
    public function reindex(array $pageIds, array $stores, array $targets = []): array
    {
        $result = ['upserted' => 0, 'deleted' => 0];
        $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn (int $id): bool => $id > 0)));
        sort($ids);
        if ($ids === [] || $stores === []) {
            return $result;
        }
        $full = $targets !== [];
        $storeIds = array_map(static fn (StoreContext $store): int => $store->getStoreId(), $stores);
        if ($full) {
            foreach ($storeIds as $storeId) {
                if (!isset($targets[$storeId])) {
                    throw new \LogicException(sprintf('No rebuild target for store view %d.', $storeId));
                }
            }
            $extra = array_diff(array_keys($targets), $storeIds);
            if ($extra !== []) {
                throw new \LogicException(sprintf('A rebuild target names store view %d that is not rebuilt.', reset($extra)));
            }
        }

        /** @var array<int, string> $failures store id => value-free reason */
        $failures = [];
        $writeTargets = [];
        foreach ($stores as $store) {
            $storeId = $store->getStoreId();
            if ($full) {
                $writeTargets[$storeId] = $targets[$storeId];
                continue;
            }
            try {
                $writeTargets[$storeId] = $this->indexManager->ensureAlias($store);
            } catch (IndexingException $exception) {
                $failures[$storeId] = $exception->getMessage();
            }
        }

        foreach (array_chunk($ids, max(1, $this->batchSize)) as $batch) {
            if (count($failures) === count($stores)) {
                break; // nothing left to write to
            }
            $pages = $this->pageReader->read($batch);
            $links = $this->linkReader->read($batch);
            $types = $this->typeReader->read(array_values(array_unique(array_column($links, 'type_id'))));
            $terms = $this->termReader->read(array_values(array_unique(array_column($links, 'term_id'))));
            $shadowed = $this->shadowReader->read($pages, $storeIds);
            /** @var array<int, PreparedContent> $prepared this batch's extracted content, by page id */
            $prepared = [];
            foreach ($stores as $store) {
                $storeId = $store->getStoreId();
                if (isset($failures[$storeId])) {
                    continue;
                }
                $operations = [];
                $upserted = 0;
                $deleted = 0;
                foreach ($batch as $pageId) {
                    $page = $pages[$pageId] ?? null;
                    $link = $links[$pageId] ?? null;
                    $type = $link === null ? null : ($types[$link['type_id']] ?? null);
                    $term = $link === null ? null : ($terms[$link['term_id']] ?? null);
                    $id = DocumentBuilder::documentId($pageId);
                    $visibility = $this->visibilityChecker->check(
                        $page,
                        $link,
                        $type,
                        $term,
                        $store,
                        in_array($storeId, $shadowed[$pageId] ?? [], true)
                    );
                    if ($visibility === VisibilityChecker::VISIBLE) {
                        // check() returned VISIBLE, so page, type and term are set (build() is non-nullable)
                        $prepared[$pageId] ??= $this->documentBuilder->prepare($page);
                        $operations[] = [
                            'action' => 'index',
                            'id' => $id,
                            'document' => $this->documentBuilder->build($page, $type, $term, $store, $prepared[$pageId]),
                        ];
                        $upserted++;
                    } elseif (!$full) {
                        $operations[] = ['action' => 'delete', 'id' => $id];
                        $deleted++;
                    }
                }
                if ($operations === []) {
                    continue;
                }
                try {
                    $this->indexManager->bulk($writeTargets[$storeId], $operations, !$full);
                } catch (IndexingException $exception) {
                    $failures[$storeId] = $exception->getMessage();
                    continue;
                }
                $result['upserted'] += $upserted;
                $result['deleted'] += $deleted;
            }
        }

        if ($failures !== []) {
            ksort($failures);
            throw new IndexingException(sprintf(
                'CMS page indexing failed in %d of %d store view(s); store view %d: %s',
                count($failures),
                count($stores),
                array_key_first($failures),
                reset($failures)
            ));
        }
        return $result;
    }
}
