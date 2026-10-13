<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Keyset batches of CMS page, category and product ids for the full rebuild and the CLI (entity_id is stable under Staging; the
 * Staging select renderer keeps one current row per id, DISTINCT guards against several).
 */
class CatalogIdSource
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * Pages a store view can show: assigned to it or to All Store Views (store 0). cms_page is a staged table: the
     * Staging select renderer keeps the current version, so a page is selected by its current store assignment. Pages
     * of other store views are never visible in this one; the full rebuild does not need to read them.
     *
     * @return list<int> ascending, distinct page ids greater than $afterId
     */
    public function nextPageIds(int $afterId, int $limit, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $link = $this->metadataPool->getMetadata(PageInterface::class)->getLinkField();
        return array_map('intval', $connection->fetchCol(
            $connection->select()->distinct()
                ->from(['p' => $this->resource->getTableName('cms_page')], ['page_id'])
                ->join(['s' => $this->resource->getTableName('cms_page_store')], sprintf('s.%1$s = p.%1$s', $link), [])
                ->where('s.store_id IN (?)', [0, $storeId])
                ->where('p.page_id > ?', $afterId)
                ->order('p.page_id ASC')
                ->limit(max(1, $limit))
        ));
    }

    /**
     * @param int|null $storeId when set, only the categories below the root category of that store view's group
     *     (path '1/<root>/...'); a store view without a group root yields none
     * @return list<int> categories that can have a page (level >= 2: below a root), ascending
     */
    public function nextCategoryIds(int $afterId, int $limit, ?int $storeId = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()->distinct()
            ->from($this->resource->getTableName('catalog_category_entity'), ['entity_id'])
            ->where('entity_id > ?', $afterId)
            ->where('level >= ?', 2);
        if ($storeId !== null) {
            $select->where(sprintf(
                "path LIKE CONCAT('1/', (SELECT g.root_category_id FROM %s g INNER JOIN %s s ON s.group_id = g.group_id WHERE s.store_id = %d), '/%%')",
                $this->resource->getTableName('store_group'),
                $this->resource->getTableName('store'),
                $storeId
            ));
        }
        return array_map('intval', $connection->fetchCol(
            $select->order('entity_id ASC')->limit(max(1, $limit))
        ));
    }

    /**
     * @return list<int> ascending
     */
    public function nextProductIds(int $afterId, int $limit): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()->distinct()
                ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
                ->where('entity_id > ?', $afterId)
                ->order('entity_id ASC')
                ->limit(max(1, $limit))
        ));
    }
}
