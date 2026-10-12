<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;
use Thedevkitchen\CmsSearch\Model\ContentType\BulkDeleter;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\CollectionFactory;

class MassDelete extends AbstractContentType implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_DELETE;

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly BulkDeleter $bulkDeleter
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $result = $this->bulkDeleter->delete($collection->getAllIds());

        if ($result['deleted'] > 0) {
            $this->messageManager->addSuccessMessage(
                __('A total of %1 record(s) have been deleted.', $result['deleted'])
            );
        }
        foreach ($result['blocked'] as $message) {
            $this->messageManager->addErrorMessage($message);
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
