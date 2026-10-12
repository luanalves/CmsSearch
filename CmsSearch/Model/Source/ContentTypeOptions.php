<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Source;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Data\OptionSourceInterface;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;

class ContentTypeOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly ContentTypeRepositoryInterface $repository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function toOptionArray(): array
    {
        $this->criteriaBuilder->addFilter(ContentTypeInterface::IS_ACTIVE, 1);
        foreach ([ContentTypeInterface::SORT_ORDER, ContentTypeInterface::TYPE_ID] as $field) {
            $this->criteriaBuilder->addSortOrder(
                $this->sortOrderBuilder->setField($field)->setAscendingDirection()->create()
            );
        }

        $options = [];
        foreach ($this->repository->getList($this->criteriaBuilder->create())->getItems() as $type) {
            $options[] = ['value' => (int) $type->getTypeId(), 'label' => $type->getLabel(0)];
        }
        return $options;
    }
}
