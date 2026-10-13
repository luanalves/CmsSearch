<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Banner\Model\Banner;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * magento_banner_save_after / magento_banner_delete_after: one message per Dynamic Block (content, enabled flag,
 * customer segments and rule links are saved in the same transaction; the consumer reads them after COMMIT). Dynamic
 * Blocks are not staged.
 */
class PublishDynamicBlockReindex implements ObserverInterface
{
    public function __construct(private readonly ReindexPublisherInterface $publisher)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $banner = $event->getData('data_object') ?? $event->getData('banner');
        if (!$banner instanceof Banner || (int) $banner->getId() <= 0) {
            return;
        }
        $this->publisher->publish(
            ReindexMessageInterface::ENTITY_DYNAMIC_BLOCK,
            (int) $banner->getId(),
            $event->getName() === 'magento_banner_delete_after' ? ReindexMessageInterface::ACTION_DELETE : ReindexMessageInterface::ACTION_SAVE
        );
    }
}
