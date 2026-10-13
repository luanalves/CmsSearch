<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Thedevkitchen\CmsSearch\Model\Db\Tables;
use Thedevkitchen\CmsSearch\Model\Reference\BlockTextResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * Keeps thedevkitchen_cmssearch_product_block_text in line with the blocks each product page publishes in a store
 * view (refresh their reference graph first). A row is written only when the sha1 of the text changed and deleted
 * when the text became empty: every write creates a catalogsearch_fulltext changelog row (etc/mview.xml) and the
 * native cron indexer_update_all_views reindexes the product. Nothing here reindexes a product synchronously.
 */
class ProductBlockTextWriter
{
    public const MAX_TEXT_LENGTH = 65536;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly BlockTextResolver $blockTextResolver
    ) {
    }

    /**
     * @param array<int|string> $productIds
     * @return array{written: int, deleted: int, unchanged: int}
     */
    public function refresh(array $productIds, int $storeId): array
    {
        $result = ['written' => 0, 'deleted' => 0, 'unchanged' => 0];
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || $storeId <= 0) {
            return $result;
        }
        $enrichments = $this->blockTextResolver->forHosts(HostType::PRODUCT, $ids, $storeId);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(Tables::PRODUCT_BLOCK_TEXT);
        $hashes = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['product_id', 'text_hash'])
                ->where('store_id = ?', $storeId)
                ->where('product_id IN (?)', $ids)
        );
        $rows = [];
        $deletes = [];
        foreach ($ids as $id) {
            $text = mb_substr(trim(isset($enrichments[$id]) ? $enrichments[$id]->getText() : ''), 0, self::MAX_TEXT_LENGTH);
            $old = isset($hashes[$id]) ? (string) $hashes[$id] : null;
            if ($text === '') {
                if ($old !== null) {
                    $deletes[] = $id;
                }
                continue;
            }
            $hash = sha1($text);
            if ($hash === $old) {
                $result['unchanged']++;
                continue;
            }
            $rows[] = ['product_id' => $id, 'store_id' => $storeId, 'text' => $text, 'text_hash' => $hash];
        }
        if ($rows !== []) {
            $connection->insertOnDuplicate($table, $rows, ['text', 'text_hash']);
            $result['written'] = count($rows);
        }
        if ($deletes !== []) {
            $connection->delete($table, ['store_id = ?' => $storeId, 'product_id IN (?)' => $deletes]);
            $result['deleted'] = count($deletes);
        }
        return $result;
    }
}
