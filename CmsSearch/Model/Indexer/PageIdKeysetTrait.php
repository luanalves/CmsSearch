<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

/**
 * Keyset pagination over cms_page.page_id for the reindex command (the only user; the full rebuild uses
 * CatalogIdSource::nextPageIds, which also filters by store view).
 * The using class provides `$this->resource` (ResourceConnection) and `$this->batchSize` (int).
 */
trait PageIdKeysetTrait
{
    /**
     * @return int[] next batch of distinct page ids (keyset pagination; page_id is stable under Staging)
     */
    private function nextPageIds(int $afterId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from($this->resource->getTableName('cms_page'), 'page_id')
            ->where('page_id > ?', $afterId)
            ->order('page_id ASC')
            ->limit($this->batchSize);
        return array_map('intval', $connection->fetchCol($select));
    }
}
