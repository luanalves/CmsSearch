<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\Lock\LockManagerInterface;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReferenceResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * Blocking full rebuild for operators (bin/magento thedevkitchen:cmssearch:reindex --sync; never reachable from a web
 * request). Per store view, under a per-store lock (a second rebuild of the same store view fails fast instead of
 * deleting the first one's unfinished version at its swap):
 *   1. a new index version is created with the current strict mapping (IndexManager::createIndex);
 *   2. every page id assigned to the store view or to All Store Views is streamed in keyset batches
 *      (CatalogIdSource::nextPageIds; pages of other store views cannot be visible here) through PageIndexer in full mode (visible pages only, written
 *      through the version's build alias, no refresh per request), then every category id below the store view's root category through
 *      CategoryIndexer the same way (Plan 6: category documents share the index, so the alias switch would drop them
 *      otherwise); the reference graph of every page and category batch is refreshed before it is indexed;
 *   3. the version is refreshed ONCE (REFRESH DECISION), then the alias is moved to it in one atomic _aliases call
 *      (zero downtime: searches read the alias) and the store's other versions are deleted.
 * A failure in 1-3 (pages, categories or their graph) drops the new version and leaves the alias where it was.
 * switchAlias() throws only when the alias did NOT move, so the drop in the catch can never hit the live index; after
 * a successful switch nothing is dropped
 * (old versions that could not be deleted are returned as "leftovers"; the next rebuild removes them).
 * Without a store id every store view is rebuilt in turn, then the indices of deleted store views are dropped.
 *
 * Consistency trade-off (accepted, D10): messages processed by the consumer WHILE a store view is being rebuilt land in
 * the previous version and are lost at the switch (and a page deleted during the build may keep its document). Run
 * --sync with the consumer stopped, or publish the pages again afterwards (reindex --entity=page without --sync).
 */
class FullReindexer
{
    public const LOCK_PREFIX = 'thedevkitchen_cmssearch_rebuild_';

    public function __construct(
        private readonly StoreContextProvider $storeContextProvider,
        private readonly PageIndexer $pageIndexer,
        private readonly IndexManager $indexManager,
        private readonly LockManagerInterface $lockManager,
        private readonly BlockReferenceResolver $referenceResolver, // Plan 6
        private readonly CategoryIndexer $categoryIndexer, // Plan 6
        private readonly CatalogIdSource $catalogIdSource, // Plan 6
        private readonly int $batchSize = 500
    ) {
    }

    /**
     * @param int|null $storeId one store view; null = all of them, and the indices of deleted store views are dropped
     * @param (callable(string): void)|null $progress receives one line per step (verbose CLI output)
     * @return array{
     *     stores: list<array{store_id: int, index: string, documents: int, categories: int, removed: list<string>, leftovers: list<string>}>,
     *     orphans: list<string>
     * }
     * @throws IndexingException
     */
    public function rebuild(?int $storeId = null, ?callable $progress = null): array
    {
        if ($storeId !== null) {
            $store = $this->storeContextProvider->get($storeId);
            if ($store === null) {
                throw new IndexingException(sprintf('Store view %d does not exist.', $storeId));
            }
            $stores = [$store];
        } else {
            $stores = $this->storeContextProvider->all();
        }
        $result = ['stores' => [], 'orphans' => []];
        foreach ($stores as $store) {
            try {
                $result['stores'][] = $this->rebuildLocked($store, $progress);
            } catch (\Throwable $exception) {
                if ($result['stores'] === []) {
                    throw $exception;
                }
                throw new IndexingException(sprintf(
                    '%s (store view(s) already rebuilt: %s)',
                    $exception->getMessage(),
                    implode(', ', array_column($result['stores'], 'store_id'))
                ), 0, $exception);
            }
        }
        if ($storeId === null) {
            $result['orphans'] = $this->indexManager->dropOrphans();
        }
        return $result;
    }

    /**
     * @return array{store_id: int, index: string, documents: int, categories: int, removed: list<string>, leftovers: list<string>}
     * @throws IndexingException
     */
    private function rebuildLocked(StoreContext $store, ?callable $progress): array
    {
        $lock = self::LOCK_PREFIX . $store->getStoreId();
        if (!$this->lockManager->lock($lock, 0)) {
            throw new IndexingException(sprintf(
                'Another full rebuild of store view %d is running; nothing was changed. Try again when it has finished.',
                $store->getStoreId()
            ));
        }
        try {
            return $this->rebuildStore($store, $progress);
        } finally {
            $this->lockManager->unlock($lock);
        }
    }

    /**
     * @return array{store_id: int, index: string, documents: int, categories: int, removed: list<string>, leftovers: list<string>}
     * @throws IndexingException
     */
    private function rebuildStore(StoreContext $store, ?callable $progress): array
    {
        $storeId = $store->getStoreId();
        try {
            $index = $this->indexManager->createIndex($store);
        } catch (IndexingException $exception) {
            throw new IndexingException(
                sprintf('Rebuild of store view %d failed: %s', $storeId, $exception->getMessage()),
                0,
                $exception
            );
        }
        $this->report($progress, sprintf('Store %d: building %s.', $storeId, $index));
        try {
            $documents = 0;
            $read = 0;
            $lastId = 0;
            do {
                $pageIds = $this->catalogIdSource->nextPageIds($lastId, $this->batchSize, $storeId);
                if ($pageIds !== []) {
                    $this->referenceResolver->refreshForHosts(HostType::PAGE, $pageIds, $storeId);
                    $documents += $this->pageIndexer->reindex($pageIds, [$store], [$storeId => $index])['upserted'];
                    $read += count($pageIds);
                    $lastId = (int) end($pageIds);
                    $this->report($progress, sprintf(
                        'Store %d: %d page(s) read, %d document(s) written (last page id %d).',
                        $storeId,
                        $read,
                        $documents,
                        $lastId
                    ));
                }
            } while (count($pageIds) === $this->batchSize);
            $categories = 0;
            $read = 0;
            $lastId = 0;
            do {
                $categoryIds = $this->catalogIdSource->nextCategoryIds($lastId, $this->batchSize, $storeId);
                if ($categoryIds !== []) {
                    $this->referenceResolver->refreshForHosts(HostType::CATEGORY, $categoryIds, $storeId);
                    $categories += $this->categoryIndexer->reindex($categoryIds, [$store], [$storeId => $index])['upserted'];
                    $read += count($categoryIds);
                    $lastId = (int) end($categoryIds);
                    $this->report($progress, sprintf(
                        'Store %d: %d categor(ies) read, %d category document(s) written (last category id %d).',
                        $storeId,
                        $read,
                        $categories,
                        $lastId
                    ));
                }
            } while (count($categoryIds) === $this->batchSize);
            $this->indexManager->refresh($index);
            $this->report($progress, sprintf('Store %d: %s refreshed; switching the alias.', $storeId, $index));
            // throws only when the alias did not move: the catch below can never drop the live index
            $switched = $this->indexManager->switchAlias($store, $index);
        } catch (\Throwable $exception) {
            try {
                $this->indexManager->dropIndex($index);
            } catch (\Throwable) {
                // keep the original error; the unfinished version is removed by the next successful rebuild
            }
            throw new IndexingException(
                sprintf('Rebuild of store view %d failed: %s', $storeId, $exception->getMessage()),
                0,
                $exception
            );
        }
        return [
            'store_id' => $storeId,
            'index' => $index,
            'documents' => $documents,
            'categories' => $categories,
            'removed' => $switched['deleted'],
            'leftovers' => $switched['leftovers'],
        ];
    }

    private function report(?callable $progress, string $line): void
    {
        if ($progress !== null) {
            $progress($line);
        }
    }
}
