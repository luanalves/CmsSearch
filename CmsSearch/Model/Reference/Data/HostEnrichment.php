<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * What the blocks published on a host add to its document: their text, and the segment ids of the excluded
 * segment-restricted Dynamic Blocks (D7: stored, not searchable text).
 */
final class HostEnrichment
{
    /**
     * @param list<int> $segmentIds
     * @param bool $truncated the text was cut at the per-host cap (or a block's own extraction cap)
     */
    public function __construct(
        private readonly string $text,
        private readonly array $segmentIds,
        private readonly bool $truncated = false
    ) {
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return list<int>
     */
    public function getSegmentIds(): array
    {
        return $this->segmentIds;
    }
}
