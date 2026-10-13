<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * A host as the reference graph sees it in one store view: its raw HTML fields, the references that are not
 * directives (a category's landing page block) and the facts widget placements test.
 */
final class HostSource
{
    /**
     * @param list<string> $contents
     * @param list<BlockRef> $extraRefs
     */
    public function __construct(
        private readonly HostFacts $facts,
        private readonly array $contents,
        private readonly array $extraRefs = []
    ) {
    }

    public function getFacts(): HostFacts
    {
        return $this->facts;
    }

    /**
     * @return list<string>
     */
    public function getContents(): array
    {
        return $this->contents;
    }

    /**
     * @return list<BlockRef>
     */
    public function getExtraRefs(): array
    {
        return $this->extraRefs;
    }
}
