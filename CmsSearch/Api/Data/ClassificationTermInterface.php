<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api\Data;

interface ClassificationTermInterface
{
    public const TERM_ID = 'term_id';
    public const WEBSITE_ID = 'website_id';
    public const PARENT_ID = 'parent_id';
    public const CODE = 'code';
    public const PATH = 'path';
    public const LEVEL = 'level';
    public const SORT_ORDER = 'sort_order';
    public const IS_ACTIVE = 'is_active';
    public const LABELS = 'labels';

    public function getTermId(): ?int;

    public function setTermId(int $termId): static;

    public function getWebsiteId(): int;

    public function setWebsiteId(int $websiteId): static;

    /**
     * Null for a root term.
     */
    public function getParentId(): ?int;

    public function setParentId(?int $parentId): static;

    public function getCode(): string;

    public function setCode(string $code): static;

    /**
     * Ids from the root to this term, inclusive, separated by "/" (e.g. "1/4/9"). Maintained by the repository.
     */
    public function getPath(): string;

    /**
     * Depth of the term, 0 for a root term. Maintained by the repository.
     */
    public function getLevel(): int;

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
