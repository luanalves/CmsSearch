<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * A block a store view DISPLAYS (current version, active, store-scoped), with its raw content and, for a Dynamic
 * Block, the customer segments that restrict it (D7: such a block adds no text in v1).
 */
final class BlockSource
{
    /**
     * @param list<int> $segmentIds
     */
    public function __construct(
        private readonly string $kind,
        private readonly int $id,
        private readonly string $identifier,
        private readonly string $content,
        private readonly array $segmentIds = []
    ) {
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return list<int>
     */
    public function getSegmentIds(): array
    {
        return $this->segmentIds;
    }

    public function hasSegments(): bool
    {
        return $this->segmentIds !== [];
    }

    public function key(): string
    {
        return $this->kind . '#' . $this->id;
    }
}
