<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Plugin\Framework\DB;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Queue\MessageCodec;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\PendingMessages;

/**
 * Sends the reindex requests held during a transaction once the OUTERMOST commit succeeded and drops them on any
 * rollback: the same rule as core's Magento\Framework\Model\ExecuteCommitCallbacks on the same interface.
 *
 * Runs AFTER the real COMMIT, so nothing may escape from here: an exception would make the caller roll back at level 0
 * ("Asymmetric transaction rollback") and report a failed save for data that is committed. Every send and every log
 * call is guarded. A Throwable from one send (e.g. the sender could not be built) is logged and the others are still
 * sent; once the broker refuses one message (send() returns false) the rest of this flush is logged as skipped without
 * trying, so a broker outage costs a commit at most one connection attempt.
 */
class FlushReindexMessagesPlugin
{
    public function __construct(
        private readonly PendingMessages $pending,
        private readonly MessageSender $sender,
        private readonly MessageCodec $codec,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterCommit(AdapterInterface $subject, AdapterInterface $result): AdapterInterface
    {
        if ($subject->getTransactionLevel() === 0) {
            $brokerDown = false;
            foreach ($this->pending->take(spl_object_hash($subject)) as $message) {
                if ($brokerDown) {
                    $this->log($message, 'skipped, broker unavailable');
                    continue;
                }
                try {
                    $brokerDown = !$this->sender->send($message);
                } catch (\Throwable $exception) {
                    $this->log($message, $exception->getMessage(), $exception);
                }
            }
        }
        return $result;
    }

    public function afterRollBack(AdapterInterface $subject, AdapterInterface $result): AdapterInterface
    {
        $this->pending->discard(spl_object_hash($subject));
        return $result;
    }

    private function log(ReindexMessageInterface $message, string $reason, ?\Throwable $exception = null): void
    {
        try {
            $this->logger->error(
                'publish failed ' . $this->codec->encode($message) . ': ' . $reason,
                $exception === null ? [] : ['exception' => $exception]
            );
        } catch (\Throwable) {
            // Logging must never fail a committed transaction.
        }
    }
}
