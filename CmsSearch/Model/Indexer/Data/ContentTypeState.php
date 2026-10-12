<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Data;

final class ContentTypeState
{
    public function __construct(
        private readonly int $typeId,
        private readonly string $code,
        private readonly bool $isActive
    ) {
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
