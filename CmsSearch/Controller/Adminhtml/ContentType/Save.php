<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterfaceFactory;
use Thedevkitchen\CmsSearch\Model\ContentType\FormDataMapper;

class Save extends AbstractContentType implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_EDIT;
    public const PERSISTOR_KEY = 'thedevkitchen_cmssearch_content_type';

    public function __construct(
        Context $context,
        private readonly ContentTypeRepositoryInterface $repository,
        private readonly ContentTypeInterfaceFactory $typeFactory,
        private readonly FormDataMapper $mapper,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Creating needs the create permission, changing an existing type needs the edit permission.
     */
    protected function _isAllowed(): bool
    {
        $resource = $this->resolveTypeId() > 0 ? self::RESOURCE_EDIT : self::RESOURCE_CREATE;
        return $this->_authorization->isAllowed($resource);
    }

    /**
     * Single source of truth for the id of the type being saved: the POST body wins, then the request param.
     * Both the permission check and execute() use it, so the right required always matches the type touched.
     */
    private function resolveTypeId(): int
    {
        $postId = (int) (((array) $this->getRequest()->getPostValue())['type_id'] ?? 0);
        return $postId > 0 ? $postId : max(0, (int) $this->getRequest()->getParam('type_id'));
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        if (!$data) {
            return $redirect->setPath('*/*/');
        }

        $typeId = $this->resolveTypeId();
        if ($typeId > 0 && !$this->_authorization->isAllowed(self::RESOURCE_EDIT)) {
            $this->messageManager->addErrorMessage(__('You are not allowed to edit content types.'));
            return $redirect->setPath('*/*/');
        }
        try {
            $type = $typeId > 0 ? $this->repository->getById($typeId) : $this->typeFactory->create();
            if ($typeId === 0) {
                $type->setCode(trim((string) ($data['code'] ?? '')));
            }
            $type->setSortOrder((int) ($data['sort_order'] ?? 0));
            $type->setIsActive((bool) (int) ($data['is_active'] ?? 0));
            $type->setLabels($this->mapper->toLabels($data));
            $saved = $this->repository->save($type);

            $this->messageManager->addSuccessMessage(__('You saved the content type.'));
            $this->dataPersistor->clear(self::PERSISTOR_KEY);
            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['type_id' => $saved->getTypeId()]);
            }
            return $redirect->setPath('*/*/');
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('Something went wrong while saving the content type.'));
        }

        $this->dataPersistor->set(self::PERSISTOR_KEY, $data);
        return $typeId > 0
            ? $redirect->setPath('*/*/edit', ['type_id' => $typeId])
            : $redirect->setPath('*/*/new');
    }
}
