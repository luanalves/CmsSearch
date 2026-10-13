<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrderBuilder;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;

/**
 * The CMS tabs of a store view: active editorial content types in tab order (sort order, then id) with the store
 * view label. One repository read per store view and request (the collection loads all labels in one query); a fresh
 * criteria builder per read, never a shared one.
 */
class ContentTypeTabs
{
    /** @var array<int, list<array{code: string, label: string}>> */
    private array $cache = [];

    public function __construct(
        private readonly ContentTypeRepositoryInterface $repository,
        private readonly SearchCriteriaBuilderFactory $criteriaBuilderFactory,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public function forStore(int $storeId): array
    {
        if (isset($this->cache[$storeId])) {
            return $this->cache[$storeId];
        }
        $criteria = $this->criteriaBuilderFactory->create();
        $criteria->addFilter(ContentTypeInterface::IS_ACTIVE, 1);
        foreach ([ContentTypeInterface::SORT_ORDER, ContentTypeInterface::TYPE_ID] as $field) {
            $criteria->addSortOrder($this->sortOrderBuilder->setField($field)->setAscendingDirection()->create());
        }
        $tabs = [];
        foreach ($this->repository->getList($criteria->create())->getItems() as $type) {
            if ($type->getIsActive()) {
                $tabs[] = ['code' => $type->getCode(), 'label' => $type->getLabel($storeId)];
            }
        }
        return $this->cache[$storeId] = $tabs;
    }
}
