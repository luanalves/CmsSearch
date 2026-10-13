<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Thedevkitchen\CmsSearch\Model\Db\Tables;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * Reads and writes thedevkitchen_cmssearch_block_reference. Writes replace all rows of a set of hosts of one type in
 * one store view inside ONE transaction (delete, then chunked inserts), so readers never see a half-written host and
 * a failure leaves the previous rows in place. Writes to this table only: no message, no indexer call.
 */
class ReferenceStore
{
    public const SOURCE_CONTENT = 'content';
    public const SOURCE_WIDGET = 'widget';

    /** Graph rows per keyset page of a host lookup (bounds memory for blocks placed on every product). */
    public const HOST_PAGE_SIZE = 1000;

    /**
     * @param int $insertChunkSize rows per INSERT statement (bounded packet size and placeholders)
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly int $insertChunkSize = 500
    ) {
        if ($insertChunkSize < 1) {
            throw new \InvalidArgumentException('The insert chunk size must be positive.');
        }
    }

    /**
     * @param list<int> $hostIds every refreshed host (hosts without rows lose their old ones)
     * @param list<array{host_id: int, block_kind: string, block_id: int, block_identifier: string, source: string, source_id: int, depth: int, segment_ids: ?string}> $rows
     */
    public function replace(string $hostType, array $hostIds, int $storeId, array $rows): void
    {
        if (!in_array($hostType, HostType::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown host type "%s".', substr($hostType, 0, 40)));
        }
        if ($hostIds === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(Tables::BLOCK_REFERENCE);
        $connection->beginTransaction();
        try {
            $connection->delete($table, ['store_id = ?' => $storeId, 'host_type = ?' => $hostType, 'host_id IN (?)' => $hostIds]);
            foreach (array_chunk($rows, $this->insertChunkSize) as $chunk) {
                // The unique key compares block_identifier with utf8mb4_general_ci: two spellings the collation
                // considers equal (that BlockRef::key() did not fold) are merged, keeping the first row and the
                // smallest depth, instead of a duplicate-key error that would roll back the whole refresh.
                $connection->insertOnDuplicate(
                    $table,
                    array_map(static fn (array $row): array => ['store_id' => $storeId, 'host_type' => $hostType] + $row, $chunk),
                    ['depth' => new Expression('LEAST(`depth`, VALUES(`depth`))')]
                );
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * Hosts that referenced a block at their last refresh, as keyset pages of graph rows (ordered by reference_id,
     * through the (block_kind, block_id) index, then - for a CMS block - through (block_kind, block_identifier) for its
     * current identifier). Rows are not distinct: a host may appear in several rows and pages; the caller deduplicates.
     * Lazy: nothing is read before the pages are iterated.
     *
     * @return iterable<list<array{store_id: int, host_type: string, host_id: int}>>
     */
    public function hostsForBlock(
        string $kind,
        int $blockId,
        string $identifier = '',
        int $pageSize = self::HOST_PAGE_SIZE
    ): iterable {
        if ($blockId <= 0 || !in_array($kind, BlockRef::KINDS, true)) {
            return;
        }
        yield from $this->pagesOf(['block_kind = ?' => $kind, 'block_id = ?' => $blockId], $pageSize);
        if ($identifier !== '') {
            yield from $this->pagesOf(['block_kind = ?' => $kind, 'block_identifier = ?' => $identifier], $pageSize);
        }
    }

    /**
     * @return iterable<list<array{store_id: int, host_type: string, host_id: int}>> pages of the graph rows a widget
     *         instance published (also after it was deleted or moved: the BEFORE side of a widget message)
     */
    public function hostsForSource(string $source, int $sourceId, int $pageSize = self::HOST_PAGE_SIZE): iterable
    {
        // Content rows all have source_id 0: only a real widget instance id identifies a set of hosts.
        if ($source !== self::SOURCE_WIDGET || $sourceId <= 0) {
            return;
        }
        yield from $this->pagesOf(['source = ?' => $source, 'source_id = ?' => $sourceId], $pageSize);
    }

    /**
     * @param list<int> $hostIds
     * @return array<int, list<array{block_kind: string, block_id: int, block_identifier: string, segment_ids: ?string}>>
     */
    public function directRefs(string $hostType, array $hostIds, int $storeId): array
    {
        if ($hostIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName(Tables::BLOCK_REFERENCE),
                    ['host_id', 'block_kind', 'block_id', 'block_identifier', 'segment_ids']
                )
                ->where('store_id = ?', $storeId)
                ->where('host_type = ?', $hostType)
                ->where('host_id IN (?)', $hostIds)
                ->where('depth = ?', 0)
                ->order('reference_id ASC')
        );
        $refs = [];
        foreach ($rows as $row) {
            $refs[(int) $row['host_id']][] = [
                'block_kind' => (string) $row['block_kind'],
                'block_id' => (int) $row['block_id'],
                'block_identifier' => (string) $row['block_identifier'],
                'segment_ids' => $row['segment_ids'] === null ? null : (string) $row['segment_ids'],
            ];
        }
        return $refs;
    }

    /**
     * @param array<string, int|string> $conditions
     * @return \Generator<int, list<array{store_id: int, host_type: string, host_id: int}>>
     */
    private function pagesOf(array $conditions, int $pageSize): \Generator
    {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException('The page size must be positive.');
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(Tables::BLOCK_REFERENCE);
        $after = 0;
        do {
            $select = $connection->select()
                ->from($table, ['reference_id', 'store_id', 'host_type', 'host_id'])
                ->where('reference_id > ?', $after);
            foreach ($conditions as $condition => $value) {
                $select->where($condition, $value);
            }
            $rows = $connection->fetchAll($select->order('reference_id ASC')->limit($pageSize));
            if ($rows === []) {
                return;
            }
            $after = (int) $rows[array_key_last($rows)]['reference_id'];
            yield array_map(
                static fn (array $row): array => [
                    'store_id' => (int) $row['store_id'],
                    'host_type' => (string) $row['host_type'],
                    'host_id' => (int) $row['host_id'],
                ],
                $rows
            );
        } while (count($rows) === $pageSize);
    }
}
