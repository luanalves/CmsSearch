<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search\Data;

/**
 * One result card, as stored in the index (plain text; escaping is the template's job).
 */
final class Card
{
    public function __construct(
        private readonly string $entityType,
        private readonly string $title,
        private readonly string $url,
        private readonly ?string $image,
        private readonly string $summary,
        private readonly ?string $contentTypeCode
    ) {
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** Absolute http(s) URL, or '' when the indexed value is not one (the card is shown without link). */
    public function getUrl(): string
    {
        return $this->url;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getContentTypeCode(): ?string
    {
        return $this->contentTypeCode;
    }
}
