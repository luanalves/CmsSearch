<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * catalog_product_save_after / _delete_after (inside the product transaction): a product's block text depends on its
 * description, short description, type (widget placements by product type) and websites (it is written per store view
 * of its websites) only, so other saves (price, stock, name...) publish nothing. A new product always asks (widget
 * instances on every product page). Raw-SQL writers: Product\Action (mass "Update attributes", website assignment) is
 * covered by Plugin\Catalog\PublishProductMassActionReindex, imports by PublishProductImportReindex. Staging:
 * ReferenceVersionApplied (the applier's re-save changes no field).
 */
class PublishProductReindex implements ObserverInterface
{
    public const TRACKED = ['description', 'short_description', 'type_id'];

    public function __construct(private readonly ReindexPublisherInterface $publisher)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $product = $event->getData('data_object') ?? $event->getData('product');
        if (!$product instanceof Product || (int) $product->getId() <= 0) {
            return;
        }
        if ($event->getName() === 'catalog_product_delete_after') {
            $this->publisher->publish(ReindexMessageInterface::ENTITY_PRODUCT, (int) $product->getId(), ReindexMessageInterface::ACTION_DELETE);
            return;
        }
        if ($product->getOrigData('entity_id') === null || $this->changed($product) || $this->websitesChanged($product)) {
            $this->publisher->publish(ReindexMessageInterface::ENTITY_PRODUCT, (int) $product->getId(), ReindexMessageInterface::ACTION_SAVE);
        }
    }

    private function changed(Product $product): bool
    {
        foreach (self::TRACKED as $field) {
            if ($product->dataHasChangedFor($field)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Product::beforeSave() keeps the website ids read before the save in origData; getWebsiteIds() gives the saved
     * ones (from the data the caller set, else read again). A lookup failure asks anyway: a redundant reindex is
     * harmless, a missed one is not, and the save must never fail because of it.
     */
    private function websitesChanged(Product $product): bool
    {
        $before = $product->getOrigData('website_ids');
        if (!is_array($before)) {
            return false;
        }
        try {
            $after = $product->getWebsiteIds();
        } catch (\Throwable) {
            return true;
        }
        return !is_array($after) || $this->idSet($before) !== $this->idSet($after);
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private function idSet(array $ids): array
    {
        $set = array_values(array_unique(array_map('intval', $ids)));
        sort($set);
        return $set;
    }
}
