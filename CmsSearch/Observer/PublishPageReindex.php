<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * cms_page_save_after / cms_page_delete_after: every page write (admin form, REST, grid inline edit, mass actions,
 * Save & Duplicate, Staging version create/update/remove, the StagingApplier's own saves) asks for a page reindex.
 * Both events fire INSIDE the page transaction: the publisher holds the request until COMMIT and merges it with the
 * request of the page-link write of the same save, so one save sends one message and a rolled back save none.
 */
class PublishPageReindex implements ObserverInterface
{
    public function __construct(private readonly ReindexPublisherInterface $publisher)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $page = $event->getData('object') ?? $event->getData('data_object');
        if (!$page instanceof PageInterface) {
            return;
        }
        $pageId = (int) $page->getId();
        if ($pageId <= 0) {
            return;
        }
        $action = $event->getName() === 'cms_page_delete_after'
            ? ReindexMessageInterface::ACTION_DELETE
            : ReindexMessageInterface::ACTION_SAVE;
        $this->publisher->publish(ReindexMessageInterface::ENTITY_PAGE, $pageId, $action);
    }
}
