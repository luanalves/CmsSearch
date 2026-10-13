<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\CatalogImportExport\Model\Import\Product as ProductImport;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * Product import writes by raw SQL (no product model event). catalog_product_import_bunch_save_after (after the bunch
 * is written): one product/save per distinct product whose row has a value in a column of the block text
 * (description, short_description, product_websites; additional_attributes are already expanded into the row). Empty
 * cells change nothing in a Magento import. product_type is not a signal: Import\Product::_prepareRowForDb() fills
 * it with the current type of every existing product (an import cannot change it), and a new product without a
 * website has no block text anywhere. catalog_product_import_bunch_delete_after (after its COMMIT): one
 * product/delete per deleted id. One message per product, as for model saves: an import of n such products sends n
 * messages (the same as `thedevkitchen:cmssearch:reindex --entity=product`); a re-import of a full export that fills
 * product_websites asks for every row even when nothing changed.
 */
class PublishProductImportReindex implements ObserverInterface
{
    public const COLUMNS = ['description', 'short_description', 'product_websites'];

    public function __construct(private readonly ReindexPublisherInterface $publisher)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        if ($event->getName() === 'catalog_product_import_bunch_delete_after') {
            $ids = $event->getData('ids_to_delete');
            $this->publishAll(is_array($ids) ? $ids : [], ReindexMessageInterface::ACTION_DELETE);
            return;
        }
        $adapter = $event->getData('adapter');
        $bunch = $event->getData('bunch');
        if (!$adapter instanceof ProductImport || !is_array($bunch)) {
            return;
        }
        $ids = [];
        foreach ($bunch as $row) {
            if (!is_array($row) || !$this->touchesBlockText($row) || !is_scalar($row['sku'] ?? null) || (string) $row['sku'] === '') {
                continue;
            }
            $known = $adapter->getNewSku((string) $row['sku']);
            if (is_array($known) && isset($known['entity_id'])) {
                $ids[] = $known['entity_id'];
            }
        }
        $this->publishAll($ids, ReindexMessageInterface::ACTION_SAVE);
    }

    /**
     * @param array<mixed> $row
     */
    private function touchesBlockText(array $row): bool
    {
        foreach (self::COLUMNS as $column) {
            if (is_scalar($row[$column] ?? null) && trim((string) $row[$column]) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $ids
     */
    private function publishAll(array $ids, string $action): void
    {
        $seen = [];
        foreach ($ids as $value) {
            $id = (int) $value;
            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $this->publisher->publish(ReindexMessageInterface::ENTITY_PRODUCT, $id, $action);
            }
        }
    }
}
