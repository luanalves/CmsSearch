<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * A CMS Block or Dynamic Blocks widget instance: one theme, some store views (0 = all), parameters, placements.
 */
final class WidgetInstance
{
    /**
     * @param list<int> $storeIds
     * @param array<string, mixed> $parameters decoded widget_parameters
     * @param list<WidgetPageGroup> $pageGroups
     */
    public function __construct(
        private readonly int $id,
        private readonly string $type,
        private readonly int $themeId,
        private readonly array $storeIds,
        private readonly array $parameters,
        private readonly array $pageGroups
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getThemeId(): int
    {
        return $this->themeId;
    }

    /**
     * @return list<int>
     */
    public function getStoreIds(): array
    {
        return $this->storeIds;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return list<WidgetPageGroup>
     */
    public function getPageGroups(): array
    {
        return $this->pageGroups;
    }
}
