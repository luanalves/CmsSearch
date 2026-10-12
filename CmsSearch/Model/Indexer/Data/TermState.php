<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Data;

final class TermState
{
    /**
     * @param int[] $ancestorIds root first, the term itself last (TreeBuilder::ancestorIds)
     */
    public function __construct(
        private readonly int $termId,
        private readonly int $websiteId,
        private readonly string $path,
        private readonly array $ancestorIds,
        private readonly bool $effectiveActive
    ) {
    }

    public function getTermId(): int
    {
        return $this->termId;
    }

    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return int[]
     */
    public function getAncestorIds(): array
    {
        return $this->ancestorIds;
    }

    /** The term and every ancestor are active (a disabled term hides its whole branch). */
    public function isEffectiveActive(): bool
    {
        return $this->effectiveActive;
    }
}
