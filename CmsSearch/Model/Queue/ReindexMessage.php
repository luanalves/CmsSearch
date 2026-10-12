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
            throw new InvalidReindexMessageException(sprintf('Unknown entity type "%s".', $entityType));
        }
        if (!in_array($action, $actions, true)) {
            throw new InvalidReindexMessageException(
                sprintf('Action "%s" is not valid for entity type "%s".', $action, $entityType)
            );
        }
        if ($entityId <= 0) {
            throw new InvalidReindexMessageException('The entity id must be a positive integer.');
        }
        if ($storeId !== null && $storeId <= 0) {
            throw new InvalidReindexMessageException('The store id must be a positive integer.');
        }
        if ($websiteId !== null && $websiteId <= 0) {
            throw new InvalidReindexMessageException('The website id must be a positive integer.');
        }
        if ($timestamp < 0) {
            throw new InvalidReindexMessageException('The timestamp must not be negative.');
        }
        if ($attempt < 0 || $attempt > self::MAX_ATTEMPT) {
            throw new InvalidReindexMessageException(
                sprintf('The attempt must be between 0 and %d.', self::MAX_ATTEMPT)
            );
        }
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
