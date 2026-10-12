<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Ui\DataProvider\ContentType;

use Magento\Framework\Api\Filter;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\Save;
use Thedevkitchen\CmsSearch\Model\ContentType\FormDataMapper;

class FormDataProvider extends AbstractDataProvider
{
    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly ContentTypeRepositoryInterface $repository,
        private readonly FormDataMapper $mapper,
        private readonly RequestInterface $request,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * This provider has no collection; the record is looked up from the request in getData().
     */
    public function addFilter(Filter $filter): void
    {
    }

    /**
     * The code is immutable: lock the field whenever an existing type is being edited.
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        $meta = parent::getMeta();
        if ((int) $this->request->getParam($this->requestFieldName) > 0) {
            $meta['general']['children']['code']['arguments']['data']['config']['disabled'] = true;
        }
        return $meta;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getData(): array
    {
        $requestedId = (int) $this->request->getParam($this->requestFieldName);
        $persisted = $this->dataPersistor->get(Save::PERSISTOR_KEY);
        if (!empty($persisted)) {
            $persistedId = (int) ($persisted['type_id'] ?? 0);
            if ($persistedId === $requestedId) {
                $this->dataPersistor->clear(Save::PERSISTOR_KEY);
                return [$persistedId > 0 ? $persistedId : '' => $persisted];
            }
        }

        $typeId = $requestedId;
        if ($typeId <= 0) {
            return [];
        }
        try {
            return [$typeId => $this->mapper->toFormData($this->repository->getById($typeId))];
        } catch (NoSuchEntityException) {
            return [];
        }
    }
}
