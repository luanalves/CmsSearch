<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search\Data;

/**
 * Answer of the CMS index for one search result page.
 */
final class CmsResult
{
    /**
     * @param array<string, int> $typeCounts content type code => matching page documents
     * @param list<Card> $hits the requested page of the active CMS tab
     * @param array<int, int> $termCounts term id => matching documents of the active content tab (descendants included)
     */
    public function __construct(
        private readonly array $typeCounts,
        private readonly int $categoryCount,
        private readonly bool $hasCategoryDocuments,
        private readonly int $total,
        private readonly array $hits,
        private readonly array $termCounts
    ) {
    }

    public function getCount(string $contentTypeCode): int
    {
        return $this->typeCounts[$contentTypeCode] ?? 0;
    }

    /** @return array<string, int> */
    public function getTypeCounts(): array
    {
        return $this->typeCounts;
    }

    public function getCategoryCount(): int
    {
        return $this->categoryCount;
    }

    /** The store view's index holds at least one category document (Plan 6), whatever the query. */
    public function hasCategoryDocuments(): bool
    {
        return $this->hasCategoryDocuments;
    }

    /** Documents of the active CMS tab (filter included); 0 on the Products tab. */
    public function getTotal(): int
    {
        return $this->total;
    }

    /** @return list<Card> */
    public function getHits(): array
    {
        return $this->hits;
    }

    /** @return array<int, int> */
    public function getTermCounts(): array
    {
        return $this->termCounts;
    }
}
