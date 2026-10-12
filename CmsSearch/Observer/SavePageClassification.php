<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\PageClassificationRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\PageClassification\PageWebsites;
use Thedevkitchen\CmsSearch\Model\PageClassification\SubmittedValues;

/**
 * cms_page_save_after: persists the Search fieldset through the repository (single write path).
 * The event is dispatched before the page save transaction commits (Plan 2 Task 9 spike), so an exception
 * here rolls the page save back and the admin sees the validation message.
 */
class SavePageClassification implements ObserverInterface
{
    public function __construct(
        private readonly SubmittedValues $submittedValues,
        private readonly PageClassificationRepositoryInterface $repository,
        private readonly PageWebsites $pageWebsites,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $page = $observer->getEvent()->getData('object');
        if ($page instanceof PageInterface) {
            $this->persist($page);
        }
    }

    /**
     * Validation messages (LocalizedException) reach the admin as they are; anything else is logged and replaced
     * by a generic message (no SQL or internals in the admin). Either way the page save is rolled back.
     *
     * @throws LocalizedException
     */
    public function persist(PageInterface $page): void
    {
        if (!$page instanceof DataObject) {
            return;
        }
        $values = $this->submittedValues->read($page);
        if ($values === null) {
            return;
        }
        $pageId = (int) $page->getId();
        if ($pageId <= 0) {
            throw new LocalizedException(__('The CMS page id is not valid.'));
        }
        try {
            // The store views the page save itself writes (never request input): the term must match their websites.
            $this->repository->assign($pageId, $values['type_id'], $values['term_id'], $this->pageWebsites->storeIdsOf($page));
        } catch (LocalizedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            throw new CouldNotSaveException(__('Could not save the search classification of the page.'));
        }
    }
}
