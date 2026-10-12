<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;

/**
 * Opens the term form. Read-only for users without the manage right (no save/delete buttons).
 */
class Edit extends AbstractClassification implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_VIEW;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly ClassificationTermRepositoryInterface $repository,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $termId = $this->positiveInt($this->getRequest()->getParam('term_id'));
        if ($termId > 0) {
            try {
                $this->repository->getById($termId);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This classification term no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }
        } elseif (!$this->websiteExists($this->positiveInt($this->getRequest()->getParam('website_id')))) {
            $this->messageManager->addErrorMessage(__('Select a website first.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Thedevkitchen_CmsSearch::classification');
        $page->getConfig()->getTitle()->prepend(
            $termId > 0 ? __('Edit Classification Term') : __('New Classification Term')
        );
        return $page;
    }

    private function positiveInt(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    private function websiteExists(int $websiteId): bool
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            if ((int) $website->getId() === $websiteId) {
                return true;
            }
        }
        return false;
    }
}
