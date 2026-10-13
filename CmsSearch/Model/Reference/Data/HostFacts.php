<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * What a widget placement can test on a host: page identifier, product type, category anchor flag.
 */
final class HostFacts
{
    public function __construct(
        private readonly string $hostType,
        private readonly int $hostId,
        private readonly string $identifier = '',
        private readonly string $productType = '',
        private readonly bool $anchor = false
    ) {
    }

    public function getHostType(): string
    {
        return $this->hostType;
    }

    public function getHostId(): int
    {
        return $this->hostId;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getProductType(): string
    {
        return $this->productType;
    }

    public function isAnchor(): bool
    {
        return $this->anchor;
    }
}
