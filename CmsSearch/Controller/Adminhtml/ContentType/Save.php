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
        $resource = (int) $this->getRequest()->getParam('type_id') > 0
            ? self::RESOURCE_EDIT
            : self::RESOURCE_CREATE;
        return $this->_authorization->isAllowed($resource);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        if (!$data) {
            return $redirect->setPath('*/*/');
        }

        $typeId = (int) ($data['type_id'] ?? 0);
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
