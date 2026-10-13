<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
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
                $connection->insertMultiple(
                    $table,
                    array_map(static fn (array $row): array => ['store_id' => $storeId, 'host_type' => $hostType] + $row, $chunk)
                );
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * @return list<array{store_id: int, host_type: string, host_id: int}>
     */
    public function hostsForBlock(string $kind, int $blockId, string $identifier = ''): array
    {
        if ($blockId <= 0 || !in_array($kind, BlockRef::KINDS, true)) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $match = $connection->quoteInto('block_id = ?', $blockId);
        if ($identifier !== '') {
            $match .= ' OR ' . $connection->quoteInto('block_identifier = ?', $identifier);
        }
        return $this->hosts(
            $connection->select()->distinct()
                ->from($this->resource->getTableName(Tables::BLOCK_REFERENCE), ['store_id', 'host_type', 'host_id'])
                ->where('block_kind = ?', $kind)
                ->where($match)
                ->order(['store_id ASC', 'host_type ASC', 'host_id ASC'])
        );
    }

    /**
     * @return list<array{store_id: int, host_type: string, host_id: int}> hosts a widget instance published on
     */
    public function hostsForSource(string $source, int $sourceId): array
    {
        // Content rows all have source_id 0: only a real widget instance id identifies a set of hosts.
        if ($source !== self::SOURCE_WIDGET || $sourceId <= 0) {
            return [];
        }
        $connection = $this->resource->getConnection();
        return $this->hosts(
            $connection->select()->distinct()
                ->from($this->resource->getTableName(Tables::BLOCK_REFERENCE), ['store_id', 'host_type', 'host_id'])
                ->where('source = ?', $source)
                ->where('source_id = ?', $sourceId)
                ->order(['store_id ASC', 'host_type ASC', 'host_id ASC'])
        );
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
     * @return list<array{store_id: int, host_type: string, host_id: int}>
     */
    private function hosts(Select $select): array
    {
        return array_map(
            static fn (array $row): array => [
                'store_id' => (int) $row['store_id'],
                'host_type' => (string) $row['host_type'],
                'host_id' => (int) $row['host_id'],
            ],
            $this->resource->getConnection()->fetchAll($select)
        );
    }
}
