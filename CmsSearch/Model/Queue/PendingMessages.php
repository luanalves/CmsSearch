<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;

/**
 * Reindex requests made inside a database transaction, per connection, until the outermost COMMIT (sent) or a
 * ROLLBACK (dropped). The first request with a given key wins, so one transaction sends one message per
 * entity/action/scope. Shared instance: filled by ReindexPublisher, emptied by FlushReindexMessagesPlugin.
 */
class PendingMessages
{
    /** @var array<string, array<string, ReindexMessageInterface>> connection id => key => message */
    private array $pending = [];

    public function add(string $connectionId, ReindexMessageInterface $message): void
    {
        $this->pending[$connectionId][$message->getKey()] ??= $message;
    }

    /**
     * @return list<ReindexMessageInterface> in the order they were first requested; the list is emptied
     */
    public function take(string $connectionId): array
    {
        $messages = array_values($this->pending[$connectionId] ?? []);
        unset($this->pending[$connectionId]);
        return $messages;
    }

    public function discard(string $connectionId): void
    {
        unset($this->pending[$connectionId]);
    }
}
