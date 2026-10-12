<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Classification;

use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface;
use Thedevkitchen\CmsSearch\Model\ContentType\FormDataMapper as LabelsMapper;

class FormDataMapper
{
    public function __construct(private readonly LabelsMapper $labelsMapper)
    {
    }

    /**
     * Same label fields as the content type form (default_label + store_labels rows).
     *
     * @param array<string, mixed> $post
     * @return array<int, string>
     */
    public function toLabels(array $post): array
    {
        return $this->labelsMapper->toLabels($post);
    }

    /**
     * @return array<string, mixed>
     */
    public function toFormData(ClassificationTermInterface $term, string $websiteName): array
    {
        $labels = $term->getLabels();
        $rows = [];
        foreach ($labels as $storeId => $label) {
            if ((int) $storeId === 0) {
                continue;
            }
            $rows[] = ['record_id' => (string) $storeId, 'store_id' => (string) $storeId, 'label' => $label];
        }
        return [
            'term_id' => (string) $term->getTermId(),
            'website_id' => (string) $term->getWebsiteId(),
            'website_name' => $websiteName,
            'parent_id' => $term->getParentId() === null ? '' : (string) $term->getParentId(),
            'code' => $term->getCode(),
            'sort_order' => (string) $term->getSortOrder(),
            'is_active' => $term->getIsActive() ? '1' : '0',
            'default_label' => $labels[0] ?? '',
            'store_labels' => $rows,
        ];
    }
}
