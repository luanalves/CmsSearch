<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Thedevkitchen\CmsSearch\Model\Db\Tables;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\ContentTypeState;

class ContentTypeReader
{
    /**
     * @param int $chunkSize ids per query: bounds the IN list and the rows held in memory
     */
    public function __construct(private readonly ResourceConnection $resource, private readonly int $chunkSize = 1000)
    {
    }

    /**
     * @param array<int|string> $typeIds
     * @return array<int, ContentTypeState> ordered by type id; unknown ids are absent
     */
    public function read(array $typeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $typeIds), static fn (int $id): bool => $id > 0)));
        sort($ids);
        if ($ids === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(Tables::CONTENT_TYPE);
        $types = [];
        foreach (array_chunk($ids, max(1, $this->chunkSize)) as $chunk) {
            $rows = $connection->fetchAll(
                $connection->select()->from($table, ['type_id', 'code', 'is_active'])->where('type_id IN (?)', $chunk)
            );
            foreach ($rows as $row) {
                $types[(int) $row['type_id']] = new ContentTypeState(
                    (int) $row['type_id'],
                    (string) $row['code'],
                    (bool) (int) $row['is_active']
                );
            }
        }
        ksort($types);
        return $types;
    }
}
