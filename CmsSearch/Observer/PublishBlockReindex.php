<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * cms_block_save_after / cms_block_delete_after (admin, REST, mass actions, Staging version create/update/remove, the
 * StagingApplier's own re-saves): ONE message per block, keyed by block_id (getId(); never the Staging row_id); the
 * consumer expands it to every host that publishes the block. Fired inside the block transaction (EntityManager
 * bridge): the publisher sends after the outermost COMMIT and drops on ROLLBACK.
 */
class PublishBlockReindex implements ObserverInterface
{
    public function __construct(private readonly ReindexPublisherInterface $publisher)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $block = $event->getData('data_object') ?? $event->getData('object');
        if (!$block instanceof BlockInterface || (int) $block->getId() <= 0) {
            return;
        }
        $this->publisher->publish(
            ReindexMessageInterface::ENTITY_BLOCK,
            (int) $block->getId(),
            $event->getName() === 'cms_block_delete_after' ? ReindexMessageInterface::ACTION_DELETE : ReindexMessageInterface::ACTION_SAVE
        );
    }
}
