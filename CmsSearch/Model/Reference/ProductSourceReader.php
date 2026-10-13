<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostFacts;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSource;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * Description and short description (the store view's value over the default) and type of the products assigned to
 * the store view's website, in exactly 2 queries per chunk of ids. catalog_product_entity is staged: the Staging
 * select renderer keeps only the current version, whose link field (row_id under CatalogStaging) keys the values;
 * the Staging version cached in VersionHistory is reloaded first (long-lived consumer). Status and visibility are
 * left to the native product index.
 */
class ProductSourceReader
{
    public const ENTITY = 'catalog_product';
    public const ATTRIBUTES = ['description', 'short_description'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly VersionHistoryInterface $versionHistory,
        private readonly int $chunkSize = 500
    ) {
    }

    /**
     * @param array<int|string> $productIds
     * @return array<int, HostSource> ascending ids; missing products and products of other websites are absent
     */
    public function read(array $productIds, int $storeId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        sort($ids);
        try {
            $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (NoSuchEntityException) {
            return [];
        }
        if ($this->versionHistory instanceof ResetAfterRequestInterface) {
            $this->versionHistory->_resetState();
        }
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $attributeIds = array_map(
            fn (string $code): int => (int) $this->eavConfig->getAttribute(self::ENTITY, $code)->getAttributeId(),
            self::ATTRIBUTES
        );
        $sources = [];
        foreach (array_chunk($ids, max(1, $this->chunkSize)) as $chunk) {
            $sources += $this->readChunk($chunk, $storeId, $websiteId, $linkField, $attributeIds);
        }
        ksort($sources);
        return $sources;
    }

    /**
     * @param list<int> $ids
     * @param list<int> $attributeIds in ATTRIBUTES order
     * @return array<int, HostSource>
     */
    private function readChunk(array $ids, int $storeId, int $websiteId, string $linkField, array $attributeIds): array
    {
        $connection = $this->resource->getConnection();
        $columns = array_values(array_unique(['entity_id', $linkField, 'type_id']));
        $entities = $connection->fetchAll(
            $connection->select()
                ->from(['e' => $this->resource->getTableName('catalog_product_entity')], $columns)
                ->join(['w' => $this->resource->getTableName('catalog_product_website')], 'w.product_id = e.entity_id', [])
                ->where('e.entity_id IN (?)', $ids)
                ->where('w.website_id = ?', $websiteId)
        );
        if ($entities === []) {
            return [];
        }
        $byLink = [];
        foreach ($entities as $row) {
            $byLink[(int) $row[$linkField]] = (int) $row['entity_id'];
        }
        $values = [];
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_entity_text'), [$linkField, 'attribute_id', 'store_id', 'value'])
                ->where($linkField . ' IN (?)', array_keys($byLink))
                ->where('attribute_id IN (?)', $attributeIds)
                ->where('store_id IN (?)', [0, $storeId])
                ->order('store_id ASC')
        );
        foreach ($rows as $row) {
            $entityId = $byLink[(int) $row[$linkField]] ?? null;
            if ($entityId !== null) {
                // the store view's row wins over the default row whatever order the rows arrive in
                $scope = (int) $row['store_id'] === 0 ? 'default' : 'store';
                $values[$entityId][(int) $row['attribute_id']][$scope] = (string) $row['value'];
            }
        }
        $sources = [];
        foreach ($entities as $row) {
            $id = (int) $row['entity_id'];
            $contents = [];
            foreach ($attributeIds as $attributeId) {
                $scopes = $values[$id][$attributeId] ?? [];
                $content = $scopes['store'] ?? $scopes['default'] ?? '';
                if (trim($content) !== '') {
                    $contents[] = $content;
                }
            }
            $sources[$id] = new HostSource(new HostFacts(HostType::PRODUCT, $id, '', (string) $row['type_id']), $contents);
        }
        return $sources;
    }
}
