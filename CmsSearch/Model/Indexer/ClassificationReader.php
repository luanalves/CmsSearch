<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\Db\Tables;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\TermState;

/**
 * Batch ancestor reader (lessons, PLAN 4 CONSTRAINTS): terms and the active flag of every ancestor in exactly two
 * queries, whatever the number of terms (no N+1). Only a set larger than $chunkSize ids is split into bounded chunks
 * per stage (memory and IN-list bound): still a constant number of queries per chunk, never one per term.
 */
class ClassificationReader
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly TreeBuilder $treeBuilder,
        private readonly int $chunkSize = 1000
    ) {
    }

    /**
     * @param array<int|string> $termIds
     * @return array<int, TermState> ordered by term id; unknown ids are absent
     */
    public function read(array $termIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $termIds), static fn (int $id): bool => $id > 0)));
        sort($ids);
        if ($ids === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(Tables::CLASSIFICATION_TERM);
        $terms = [];
        $pathIds = [];
        foreach (array_chunk($ids, max(1, $this->chunkSize)) as $chunk) {
            $rows = $connection->fetchAll(
                $connection->select()->from($table, ['term_id', 'website_id', 'path'])->where('term_id IN (?)', $chunk)
            );
            foreach ($rows as $row) {
                $ancestors = $this->treeBuilder->ancestorIds((string) $row['path']);
                $terms[(int) $row['term_id']] = [(int) $row['website_id'], (string) $row['path'], $ancestors];
                foreach ($ancestors as $ancestorId) {
                    $pathIds[$ancestorId] = true;
                }
            }
        }
        if ($terms === []) {
            return [];
        }
        $pathIds = array_keys($pathIds);
        sort($pathIds);
        $active = [];
        foreach (array_chunk($pathIds, max(1, $this->chunkSize)) as $chunk) {
            $flags = $connection->fetchAll(
                $connection->select()->from($table, ['term_id', 'is_active'])->where('term_id IN (?)', $chunk)
            );
            foreach ($flags as $row) {
                $active[(int) $row['term_id']] = (bool) (int) $row['is_active'];
            }
        }
        $states = [];
        foreach ($terms as $termId => [$websiteId, $path, $ancestors]) {
            // a path that does not end with the term itself is corrupt: never index under it
            $effective = $ancestors !== [] && end($ancestors) === $termId;
            foreach ($ancestors as $ancestorId) {
                $effective = $effective && ($active[$ancestorId] ?? false);
            }
            $states[$termId] = new TermState($termId, $websiteId, $path, $ancestors, $effective);
        }
        ksort($states);
        return $states;
    }
}
