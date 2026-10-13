<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;

/**
 * layout_load_before (storefront): on the search result page with an active CMS tab, adds the handle whose layout
 * replaces the native listing and layered navigation by the CMS cards and filter (spec 5.6, no controller plugin).
 * Any other page returns before touching the (lazy) search state.
 */
class AddCmsTabHandle implements ObserverInterface
{
    public const ACTION = 'catalogsearch_result_index';
    public const HANDLE = 'thedevkitchen_cmssearch_tab';

    public function __construct(
        private readonly SearchState $state,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        if ($event->getData('full_action_name') !== self::ACTION) {
            return;
        }
        $layout = $event->getData('layout');
        if (!$layout instanceof LayoutInterface) {
            return;
        }
        try {
            if ($this->state->isEnabled() && $this->state->isCmsTabActive()) {
                $layout->getUpdate()->addHandle(self::HANDLE);
            }
        } catch (\Throwable $exception) {
            try {
                $this->logger->error(sprintf('CMS tab handle not added (%s).', get_class($exception)));
            } catch (\Throwable) {
                // an unwritable log must not break the page
            }
        }
    }
}
