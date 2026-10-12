<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ContentType;

use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;

class FormDataMapper
{
    /**
     * @param array<string, mixed> $post
     * @return array<int, string> map of store id => label (0 = default)
     */
    public function toLabels(array $post): array
    {
        $labels = [];
        $default = trim((string) ($post['default_label'] ?? ''));
        if ($default !== '') {
            $labels[0] = $default;
        }
        // Rule: a store label row counts only with a store_id made of digits only (positive) and a scalar label;
        // anything else ("abc", "1abc", "1.5", arrays) is ignored, never coerced to another store.
        foreach ((array) ($post['store_labels'] ?? []) as $row) {
            if (!is_array($row) || !is_scalar($row['store_id'] ?? null) || !is_scalar($row['label'] ?? null)) {
                continue;
            }
            $rawStoreId = (string) $row['store_id'];
            $storeId = ctype_digit($rawStoreId) ? (int) $rawStoreId : 0;
            $label = trim((string) $row['label']);
            if ($storeId > 0 && $label !== '') {
                $labels[$storeId] = $label;
            }
        }
        return $labels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toFormData(ContentTypeInterface $type): array
    {
        $labels = $type->getLabels();
        $rows = [];
        foreach ($labels as $storeId => $label) {
            if ((int) $storeId === 0) {
                continue;
            }
            $rows[] = ['record_id' => (string) $storeId, 'store_id' => (string) $storeId, 'label' => $label];
        }
        return [
            ContentTypeInterface::TYPE_ID => $type->getTypeId(),
            ContentTypeInterface::CODE => $type->getCode(),
            ContentTypeInterface::SORT_ORDER => $type->getSortOrder(),
            ContentTypeInterface::IS_ACTIVE => $type->getIsActive() ? '1' : '0',
            'default_label' => $labels[0] ?? '',
            'store_labels' => $rows,
        ];
    }
}
