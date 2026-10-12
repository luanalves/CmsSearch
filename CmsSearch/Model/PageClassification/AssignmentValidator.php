<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\PageClassification;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface;
use Thedevkitchen\CmsSearch\Api\Data\PageClassificationInterface;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;

/**
 * Server-side rules of the Page link (D5, D6). The form only offers valid options; this class decides.
 */
class AssignmentValidator
{
    public function __construct(
        private readonly ContentTypeRepositoryInterface $types,
        private readonly ClassificationTermRepositoryInterface $terms,
        private readonly PageWebsites $pageWebsites,
        private readonly TreeBuilder $treeBuilder
    ) {
    }

    /**
     * @param int[] $storeIds store views of the Page (0 = All Store Views)
     * @param PageClassificationInterface|null $current link stored before this change (an inactive value may be kept)
     * @throws InputException
     */
    public function validate(?int $typeId, ?int $termId, array $storeIds, ?PageClassificationInterface $current): void
    {
        if ($typeId === null && $termId === null) {
            return;
        }
        if ($typeId === null || $termId === null) {
            throw new InputException(
                __('Select both a content type and a classification term, or leave both empty.')
            );
        }

        try {
            $type = $this->types->getById($typeId);
        } catch (NoSuchEntityException) {
            throw new InputException(__('The selected content type does not exist.'));
        }
        if (!$type->getIsActive() && $current?->getTypeId() !== $typeId) {
            throw new InputException(
                __('The content type "%1" is inactive. Choose an active content type.', $type->getLabel(0))
            );
        }

        try {
            $term = $this->terms->getById($termId);
        } catch (NoSuchEntityException) {
            throw new InputException(__('The selected classification term does not exist.'));
        }
        if (!in_array($term->getWebsiteId(), $this->pageWebsites->fromStoreIds($storeIds), true)) {
            throw new InputException(
                __('The classification term must belong to a website of the page\'s store views.')
            );
        }
        if ($current?->getTermId() !== $termId && !$this->isEffectivelyActive($term)) {
            throw new InputException(
                __('The classification term "%1" is inactive. Choose an active term.', $term->getLabel(0))
            );
        }
    }

    /**
     * A term is usable only if it and every ancestor are active.
     */
    private function isEffectivelyActive(ClassificationTermInterface $term): bool
    {
        if (!$term->getIsActive()) {
            return false;
        }
        foreach ($this->treeBuilder->ancestorIds($term->getPath()) as $ancestorId) {
            if ($ancestorId !== $term->getTermId() && !$this->terms->getById($ancestorId)->getIsActive()) {
                return false;
            }
        }
        return true;
    }
}
