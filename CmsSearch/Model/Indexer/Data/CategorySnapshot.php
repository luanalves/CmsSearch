<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Data;

/**
 * The current version of a category in one store view (store values over defaults).
 */
final class CategorySnapshot
{
    /**
     * Display modes that render the landing page CMS block: Catalog\Block\Category\View::isContentMode()/isMixedMode()
     * compare the raw value with `==`, so the comparison is case-sensitive like the storefront's.
     */
    public const DISPLAY_MODES_WITH_PAGE = ['PAGE', 'PRODUCTS_AND_PAGE'];

    public function __construct(
        private readonly int $categoryId,
        private readonly string $path,
        private readonly int $level,
        private readonly string $name,
        private readonly string $description,
        private readonly int $landingPage,
        private readonly string $displayMode,
        private readonly bool $anchor,
        private readonly bool $active,
        private readonly ?string $requestPath,
        private readonly ?string $updatedAt
    ) {
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getLandingPage(): int
    {
        return $this->landingPage;
    }

    public function getDisplayMode(): string
    {
        return $this->displayMode;
    }

    public function isAnchor(): bool
    {
        return $this->anchor;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** url_rewrite request path in the store view, null when none exists. */
    public function getRequestPath(): ?string
    {
        return $this->requestPath;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /** The landing page CMS block the category page shows, 0 when its display mode shows none. */
    public function getLandingBlockId(): int
    {
        return in_array($this->displayMode, self::DISPLAY_MODES_WITH_PAGE, true) ? max(0, $this->landingPage) : 0;
    }

    /**
     * Shown in a store view whose root category is $rootCategoryId (Catalog\Helper\Category::canShow ->
     * isInRootCategoryList): below the root, never the root itself.
     */
    public function isInTree(int $rootCategoryId): bool
    {
        return $rootCategoryId > 0 && $this->level >= 2 && str_starts_with($this->path, '1/' . $rootCategoryId . '/');
    }
}
