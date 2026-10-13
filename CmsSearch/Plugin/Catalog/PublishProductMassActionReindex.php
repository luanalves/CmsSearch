<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Plugin\Catalog;

use Magento\Catalog\Model\Product\Action;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * Product\Action writes by raw SQL without product model events: the admin mass "Update attributes" (async consumers
 * product_action_attribute.update and product_action_attribute.website.update, Magento's bulk of at most 100 products
 * per operation) and any other caller. After the write (its own transaction has committed, or the publisher holds the
 * requests until the caller's outermost COMMIT): one product/save per distinct product when a description or short
 * description was written, and for every website (un)assignment; other attributes (price, status...) publish nothing.
 */
class PublishProductMassActionReindex
{
    /** Attributes of the product block text a mass update can write (type_id is not an attribute). */
    public const TRACKED = ['description', 'short_description'];

    public function __construct(private readonly ReindexPublisherInterface $publisher)
    {
    }

    /**
     * @param mixed $result
     * @param mixed $productIds
     * @param mixed $attrData
     * @param mixed $storeId
     * @return mixed
     */
    public function afterUpdateAttributes(Action $subject, $result, $productIds, $attrData, $storeId)
    {
        if (is_array($attrData) && array_intersect(array_map('strval', array_keys($attrData)), self::TRACKED) !== []) {
            $this->publishAll($productIds);
        }
        return $result;
    }

    /**
     * @param mixed $result
     * @param mixed $productIds
     * @param mixed $websiteIds
     * @param mixed $type
     * @return mixed
     */
    public function afterUpdateWebsites(Action $subject, $result, $productIds, $websiteIds, $type)
    {
        $this->publishAll($productIds);
        return $result;
    }

    /**
     * @param mixed $productIds
     */
    private function publishAll($productIds): void
    {
        if (!is_array($productIds)) {
            return;
        }
        $seen = [];
        foreach ($productIds as $value) {
            $id = (int) $value;
            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $this->publisher->publish(ReindexMessageInterface::ENTITY_PRODUCT, $id, ReindexMessageInterface::ACTION_SAVE);
            }
        }
    }
}
