<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;

class Delete extends AbstractContentType implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_DELETE;

    public function __construct(Context $context, private readonly ContentTypeRepositoryInterface $repository)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $typeId = (int) $this->getRequest()->getParam('type_id');
        if ($typeId <= 0) {
            $this->messageManager->addErrorMessage(__('We can\'t find a content type to delete.'));
            return $redirect->setPath('*/*/');
        }

        try {
            $this->repository->deleteById($typeId);
            $this->messageManager->addSuccessMessage(__('You deleted the content type.'));
            return $redirect->setPath('*/*/');
        } catch (LocalizedException $exception) {
            // Includes ContentTypeInUseException: the message lists the linked pages.
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $redirect->setPath('*/*/edit', ['type_id' => $typeId]);
        }
    }
}
