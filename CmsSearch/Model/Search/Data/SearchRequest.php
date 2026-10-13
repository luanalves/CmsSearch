<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search\Data;

/**
 * What one search result page asks the CMS index: the counters of every tab and, on a CMS tab, one page of its
 * documents (filtered by an ancestor term on content tabs).
 */
final class SearchRequest
{
    public function __construct(
        private readonly string $queryText,
        private readonly int $storeId,
        private readonly int $websiteId,
        private readonly string $activeKind,
        private readonly ?string $contentTypeCode,
        private readonly ?int $termId,
        private readonly int $page,
        private readonly int $pageSize
    ) {
    }

    public function getQueryText(): string
    {
        return $this->queryText;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    public function getActiveKind(): string
    {
        return $this->activeKind;
    }

    public function getContentTypeCode(): ?string
    {
        return $this->contentTypeCode;
    }

    public function getTermId(): ?int
    {
        return $this->termId;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getFrom(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }

    /** The Products tab needs the counters only. */
    public function wantsHits(): bool
    {
        return $this->activeKind !== Tab::KIND_PRODUCTS;
    }
}
