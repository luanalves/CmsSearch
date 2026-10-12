<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\CollectionFactory;

class MassStatus extends AbstractContentType implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_EDIT;

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ContentTypeRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $isActive = (bool) (int) $this->getRequest()->getParam('status');
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        $updated = 0;
        foreach ($collection->getAllIds() as $typeId) {
            try {
                $type = $this->repository->getById((int) $typeId);
                $this->repository->save($type->setIsActive($isActive));
                $updated++;
            } catch (LocalizedException $exception) {
                $this->messageManager->addErrorMessage($exception->getMessage());
            }
        }
        if ($updated > 0) {
            $this->messageManager->addSuccessMessage(__('A total of %1 record(s) have been updated.', $updated));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
