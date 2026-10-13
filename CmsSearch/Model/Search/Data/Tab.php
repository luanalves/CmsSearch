<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search\Data;

/**
 * One tab of the search result page. Products is the native page; categories and content tabs come from the CMS
 * index (one content tab per active editorial content type, D11).
 */
final class Tab
{
    public const KIND_PRODUCTS = 'products';
    public const KIND_CATEGORIES = 'categories';
    public const KIND_CONTENT = 'content';

    public function __construct(
        private readonly string $code,
        private readonly string $kind,
        private readonly string $label,
        private readonly ?int $count
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** Matching results; null when unknown here (the Products count is read by the tab bar block). */
    public function getCount(): ?int
    {
        return $this->count;
    }

    public function isCms(): bool
    {
        return $this->kind !== self::KIND_PRODUCTS;
    }
}
