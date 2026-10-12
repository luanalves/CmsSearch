<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api\Data;

/**
 * One asynchronous reindex request (D10). Scalars only, never content: the consumer re-reads the current database
 * state, so duplicate and out-of-order messages are harmless. The action is a hint: the consumer decides from the
 * current state (for example a "delete" sent when a Staging version is removed refers to a page that still exists).
 */
interface ReindexMessageInterface
{
    /** Topic, routing key, queue and consumer name (the same string). */
    public const TOPIC = 'thedevkitchen.cmssearch.reindex';

    public const ENTITY_PAGE = 'page';
    public const ENTITY_CONTENT_TYPE = 'content_type';
    public const ENTITY_CLASSIFICATION_TERM = 'classification_term';

    public const ACTION_SAVE = 'save';
    public const ACTION_DELETE = 'delete';
    public const ACTION_STAGING = 'staging';

    /**
     * Entity type => accepted actions. A classification_term "save" means "the term or its place in the tree
     * changed": the consumer reindexes the pages of the term's CURRENT subtree. Plan 6 adds block/product/category.
     */
    public const ALLOWED_ACTIONS = [
        self::ENTITY_PAGE => [self::ACTION_SAVE, self::ACTION_DELETE, self::ACTION_STAGING],
        self::ENTITY_CONTENT_TYPE => [self::ACTION_SAVE, self::ACTION_DELETE],
        self::ENTITY_CLASSIFICATION_TERM => [self::ACTION_SAVE, self::ACTION_DELETE],
    ];

    public function getEntityType(): string;

    public function getEntityId(): int;

    public function getAction(): string;

    /** Store view to limit the work to; null = every store view of the entity. */
    public function getStoreId(): ?int;

    /** Website of the entity when it has one (classification terms); otherwise null. */
    public function getWebsiteId(): ?int;

    /** Unix time of the first publication (kept across retries). */
    public function getTimestamp(): int;

    /** Zero-based processing attempt: 0 = first try. */
    public function getAttempt(): int;

    /** The same request for another attempt (the object itself never changes). */
    public function withAttempt(int $attempt): static;

    /** Identity used to merge duplicates inside one transaction: entity, action and scope (no time, no attempt). */
    public function getKey(): string;

    /**
     * @return array{entity_type: string, entity_id: int, action: string, store_id: ?int, website_id: ?int, timestamp: int, attempt: int}
     */
    public function toArray(): array;
}
