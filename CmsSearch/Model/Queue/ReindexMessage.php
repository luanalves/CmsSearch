<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;

/**
 * Immutable and always valid: the constructor refuses anything the consumer could never process.
 */
final class ReindexMessage implements ReindexMessageInterface
{
    /** Sanity bound of the attempt counter (the configured cap is at most 10). */
    public const MAX_ATTEMPT = 100;

    /** Upper bound of any entity id (unsigned 32-bit column range). */
    public const MAX_ENTITY_ID = 4294967295;

    /** Upper bound of a page id: page_id is a signed smallint (FK to sequence_cms_page). */
    public const MAX_PAGE_ID = 32767;

    /** Upper bound of store_id and website_id (smallint unsigned range). */
    public const MAX_SCOPE_ID = 65535;

    /** Fixed ceiling of the timestamp (2100-01-01 UTC); a value object cannot compare with "now". The floor is 1. */
    public const MAX_TIMESTAMP = 4102444800;

    /** Longest caller-supplied string echoed in an exception message. */
    private const MAX_ECHO = 40;

    /**
     * @throws InvalidReindexMessageException
     */
    public function __construct(
        private readonly string $entityType,
        private readonly int $entityId,
        private readonly string $action,
        private readonly int $timestamp,
        private readonly ?int $storeId = null,
        private readonly ?int $websiteId = null,
        private readonly int $attempt = 0
    ) {
        $actions = self::ALLOWED_ACTIONS[$entityType] ?? null;
        if ($actions === null) {
            throw new InvalidReindexMessageException(
                sprintf('Unknown entity type %s.', self::quote($entityType))
            );
        }
        if (!in_array($action, $actions, true)) {
            throw new InvalidReindexMessageException(sprintf(
                'Action %s is not valid for entity type %s.',
                self::quote($action),
                self::quote($entityType)
            ));
        }
        $maxId = $entityType === self::ENTITY_PAGE ? self::MAX_PAGE_ID : self::MAX_ENTITY_ID;
        if ($entityId <= 0 || $entityId > $maxId) {
            throw new InvalidReindexMessageException(
                sprintf('The entity id must be a positive integer of at most %d.', $maxId)
            );
        }
        if ($storeId !== null && ($storeId <= 0 || $storeId > self::MAX_SCOPE_ID)) {
            throw new InvalidReindexMessageException(
                sprintf('The store id must be a positive integer of at most %d.', self::MAX_SCOPE_ID)
            );
        }
        if ($websiteId !== null && ($websiteId <= 0 || $websiteId > self::MAX_SCOPE_ID)) {
            throw new InvalidReindexMessageException(
                sprintf('The website id must be a positive integer of at most %d.', self::MAX_SCOPE_ID)
            );
        }
        if ($timestamp < 1 || $timestamp > self::MAX_TIMESTAMP) {
            throw new InvalidReindexMessageException(
                sprintf('The timestamp must be between 1 and %d.', self::MAX_TIMESTAMP)
            );
        }
        if ($attempt < 0 || $attempt > self::MAX_ATTEMPT) {
            throw new InvalidReindexMessageException(
                sprintf('The attempt must be between 0 and %d.', self::MAX_ATTEMPT)
            );
        }
    }

    /** Bounded, escaped (one line) rendering of a caller string for exception messages. */
    private static function quote(string $value): string
    {
        return json_encode(
            substr($value, 0, self::MAX_ECHO),
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '""';
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function getWebsiteId(): ?int
    {
        return $this->websiteId;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function withAttempt(int $attempt): static
    {
        return new self(
            $this->entityType,
            $this->entityId,
            $this->action,
            $this->timestamp,
            $this->storeId,
            $this->websiteId,
            $attempt
        );
    }

    public function getKey(): string
    {
        return sprintf(
            '%s|%d|%s|%s|%s',
            $this->entityType,
            $this->entityId,
            $this->action,
            $this->storeId ?? '*',
            $this->websiteId ?? '*'
        );
    }

    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'action' => $this->action,
            'store_id' => $this->storeId,
            'website_id' => $this->websiteId,
            'timestamp' => $this->timestamp,
            'attempt' => $this->attempt,
        ];
    }
}
