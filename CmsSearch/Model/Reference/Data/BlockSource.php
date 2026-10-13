<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * A block a store view DISPLAYS (current version, active, store-scoped), with its raw content and, for a Dynamic
 * Block, what makes it visitor-dependent: the customer segments that restrict it (D7) or a link to a cart/catalog
 * price rule (shown only when the rule applies to the visitor; beyond the literal D7, same treatment). An EXCLUDED
 * block adds no text and is not followed in v1; its graph row is kept.
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
        private readonly array $segmentIds = [],
        private readonly bool $ruleRestricted = false
    ) {
    }

    /** Linked to a sales rule or catalog rule (magento_banner_salesrule / magento_banner_catalogrule). */
    public function isRuleRestricted(): bool
    {
        return $this->ruleRestricted;
    }

    /** Visitor-dependent (segments or price rules): no text, not followed. */
    public function isExcluded(): bool
    {
        return $this->segmentIds !== [] || $this->ruleRestricted;
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
