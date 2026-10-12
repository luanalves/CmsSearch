<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;

class Edit extends AbstractContentType implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_VIEW;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly ContentTypeRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $typeId = (int) $this->getRequest()->getParam('type_id');
        if ($typeId > 0) {
            try {
                $this->repository->getById($typeId);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This content type no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Thedevkitchen_CmsSearch::content_types');
        $page->getConfig()->getTitle()->prepend($typeId > 0 ? __('Edit Content Type') : __('New Content Type'));
        return $page;
    }
}
