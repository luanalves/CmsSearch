<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\AdvancedSearch\Model\Adapter\DataMapper\AdditionalFieldsProviderInterface;
use Magento\Framework\App\ResourceConnection;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

/**
 * Adds the text of the blocks published on the product page to the NATIVE product document (field
 * cmssearch_block_text, spec 5.7). The native mapping's dynamic template "string_mapping" maps it as text with
 * copy_to _search, so the native quick search finds it; one query per batch of the native indexer.
 */
class ProductBlockTextProvider implements AdditionalFieldsProviderInterface
{
    public const FIELD = 'cmssearch_block_text';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param array<int|string> $productIds
     * @param int|string $storeId
     * @return array<int, array{cmssearch_block_text: string}>
     */
    public function getFields(array $productIds, $storeId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || (int) $storeId <= 0) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $texts = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(Tables::PRODUCT_BLOCK_TEXT), ['product_id', 'text'])
                ->where('store_id = ?', (int) $storeId)
                ->where('product_id IN (?)', $ids)
        );
        $fields = [];
        foreach ($texts as $productId => $text) {
            if ((string) $text !== '') {
                $fields[(int) $productId] = [self::FIELD => (string) $text];
            }
        }
        return $fields;
    }
}
