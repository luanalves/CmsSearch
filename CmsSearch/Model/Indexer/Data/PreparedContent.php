<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Data;

/**
 * The store-view independent part of a page document (text, summary, raw first image), computed once per page.
 */
final class PreparedContent
{
    public function __construct(
        private readonly int $pageId,
        private readonly string $text,
        private readonly string $summary,
        private readonly ?string $firstImage
    ) {
    }

    /** The page this content was prepared from (DocumentBuilder::build() refuses another page). */
    public function getPageId(): int
    {
        return $this->pageId;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    /** Raw src of the first image (may be a {{media}} directive); resolved per store view by the builder. */
    public function getFirstImage(): ?string
    {
        return $this->firstImage;
    }
}
