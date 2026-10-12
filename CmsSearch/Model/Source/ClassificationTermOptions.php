<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Source;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;

/**
 * Classification dropdown of the CMS Page form: active terms (whole active branch) of the Page's websites.
 */
class ClassificationTermOptions
{
    public function __construct(
        private readonly TreeBuilder $treeBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly ClassificationTermRepositoryInterface $repository
    ) {
    }

    /**
     * @param int[] $websiteIds
     * @return list<array{value: int, label: string}>
     */
    public function forWebsites(array $websiteIds, ?int $currentTermId): array
    {
        $websiteIds = array_values(array_unique(array_map('intval', $websiteIds)));
        sort($websiteIds);
        $names = $this->websiteNames();
        $prefixed = count($websiteIds) > 1;

        $options = [];
        foreach ($websiteIds as $websiteId) {
            foreach ($this->treeBuilder->flatten($this->treeBuilder->build($websiteId, 0, true)) as $row) {
                $options[] = [
                    'value' => $row['term_id'],
                    'label' => $this->label($row['trail'], $prefixed ? ($names[$websiteId] ?? '') : ''),
                ];
            }
        }
        if ($currentTermId === null || in_array($currentTermId, array_column($options, 'value'), true)) {
            return $options;
        }

        try {
            $term = $this->repository->getById($currentTermId);
        } catch (NoSuchEntityException) {
            return $options;
        }
        $trail = $term->getLabel(0);
        foreach ($this->treeBuilder->flatten($this->treeBuilder->build($term->getWebsiteId())) as $row) {
            if ($row['term_id'] === $currentTermId) {
                $trail = $row['trail'];
                break;
            }
        }
        $label = $this->label($trail, $prefixed ? ($names[$term->getWebsiteId()] ?? '') : '');
        $options[] = [
            'value' => $currentTermId,
            'label' => in_array($term->getWebsiteId(), $websiteIds, true)
                ? (string) __('%1 (inactive)', $label)
                : (string) __('%1 (other website)', $label),
        ];
        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function websiteNames(): array
    {
        $names = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $names[(int) $website->getId()] = (string) $website->getName();
        }
        return $names;
    }

    private function label(string $trail, string $websiteName): string
    {
        return $websiteName === '' ? $trail : $websiteName . ' / ' . $trail;
    }
}
