<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface as Term;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermSearchResultsInterface;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermSearchResultsInterfaceFactory;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Classification\DeleteGuard;
use Thedevkitchen\CmsSearch\Model\Classification\PathManager;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm\CollectionFactory;

/**
 * Single write path of classification terms (validation, path/level, delete rules).
 */
class ClassificationTermRepository implements ClassificationTermRepositoryInterface
{
    private const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';
    private const MAX_SORT_ORDER = 65535;
    private const MAX_LABEL_LENGTH = 255;

    public function __construct(
        private readonly TermResource $resource,
        private readonly ClassificationTermFactory $factory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly ClassificationTermSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly PathManager $pathManager,
        private readonly DeleteGuard $deleteGuard,
        private readonly TreeBuilder $treeBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly ReindexPublisherInterface $reindexPublisher
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(Term $term): Term
    {
        if (!$term instanceof AbstractModel) {
            throw new InputException(__('Unsupported classification term implementation.'));
        }
        $termId = $term->getTermId();
        $stored = $termId === null ? null : $this->resource->getStoredState($termId);
        if ($termId !== null && $stored === null) {
            throw new NoSuchEntityException(__('The classification term with id "%1" does not exist.', $termId));
        }
        $this->validate($term, $stored);

        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            // path and level belong to the PathManager: never trust the values carried by the object
            $term->setData(Term::PATH, $stored['path'] ?? '');
            $term->setData(Term::LEVEL, $stored['level'] ?? 0);
            $this->resource->save($term);
            if ($stored === null || $stored['parent_id'] !== $term->getParentId()) {
                $placed = $this->pathManager->relocate(
                    (int) $term->getTermId(),
                    $term->getParentId(),
                    $stored['path'] ?? ''
                );
                $term->setData(Term::PATH, $placed['path']);
                $term->setData(Term::LEVEL, $placed['level']);
            }
            // Held until this COMMIT (dropped on ROLLBACK) by the publisher. One message also for a move: the
            // consumer reindexes the pages of the term's CURRENT subtree, so descendants need no message.
            $this->reindexPublisher->publish(
                ReindexMessageInterface::ENTITY_CLASSIFICATION_TERM,
                (int) $term->getTermId(),
                ReindexMessageInterface::ACTION_SAVE,
                null,
                $term->getWebsiteId()
            );
            $connection->commit();
        } catch (LocalizedException $exception) {
            $connection->rollBack();
            throw $exception;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            throw new CouldNotSaveException(__('Could not save the classification term.'), $exception);
        }
        return $term;
    }

    /**
     * @inheritDoc
     */
    public function getById(int $termId): Term
    {
        $term = $this->factory->create();
        $this->resource->load($term, $termId);
        if (!$term->getId()) {
            throw new NoSuchEntityException(__('The classification term with id "%1" does not exist.', $termId));
        }
        return $term;
    }

    /**
     * @inheritDoc
     */
    public function getByCode(int $websiteId, string $code): Term
    {
        $termId = $this->resource->getIdByCode($websiteId, $code);
        if ($termId === null) {
            throw new NoSuchEntityException(
                __('The classification term with code "%1" does not exist in this website.', $code)
            );
        }
        return $this->getById($termId);
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): ClassificationTermSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);
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
    public function delete(Term $term): bool
    {
        // Rule lives here so the admin, the fixtures and any future caller are covered.
        $this->deleteGuard->assertDeletable($term);
        $termId = (int) $term->getTermId();
        $websiteId = $term->getWebsiteId();
        try {
            $this->resource->delete($term);
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            throw new CouldNotDeleteException(__('Could not delete the classification term.'), $exception);
        }
        $this->reindexPublisher->publish(
            ReindexMessageInterface::ENTITY_CLASSIFICATION_TERM,
            $termId,
            ReindexMessageInterface::ACTION_DELETE,
            null,
            $websiteId
        );
        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteById(int $termId): bool
    {
        return $this->delete($this->getById($termId));
    }

    /**
     * @param array{term_id:int, website_id:int, parent_id:?int, code:string, path:string, level:int, is_active:bool}|null $stored
     */
    private function validate(Term $term, ?array $stored): void
    {
        $code = $term->getCode();
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InputException(
                __('The classification term code must have 1-64 characters: lowercase letters, digits and underscore.')
            );
        }

        $websiteId = $term->getWebsiteId();
        $storesByWebsite = $this->storeIdsByWebsite();
        if (!isset($storesByWebsite[$websiteId])) {
            throw new InputException(__('Select an existing website for the classification term.'));
        }
        if ($stored !== null && $stored['code'] !== $code) {
            throw new InputException(__('The classification term code cannot be changed after creation.'));
        }
        if ($stored !== null && $stored['website_id'] !== $websiteId) {
            throw new InputException(__('The website of a classification term cannot be changed.'));
        }
        if ($this->resource->codeExists($websiteId, $code, $term->getTermId())) {
            throw new AlreadyExistsException(
                __('A classification term with code "%1" already exists in this website.', $code)
            );
        }

        $sortOrder = $term->getSortOrder();
        if ($sortOrder < 0 || $sortOrder > self::MAX_SORT_ORDER) {
            throw new InputException(__('The sort order must be between 0 and %1.', self::MAX_SORT_ORDER));
        }

        $labels = $term->getLabels();
        if (trim((string) ($labels[0] ?? '')) === '') {
            throw new InputException(__('The default label is required.'));
        }
        foreach ($labels as $storeId => $label) {
            if (mb_strlen((string) $label) > self::MAX_LABEL_LENGTH) {
                throw new InputException(__('Labels must have at most %1 characters.', self::MAX_LABEL_LENGTH));
            }
            if ((int) $storeId !== 0 && !in_array((int) $storeId, $storesByWebsite[$websiteId], true)) {
                throw new InputException(__('Labels can only be set for the store views of the website of the term.'));
            }
        }

        $parentId = $term->getParentId();
        if ($parentId === null) {
            return;
        }
        $parent = $parentId > 0 ? $this->resource->getStoredState($parentId) : null;
        if ($parent === null) {
            throw new InputException(__('The parent term does not exist.'));
        }
        if ($parent['website_id'] !== $websiteId) {
            throw new InputException(__('The parent term must belong to the same website.'));
        }
        $termId = $term->getTermId();
        if ($termId !== null && in_array($termId, $this->treeBuilder->ancestorIds($parent['path']), true)) {
            throw new InputException(__('A term cannot be moved under itself or one of its descendants.'));
        }
    }

    /**
     * @return array<int, int[]> website id => store view ids (the admin website is not included)
     */
    private function storeIdsByWebsite(): array
    {
        $map = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $map[(int) $website->getId()] = [];
        }
        foreach ($this->storeManager->getStores() as $store) {
            $websiteId = (int) $store->getWebsiteId();
            if (isset($map[$websiteId])) {
                $map[$websiteId][] = (int) $store->getId();
            }
        }
        return $map;
    }
}
