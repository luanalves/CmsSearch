<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;

class InlineEdit extends AbstractContentType implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_EDIT;

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ContentTypeRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $json = $this->jsonFactory->create();
        $items = $this->getRequest()->getParam('items', []);
        if (!$this->getRequest()->getParam('isAjax') || !is_array($items) || !$items) {
            return $json->setData(['messages' => [__('Please correct the data sent.')], 'error' => true]);
        }

        $messages = [];
        $error = false;
        foreach ($items as $typeId => $row) {
            try {
                $type = $this->repository->getById((int) $typeId);
                // Only the tab order and the status are editable inline; code and labels are not touched.
                if (isset($row['sort_order'])) {
                    $type->setSortOrder((int) $row['sort_order']);
                }
                if (isset($row['is_active'])) {
                    $type->setIsActive((bool) (int) $row['is_active']);
                }
                $this->repository->save($type);
            } catch (LocalizedException $exception) {
                $messages[] = '[ID: ' . (int) $typeId . '] ' . $exception->getMessage();
                $error = true;
            }
        }
        return $json->setData(['messages' => $messages, 'error' => $error]);
    }
}
