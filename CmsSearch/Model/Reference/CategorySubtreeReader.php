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

    /**
     * Ids of the given categories plus every descendant of each (whatever the active flag): the set the catalog
     * permission indexer really reindexes for them (Category\Action\Rows::removeObsoleteIndexData() walks the subtree).
     * One query for the paths, then one descendantIds() per path that is not already under another given path.
     *
     * @param list<int> $ids
     * @return list<int> ascending, distinct
     */
    public function withDescendants(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from($this->resource->getTableName('catalog_category_entity'), ['path'])
            ->where('entity_id IN (?)', $ids);
        $paths = array_values(array_unique(array_map('strval', $connection->fetchCol($select))));
        $all = $ids;
        foreach ($paths as $path) {
            foreach ($paths as $other) {
                if ($other !== $path && str_starts_with($path, $other . '/')) {
                    continue 2; // covered by the walk of its ancestor
                }
            }
            $all = array_merge($all, $this->descendantIds($path));
        }
        $all = array_values(array_unique($all));
        sort($all);
        return $all;
    }
}
