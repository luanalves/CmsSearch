<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Source;

use Magento\Framework\Exception\NoSuchEntityException;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;

/**
 * Content type dropdown of the CMS Page form: the active types, plus the Page's current type when it was deactivated.
 */
class PageContentTypeOptions
{
    public function __construct(
        private readonly ContentTypeOptions $activeOptions,
        private readonly ContentTypeRepositoryInterface $repository
    ) {
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function toOptionArrayWithCurrent(?int $currentTypeId): array
    {
        $options = $this->activeOptions->toOptionArray();
        if ($currentTypeId === null || in_array($currentTypeId, array_column($options, 'value'), true)) {
            return $options;
        }
        try {
            $type = $this->repository->getById($currentTypeId);
        } catch (NoSuchEntityException) {
            return $options;
        }
        $options[] = ['value' => $currentTypeId, 'label' => (string) __('%1 (inactive)', $type->getLabel(0))];
        return $options;
    }
}
