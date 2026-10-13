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

    /** Identity of the reference; identifiers compare like the database collation (see foldIdentifier()). */
    public function key(): string
    {
        return $this->id > 0
            ? $this->kind . '#' . $this->id
            : $this->kind . '@' . self::foldIdentifier($this->identifier);
    }

    /**
     * Approximates utf8mb4_general_ci, the collation of cms_block.identifier and of the graph's unique key: case and
     * accents are ignored (canonical decomposition, combining marks removed) and ß equals s. What remains different
     * (letters without a decomposition, e.g. ø, æ) is still merged by the database at insert (ReferenceStore).
     */
    public static function foldIdentifier(string $identifier): string
    {
        $decomposed = \Normalizer::normalize($identifier, \Normalizer::FORM_D);
        $plain = preg_replace('/\p{Mn}+/u', '', is_string($decomposed) ? $decomposed : $identifier) ?? $identifier;
        return str_replace('ß', 's', mb_strtolower($plain));
    }
}
