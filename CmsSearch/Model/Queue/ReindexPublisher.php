<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * See ReindexPublisherInterface. Inside a transaction the request waits in PendingMessages and
 * FlushReindexMessagesPlugin sends it after the outermost COMMIT (or drops it on ROLLBACK).
 */
class ReindexPublisher implements ReindexPublisherInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PendingMessages $pending,
        private readonly MessageSender $sender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function publish(
        string $entityType,
        int $entityId,
        string $action,
        ?int $storeId = null,
        ?int $websiteId = null
    ): void {
        try {
            $message = new ReindexMessage($entityType, $entityId, $action, time(), $storeId, $websiteId);
            $connection = $this->resourceConnection->getConnection();
            if ($connection->getTransactionLevel() > 0) {
                $this->pending->add(spl_object_hash($connection), $message);
                return;
            }
            $this->sender->send($message);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('reindex request dropped (%s %d %s): %s', $entityType, $entityId, $action, $exception->getMessage()),
                ['exception' => $exception]
            );
        }
    }
}
