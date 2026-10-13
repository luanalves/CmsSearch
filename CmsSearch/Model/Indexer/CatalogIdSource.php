<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;

/**
 * Keyset batches of category and product ids for the full rebuild and the CLI (entity_id is stable under Staging; the
 * Staging select renderer keeps one current row per id, DISTINCT guards against several).
 */
class CatalogIdSource
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return list<int> categories that can have a page (level >= 2: below a root), ascending
     */
    public function nextCategoryIds(int $afterId, int $limit): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()->distinct()
                ->from($this->resource->getTableName('catalog_category_entity'), ['entity_id'])
                ->where('entity_id > ?', $afterId)
                ->where('level >= ?', 2)
                ->order('entity_id ASC')
                ->limit(max(1, $limit))
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
