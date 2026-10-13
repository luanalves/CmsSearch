<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\CategorySnapshot;

/**
 * Categories of one store view in exactly 5 queries per chunk of ids: the entity rows (catalog_category_entity is
 * staged: the Staging select renderer keeps only the current version, whose link field - row_id under CatalogStaging,
 * entity_id without it - keys the values), one query per value backend (the store view's value over the default) and
 * the url_rewrite request paths. The Staging version cached in VersionHistory is reloaded first (long-lived consumer).
 */
class CategorySnapshotReader
{
    public const ENTITY = 'catalog_category';
    /** attribute code => backend table suffix (Task 6 Step 1: eav_attribute.backend_type of this install) */
    public const ATTRIBUTES = [
        'name' => 'varchar',
        'display_mode' => 'varchar',
        'description' => 'text',
        'landing_page' => 'int',
        'is_anchor' => 'int',
        'is_active' => 'int',
    ];
    private const BACKENDS = ['varchar', 'text', 'int'];
    private const DEFAULT_DISPLAY_MODE = 'PRODUCTS';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig,
        private readonly VersionHistoryInterface $versionHistory,
        private readonly int $chunkSize = 500
    ) {
    }

    /**
     * @param array<int|string> $categoryIds
     * @return array<int, CategorySnapshot> ascending ids; missing categories are absent
     */
    public function read(array $categoryIds, int $storeId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        sort($ids);
        if ($this->versionHistory instanceof ResetAfterRequestInterface) {
            $this->versionHistory->_resetState();
        }
        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
        $snapshots = [];
        foreach (array_chunk($ids, max(1, $this->chunkSize)) as $chunk) {
            $snapshots += $this->readChunk($chunk, $storeId, $linkField);
        }
        ksort($snapshots);
        return $snapshots;
    }

    /**
     * @param list<int> $ids
     * @return array<int, CategorySnapshot>
     */
    private function readChunk(array $ids, int $storeId, string $linkField): array
    {
        $connection = $this->resource->getConnection();
        $entities = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName('catalog_category_entity'),
                    array_values(array_unique(['entity_id', $linkField, 'path', 'level', 'updated_at']))
                )
                ->where('entity_id IN (?)', $ids)
        );
        if ($entities === []) {
            return [];
        }
        $byLink = [];
        foreach ($entities as $row) {
            $byLink[(int) $row[$linkField]] = (int) $row['entity_id'];
        }
        $codes = [];
        foreach (array_keys(self::ATTRIBUTES) as $code) {
            $codes[(int) $this->eavConfig->getAttribute(self::ENTITY, $code)->getAttributeId()] = $code;
        }
        $values = [];
        foreach (self::BACKENDS as $backend) {
            $attributeIds = array_keys(array_filter($codes, static fn (string $code): bool => self::ATTRIBUTES[$code] === $backend));
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(
                        $this->resource->getTableName('catalog_category_entity_' . $backend),
                        [$linkField, 'attribute_id', 'store_id', 'value']
                    )
                    ->where($linkField . ' IN (?)', array_keys($byLink))
                    ->where('attribute_id IN (?)', $attributeIds)
                    ->where('store_id IN (?)', [0, $storeId])
                    ->order('store_id ASC')
            );
            foreach ($rows as $row) {
                $entityId = $byLink[(int) $row[$linkField]] ?? null;
                $code = $codes[(int) $row['attribute_id']] ?? null;
                if ($entityId === null || $code === null) {
                    continue;
                }
                // the store view's row wins over the default row whatever order the rows arrive in
                $scope = (int) $row['store_id'] === 0 ? 'default' : 'store';
                $values[$entityId][$code][$scope] = $row['value'] === null ? null : (string) $row['value'];
            }
        }
        $paths = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('url_rewrite'), ['entity_id', 'request_path'])
                ->where('entity_type = ?', 'category')
                ->where('entity_id IN (?)', $ids)
                ->where('store_id = ?', $storeId)
                ->where('redirect_type = ?', 0)
                ->where('metadata IS NULL')
        );
        $snapshots = [];
        foreach ($entities as $row) {
            $id = (int) $row['entity_id'];
            $value = static function (string $code) use ($values, $id): ?string {
                $scopes = $values[$id][$code] ?? [];
                return array_key_exists('store', $scopes) ? $scopes['store'] : ($scopes['default'] ?? null);
            };
            $mode = (string) $value('display_mode');
            $snapshots[$id] = new CategorySnapshot(
                $id,
                (string) $row['path'],
                (int) $row['level'],
                (string) $value('name'),
                (string) $value('description'),
                (int) $value('landing_page'),
                $mode === '' ? self::DEFAULT_DISPLAY_MODE : $mode,
                (int) $value('is_anchor') === 1,
                (int) $value('is_active') === 1,
                isset($paths[$id]) && (string) $paths[$id] !== '' ? (string) $paths[$id] : null,
                $row['updated_at'] === null || $row['updated_at'] === '' ? null : (string) $row['updated_at']
            );
        }
        return $snapshots;
    }
}
