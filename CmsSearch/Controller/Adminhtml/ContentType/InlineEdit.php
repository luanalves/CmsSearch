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
                    $sortOrder = (string) $row['sort_order'];
                    if (!ctype_digit($sortOrder) || (int) $sortOrder > 65535) {
                        throw new LocalizedException(__('The tab order must be between 0 and %1.', 65535));
                    }
                    $type->setSortOrder((int) $sortOrder);
                }
                if (isset($row['is_active'])) {
                    if (!in_array($row['is_active'], ['0', '1', 0, 1], true)) {
                        throw new LocalizedException(__('Please correct the data sent.'));
                    }
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
