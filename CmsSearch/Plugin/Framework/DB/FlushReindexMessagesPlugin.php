<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Plugin\Framework\DB;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\PendingMessages;

/**
 * Sends the reindex requests held during a transaction once the OUTERMOST commit succeeded and drops them on any
 * rollback: the same rule as core's Magento\Framework\Model\ExecuteCommitCallbacks on the same interface.
 */
class FlushReindexMessagesPlugin
{
    public function __construct(
        private readonly PendingMessages $pending,
        private readonly MessageSender $sender
    ) {
    }

    public function afterCommit(AdapterInterface $subject, AdapterInterface $result): AdapterInterface
    {
        if ($subject->getTransactionLevel() === 0) {
            foreach ($this->pending->take(spl_object_hash($subject)) as $message) {
                $this->sender->send($message);
            }
        }
        return $result;
    }

    public function afterRollBack(AdapterInterface $subject, AdapterInterface $result): AdapterInterface
    {
        $this->pending->discard(spl_object_hash($subject));
        return $result;
    }
}
