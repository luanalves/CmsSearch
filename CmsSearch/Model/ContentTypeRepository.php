<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterfaceFactory;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\ContentType\DeleteGuard;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType as ContentTypeResource;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\CollectionFactory;

class ContentTypeRepository implements ContentTypeRepositoryInterface
{
    private const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';
    private const MAX_SORT_ORDER = 65535;
    private const MAX_LABEL_LENGTH = 255;

    public function __construct(
        private readonly ContentTypeResource $resource,
        private readonly ContentTypeFactory $factory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly ContentTypeSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly DeleteGuard $deleteGuard,
        private readonly ReindexPublisherInterface $reindexPublisher
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(ContentTypeInterface $type): ContentTypeInterface
    {
        $this->validate($type);
        try {
            $this->resource->save($type);
        } catch (AlreadyExistsException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__('Could not save the content type: %1', $exception->getMessage()), $exception);
        }
        $typeId = $type->getTypeId();
        if ($typeId !== null) {
            // Label, tab order and active flag change what every page of the type shows: the consumer expands it.
            $this->reindexPublisher->publish(
                ReindexMessageInterface::ENTITY_CONTENT_TYPE,
                $typeId,
                ReindexMessageInterface::ACTION_SAVE
            );
        }
        return $type;
    }

    /**
     * @inheritDoc
     */
    public function getById(int $typeId): ContentTypeInterface
    {
        $type = $this->factory->create();
        $this->resource->load($type, $typeId);
        if (!$type->getId()) {
            throw new NoSuchEntityException(__('The content type with id "%1" does not exist.', $typeId));
        }
        return $type;
    }

    /**
     * @inheritDoc
     */
    public function getByCode(string $code): ContentTypeInterface
    {
        $type = $this->factory->create();
        $this->resource->load($type, $code, ContentTypeInterface::CODE);
        if (!$type->getId()) {
            throw new NoSuchEntityException(__('The content type with code "%1" does not exist.', $code));
        }
        return $type;
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): ContentTypeSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        // getItems() loads the collection, so sort orders and paging are applied;
        // labels are hydrated by the collection in a single query.
        $items = array_values($collection->getItems());

        $results = $this->searchResultsFactory->create();
        $results->setSearchCriteria($searchCriteria);
        $results->setItems($items);
        $results->setTotalCount($collection->getSize());
        return $results;
    }

    /**
     * @inheritDoc
     */
    public function delete(ContentTypeInterface $type): bool
    {
        // Rule lives here so controller, mass action and any other caller are covered.
        $this->deleteGuard->assertDeletable($type);
        $typeId = $type->getTypeId();
        try {
            $this->resource->delete($type);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(__('Could not delete the content type: %1', $exception->getMessage()), $exception);
        }
        if ($typeId !== null) {
            $this->reindexPublisher->publish(
                ReindexMessageInterface::ENTITY_CONTENT_TYPE,
                $typeId,
                ReindexMessageInterface::ACTION_DELETE
            );
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteById(int $typeId): bool
    {
        return $this->delete($this->getById($typeId));
    }

    private function validate(ContentTypeInterface $type): void
    {
        $code = $type->getCode();
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InputException(
                __('The content type code must have 1-64 characters: lowercase letters, digits and underscore.')
            );
        }

        $typeId = $type->getTypeId();
        if ($typeId !== null) {
            $currentCode = $this->resource->getCodeById($typeId);
            if ($currentCode !== null && $currentCode !== $code) {
                throw new InputException(__('The content type code cannot be changed after creation.'));
            }
        }

        if ($this->resource->codeExists($code, $typeId)) {
            throw new AlreadyExistsException(__('A content type with code "%1" already exists.', $code));
        }

        $sortOrder = $type->getSortOrder();
        if ($sortOrder < 0 || $sortOrder > self::MAX_SORT_ORDER) {
            throw new InputException(__('The tab order must be between 0 and %1.', self::MAX_SORT_ORDER));
        }

        $labels = $type->getLabels();
        if (trim((string) ($labels[0] ?? '')) === '') {
            throw new InputException(__('The default label is required.'));
        }
        foreach ($labels as $label) {
            if (mb_strlen((string) $label) > self::MAX_LABEL_LENGTH) {
                throw new InputException(
                    __('Labels must have at most %1 characters.', self::MAX_LABEL_LENGTH)
                );
            }
        }
    }
}
