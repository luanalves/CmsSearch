<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Magento\Framework\App\ResourceConnection;

/**
 * Ids of every category below a category path, whatever their active flag (Category::getAllChildren() keeps only the
 * children active in the category's scope and would miss a child inactive by default but active in a store view).
 * One indexed query on catalog_category_entity.path; DISTINCT because the table is staged (one row per version).
 * Request side (category observer): reads only, inside the caller's transaction, so a just-moved subtree is seen with
 * its new paths.
 */
class CategorySubtreeReader
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return list<int> ascending, the category itself excluded
     */
    public function descendantIds(string $path): array
    {
        if (preg_match('#^\d+(/\d+)*$#', $path) !== 1) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from($this->resource->getTableName('catalog_category_entity'), ['entity_id'])
            ->where('path LIKE ?', $path . '/%')
            ->order('entity_id');
        $ids = array_values(array_unique(array_map('intval', $connection->fetchCol($select))));
        sort($ids);
        return $ids;
    }
}
