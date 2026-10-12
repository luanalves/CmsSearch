<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

/**
 * Type and term of many pages in one query (the link is keyed by page_id and may be stale: VisibilityChecker decides).
 */
class PageLinkReader
{
    /**
     * @param int $chunkSize ids per query: bounds the IN list and the rows held in memory
     */
    public function __construct(private readonly ResourceConnection $resource, private readonly int $chunkSize = 1000)
    {
    }

    /**
     * @param array<int|string> $pageIds
     * @return array<int, array{type_id: int, term_id: int}>
     */
    public function read(array $pageIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(Tables::PAGE_CLASSIFICATION);
        $links = [];
        foreach (array_chunk($ids, max(1, $this->chunkSize)) as $chunk) {
            $rows = $connection->fetchAll(
                $connection->select()->from($table, ['page_id', 'type_id', 'term_id'])->where('page_id IN (?)', $chunk)
            );
            foreach ($rows as $row) {
                $links[(int) $row['page_id']] = ['type_id' => (int) $row['type_id'], 'term_id' => (int) $row['term_id']];
            }
        }
        ksort($links);
        return $links;
    }
}
