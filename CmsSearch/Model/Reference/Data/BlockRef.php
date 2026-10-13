<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * A reference to a CMS block (by block_id or identifier) or to a Dynamic Block (by banner_id), as written in content
 * or in a widget instance. It says nothing about whether the block shows: that is decided per store view at read time.
 */
final class BlockRef
{
    public const KIND_BLOCK = 'block';
    public const KIND_DYNAMIC_BLOCK = 'dynamic_block';
    public const KINDS = [self::KIND_BLOCK, self::KIND_DYNAMIC_BLOCK];

    public function __construct(
        private readonly string $kind,
        private readonly int $id,
        private readonly string $identifier = ''
    ) {
        if (!in_array($kind, self::KINDS, true) || $id < 0 || ($id === 0) === ($identifier === '')
            || ($kind === self::KIND_DYNAMIC_BLOCK && $id === 0)) {
            throw new \InvalidArgumentException('A block reference needs a kind and either an id or an identifier.');
        }
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /** 0 when the block is referenced by identifier. */
    public function getId(): int
    {
        return $this->id;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function isByIdentifier(): bool
    {
        return $this->id === 0;
    }

    /** Identity of the reference; identifiers compare case-insensitively like the database collation. */
    public function key(): string
    {
        return $this->id > 0
            ? $this->kind . '#' . $this->id
            : $this->kind . '@' . mb_strtolower($this->identifier);
    }
}
