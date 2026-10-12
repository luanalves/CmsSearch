<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api\Data;

interface ContentTypeInterface
{
    public const TYPE_ID = 'type_id';
    public const CODE = 'code';
    public const SORT_ORDER = 'sort_order';
    public const IS_ACTIVE = 'is_active';
    public const LABELS = 'labels';

    public function getTypeId(): ?int;

    public function setTypeId(int $typeId): static;

    public function getCode(): string;

    public function setCode(string $code): static;

    public function getSortOrder(): int;

    public function setSortOrder(int $sortOrder): static;

    public function getIsActive(): bool;

    public function setIsActive(bool $isActive): static;

    /**
     * @return array<int, string> map of store id => label (store id 0 is the default label)
     */
    public function getLabels(): array;

    /**
     * @param array<int, string> $labels
     */
    public function setLabels(array $labels): static;

    /**
     * Label for the store view, falling back to the default label and then to the code.
     */
    public function getLabel(int $storeId): string;
}
