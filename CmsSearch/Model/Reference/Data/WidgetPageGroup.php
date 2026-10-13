<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * One row of widget_instance_page: where the widget instance is placed.
 */
final class WidgetPageGroup
{
    public const FOR_ALL = 'all';
    public const FOR_SPECIFIC = 'specific';

    /**
     * @param list<int> $entities category or product ids when page_for = specific
     */
    public function __construct(
        private readonly string $group,
        private readonly string $layoutHandle,
        private readonly string $pageFor,
        private readonly array $entities
    ) {
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function getLayoutHandle(): string
    {
        return $this->layoutHandle;
    }

    public function getPageFor(): string
    {
        return $this->pageFor;
    }

    /**
     * @return list<int>
     */
    public function getEntities(): array
    {
        return $this->entities;
    }

    public function isSpecific(): bool
    {
        return $this->pageFor === self::FOR_SPECIFIC;
    }
}
