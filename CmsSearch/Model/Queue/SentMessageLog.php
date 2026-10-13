<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;

/**
 * Entities this process sent an UNSCOPED reindex message for while a Staging application runs (opened and closed by
 * Plugin\Staging\OpenSentMessageLog around StagingApplier::execute()). StagingApplier switches the current version
 * first, re-saves every applied entity through its repository (the block and page save observers publish one "save"
 * per entity, sent after each commit and recorded here by MessageSender) and only then runs the post-processors:
 * PageVersionApplied / ReferenceVersionApplied skip an entity recorded here, because its message was published after
 * the version switch and the consumer always reads the current version (the action is only a hint). Store- or
 * website-scoped messages are never recorded (they do not reindex every store view). Memory: one key per entity sent
 * during the run, emptied when the run ends.
 */
class SentMessageLog implements ResetAfterRequestInterface
{
    private bool $open = false;
    /** @var array<string, true> "<entity_type>#<entity_id>" */
    private array $sent = [];

    public function open(): void
    {
        $this->open = true;
        $this->sent = [];
    }

    public function close(): void
    {
        $this->open = false;
        $this->sent = [];
    }

    public function record(ReindexMessageInterface $message): void
    {
        if (!$this->open || $message->getStoreId() !== null || $message->getWebsiteId() !== null) {
            return;
        }
        $this->sent[$message->getEntityType() . '#' . $message->getEntityId()] = true;
    }

    public function wasSent(string $entityType, int $entityId): bool
    {
        return $this->open && isset($this->sent[$entityType . '#' . $entityId]);
    }

    public function _resetState(): void
    {
        $this->close();
    }
}
