<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Data;

/**
 * The CURRENT (Staging-active) version of a CMS page, identified by its stable page_id (D9).
 */
final class PageSnapshot
{
    /**
     * @param int[] $storeIds 0 = All Store Views
     */
    public function __construct(
        private readonly int $pageId,
        private readonly string $identifier,
        private readonly string $title,
        private readonly string $contentHeading,
        private readonly string $content,
        private readonly string $metaDescription,
        private readonly bool $isActive,
        private readonly array $storeIds,
        private readonly ?string $updateTime
    ) {
    }

    public function getPageId(): int
    {
        return $this->pageId;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContentHeading(): string
    {
        return $this->contentHeading;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getMetaDescription(): string
    {
        return $this->metaDescription;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return int[]
     */
    public function getStoreIds(): array
    {
        return $this->storeIds;
    }

    public function getUpdateTime(): ?string
    {
        return $this->updateTime;
    }
}
