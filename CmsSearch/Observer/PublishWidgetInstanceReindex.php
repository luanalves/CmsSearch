<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Widget\Model\Widget\Instance;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Reference\DirectiveParser;
use Thedevkitchen\CmsSearch\Model\Reference\WidgetInstanceResolver;

/**
 * widget_widget_instance_save_after / _delete_after (model events; widget instances have no EntityManager) for CMS
 * Block and Dynamic Blocks widgets only (other widgets never publish block text, TS-19). The page groups are written
 * in the same transaction; the consumer refreshes the hosts of the old placement (graph) and of the new one.
 */
class PublishWidgetInstanceReindex implements ObserverInterface
{
    public function __construct(private readonly ReindexPublisherInterface $publisher)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $instance = $event->getData('data_object') ?? $event->getData('object');
        if (!$instance instanceof Instance || (int) $instance->getId() <= 0) {
            return;
        }
        $type = DirectiveParser::normalizeClass((string) $instance->getData('instance_type'));
        if (!in_array($type, WidgetInstanceResolver::SUPPORTED_TYPES, true)) {
            return;
        }
        $this->publisher->publish(
            ReindexMessageInterface::ENTITY_WIDGET_INSTANCE,
            (int) $instance->getId(),
            $event->getName() === 'widget_widget_instance_delete_after' ? ReindexMessageInterface::ACTION_DELETE : ReindexMessageInterface::ACTION_SAVE
        );
    }
}
