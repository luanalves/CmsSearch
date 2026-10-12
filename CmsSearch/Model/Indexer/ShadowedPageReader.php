<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;

/**
 * Store views in which a page of All Store Views (store 0) is NOT served because another ACTIVE page with the same
 * identifier is assigned to that store view: Magento resolves a URL key with `store_id IN (0, S) ORDER BY store_id
 * DESC LIMIT 1` over active pages (Magento\Cms\Model\ResourceModel\Page::checkIdentifier). Example of this install:
 * `home` of store 0 (page 2) is shadowed in store views 2-7 by their own `home` pages.
 *
 * One query per call (the caller's batch bounds the id list). The identifier comparison is done by the database
 * (same column collation as Magento's lookup), and under Content Staging the select renderer restricts both page
 * aliases to their current version, exactly like the router's lookup.
 */
class ShadowedPageReader
{
    private const STORE_TABLE = 'cms_page_store';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly VersionHistoryInterface $versionHistory
    ) {
    }

    /**
     * @param array<int, PageSnapshot> $pages current snapshots by page id
     * @param array<int|string> $storeIds store views to check
     * @return array<int, list<int>> page id => ascending store view ids where it is shadowed; pages never shadowed are absent
     */
    public function read(array $pages, array $storeIds): array
    {
        $stores = array_values(array_unique(array_filter(array_map('intval', $storeIds), static fn (int $id): bool => $id > 0)));
        sort($stores);
        $candidates = [];
        foreach ($pages as $page) {
            // only an active page of store 0 can lose to a store-specific one (an explicit assignment always wins)
            if ($page->isActive() && in_array(0, $page->getStoreIds(), true)) {
                $candidates[] = $page->getPageId();
            }
        }
        sort($candidates);
        if ($candidates === [] || $stores === []) {
            return [];
        }
        $metadata = $this->metadataPool->getMetadata(PageInterface::class);
        $linkField = $metadata->getLinkField();
        $idField = $metadata->getIdentifierField();
        $pageTable = $this->resource->getTableName($metadata->getEntityTable());
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['p' => $pageTable], [])
            ->join(
                ['q' => $pageTable],
                sprintf('q.identifier = p.identifier AND q.%1$s <> p.%1$s', $idField),
                []
            )
            ->join(
                ['qs' => $this->resource->getTableName(self::STORE_TABLE)],
                sprintf('qs.%1$s = q.%1$s', $linkField),
                []
            )
            ->columns(['page_id' => 'p.' . $idField, 'store_id' => 'qs.store_id'])
            ->where(sprintf('p.%s IN (?)', $idField), $candidates)
            ->where('qs.store_id IN (?)', $stores)
            ->where('q.is_active = ?', 1)
            ->distinct();
        $shadowed = [];
        foreach ($connection->fetchAll($select) as $row) {
            $pageId = (int) $row['page_id'];
            $storeId = (int) $row['store_id'];
            if (in_array($pageId, $candidates, true) && in_array($storeId, $stores, true)) {
                $shadowed[$pageId][$storeId] = $storeId;
            }
        }
        ksort($shadowed);
        return array_map(static function (array $ids): array {
            sort($ids);
            return $ids;
        }, $shadowed);
    }

    /**
     * Pages of All Store Views (store 0) whose URL key one of the given pages shares: whether they are served in a
     * store view depends on these pages, so a change of one of them (activation, store views, deletion of its
     * current version) must reindex them too. One query; at most one store-0 page per URL key (URL keys are unique
     * per store), so the result is bounded by the input. A page already deleted, or whose URL key changed, no longer
     * names its old siblings (they follow at their own next reindex or the next --sync).
     *
     * @param array<int|string> $pageIds
     * @return list<int> ascending ids, the given pages excluded
     */
    public function siblingsOf(array $pageIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn (int $id): bool => $id > 0)));
        sort($ids);
        if ($ids === []) {
            return [];
        }
        // runs before any PageSnapshotReader::read() of the message: reload the Staging version a long-lived consumer
        // has cached, so the Staging select renderer compares the CURRENT rows (read() needs no reset: it always
        // follows a snapshot read, which resets it)
        if ($this->versionHistory instanceof ResetAfterRequestInterface) {
            $this->versionHistory->_resetState();
        }
        $metadata = $this->metadataPool->getMetadata(PageInterface::class);
        $linkField = $metadata->getLinkField();
        $idField = $metadata->getIdentifierField();
        $pageTable = $this->resource->getTableName($metadata->getEntityTable());
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['p' => $pageTable], [])
            ->join(
                ['q' => $pageTable],
                sprintf('q.identifier = p.identifier AND q.%1$s <> p.%1$s', $idField),
                []
            )
            ->join(
                ['qs' => $this->resource->getTableName(self::STORE_TABLE)],
                sprintf('qs.%1$s = q.%1$s', $linkField),
                []
            )
            ->columns(['page_id' => 'q.' . $idField])
            ->where(sprintf('p.%s IN (?)', $idField), $ids)
            ->where('qs.store_id = ?', 0)
            ->distinct();
        $siblings = array_values(array_diff(array_unique(array_map('intval', $connection->fetchCol($select))), $ids));
        sort($siblings);
        return $siblings;
    }

    /**
     * Producer side of the shadow rule (Observer\PublishPageReindex): the ACTIVE pages of All Store Views (store 0)
     * with one of the given URL keys, other than $exceptPageId. Used when a page leaves a URL key (rename or delete),
     * which the consumer can no longer resolve from the page's current row. One query on the indexed identifier.
     * Runs inside the page save/delete transaction, where Staging may have set the version being saved: the version
     * state is deliberately NOT reset here.
     *
     * @param array<string> $identifiers
     * @return list<int> ascending page ids
     */
    public function activeStoreZeroPagesNamed(array $identifiers, int $exceptPageId): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $identifiers), static fn (string $key): bool => $key !== '')));
        sort($keys);
        if ($keys === []) {
            return [];
        }
        $metadata = $this->metadataPool->getMetadata(PageInterface::class);
        $linkField = $metadata->getLinkField();
        $idField = $metadata->getIdentifierField();
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['p' => $this->resource->getTableName($metadata->getEntityTable())], [])
            ->join(
                ['ps' => $this->resource->getTableName(self::STORE_TABLE)],
                sprintf('ps.%1$s = p.%1$s', $linkField),
                []
            )
            ->columns(['page_id' => 'p.' . $idField])
            ->where('p.identifier IN (?)', $keys)
            ->where('ps.store_id = ?', 0)
            ->where('p.is_active = ?', 1)
            ->where(sprintf('p.%s <> ?', $idField), $exceptPageId)
            ->distinct();
        $ids = array_values(array_diff(array_unique(array_map('intval', $connection->fetchCol($select))), [$exceptPageId]));
        sort($ids);
        return $ids;
    }
}
