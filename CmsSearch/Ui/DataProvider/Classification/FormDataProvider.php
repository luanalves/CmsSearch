<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Ui\DataProvider\Classification;

use Magento\Framework\Api\Filter;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\Save;
use Thedevkitchen\CmsSearch\Model\Classification\FormDataMapper;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;

class FormDataProvider extends AbstractDataProvider
{
    private ?ClassificationTermInterface $term = null;
    private bool $termLoaded = false;

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly ClassificationTermRepositoryInterface $repository,
        private readonly FormDataMapper $mapper,
        private readonly TreeBuilder $treeBuilder,
        private readonly StoreManagerInterface $storeManager,
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
     * Parent options ("move to") never contain the term itself or its descendants; store-view label
     * options are the store views of the term's website; the code is locked when editing.
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        $meta = parent::getMeta();
        $term = $this->currentTerm();
        $websiteId = $term?->getWebsiteId() ?? $this->requestedWebsiteId();
        if ($term !== null) {
            $meta['general']['children']['code']['arguments']['data']['config']['disabled'] = true;
        }

        $parents = [['value' => '', 'label' => __('(Root)')]];
        if ($websiteId > 0) {
            $ownPath = $term?->getPath() ?? '';
            foreach ($this->treeBuilder->flatten($this->treeBuilder->build($websiteId)) as $row) {
                if ($ownPath !== '' && ($row['path'] === $ownPath || str_starts_with($row['path'], $ownPath . '/'))) {
                    continue;
                }
                $parents[] = ['value' => $row['term_id'], 'label' => $row['trail']];
            }
        }
        $meta['general']['children']['parent_id']['arguments']['data']['config']['options'] = $parents;

        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ((int) $store->getWebsiteId() === $websiteId) {
                $stores[] = ['value' => (int) $store->getId(), 'label' => (string) $store->getName()];
            }
        }
        $meta['general']['children']['store_labels']['children']['record']['children']['store_id']
            ['arguments']['data']['config']['options'] = $stores;
        return $meta;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getData(): array
    {
        $requestedId = $this->positiveInt($this->request->getParam($this->requestFieldName));
        $persisted = $this->dataPersistor->get(Save::PERSISTOR_KEY);
        if (!empty($persisted) && $this->positiveInt($persisted['term_id'] ?? '') === $requestedId) {
            $this->dataPersistor->clear(Save::PERSISTOR_KEY);
            return [$requestedId > 0 ? $requestedId : '' => $persisted];
        }

        if ($requestedId > 0) {
            $term = $this->currentTerm();
            return $term === null
                ? []
                : [$requestedId => $this->mapper->toFormData($term, $this->websiteName($term->getWebsiteId()))];
        }

        $websiteId = $this->requestedWebsiteId();
        $websiteName = $this->websiteName($websiteId);
        if ($websiteName === null) {
            return [];
        }
        $parentId = $this->positiveInt($this->request->getParam('parent_id'));
        $parentIds = array_column($this->treeBuilder->flatten($this->treeBuilder->build($websiteId)), 'term_id');
        return ['' => [
            'website_id' => (string) $websiteId,
            'website_name' => $websiteName,
            'parent_id' => in_array($parentId, $parentIds, true) ? (string) $parentId : '',
            'is_active' => '1',
            'sort_order' => '0',
        ]];
    }

    private function currentTerm(): ?ClassificationTermInterface
    {
        if (!$this->termLoaded) {
            $this->termLoaded = true;
            $termId = $this->positiveInt($this->request->getParam($this->requestFieldName));
            if ($termId > 0) {
                try {
                    $this->term = $this->repository->getById($termId);
                } catch (NoSuchEntityException) {
                    $this->term = null;
                }
            }
        }
        return $this->term;
    }

    private function requestedWebsiteId(): int
    {
        return $this->positiveInt($this->request->getParam('website_id'));
    }

    private function websiteName(int $websiteId): ?string
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            if ((int) $website->getId() === $websiteId) {
                return (string) $website->getName();
            }
        }
        return null;
    }

    private function positiveInt(mixed $value): int
    {
        $value = (string) $value;
        return ctype_digit($value) ? (int) $value : 0;
    }
}
