<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;

/**
 * cms_page_save_after / cms_page_delete_after: every page write (admin form, REST, grid inline edit, mass actions,
 * Save & Duplicate, Staging version create/update/remove, the StagingApplier's own saves) asks for a page reindex.
 * Both events fire INSIDE the page transaction: the publisher holds the request until COMMIT and merges it with the
 * request of the page-link write of the same save, so one save sends one message and a rolled back save none.
 *
 * Shadow rule: a page that LEAVES a URL key (renamed away from it, or deleted) may un-shadow the active store-0 page of
 * that key in its store views; the consumer cannot find it any more from the page's current row, so page/save is also
 * asked for those store-0 pages (one indexed query, only on a rename or a delete; same buffer, deduplicated per
 * request). A lookup failure is logged and never breaks the page write.
 */
class PublishPageReindex implements ObserverInterface
{
    public function __construct(
        private readonly ReindexPublisherInterface $publisher,
        private readonly ShadowedPageReader $shadowedPageReader,
        private readonly LoggerInterface $logger
    ) {
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
        $isDelete = $event->getName() === 'cms_page_delete_after';
        $action = $isDelete ? ReindexMessageInterface::ACTION_DELETE : ReindexMessageInterface::ACTION_SAVE;
        $this->publisher->publish(ReindexMessageInterface::ENTITY_PAGE, $pageId, $action);

        $current = (string) $page->getIdentifier();
        $left = $isDelete ? $current : ($page instanceof AbstractModel ? (string) $page->getOrigData('identifier') : '');
        if ($left === '' || (!$isDelete && $left === $current)) {
            return; // unchanged key: the consumer resolves the siblings of the current key itself
        }
        try {
            $siblings = $this->shadowedPageReader->activeStoreZeroPagesNamed([$left], $pageId);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('shadowed store-0 pages of page %d not looked up: %s', $pageId, $exception->getMessage()),
                ['exception' => $exception]
            );
            return;
        }
        foreach ($siblings as $siblingId) {
            $this->publisher->publish(ReindexMessageInterface::ENTITY_PAGE, $siblingId, ReindexMessageInterface::ACTION_SAVE);
        }
    }
}
