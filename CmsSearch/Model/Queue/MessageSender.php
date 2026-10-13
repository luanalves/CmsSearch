<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;

/**
 * Sends one reindex message to the broker now. Never throws: a failure (broker down, wrong credentials, an encoding
 * error...) is logged with the message, so it can be replayed with bin/magento thedevkitchen:cmssearch:reindex, and
 * false is returned. Even a failing logger is swallowed. A message sent while a Staging application runs is recorded
 * in SentMessageLog.
 */
class MessageSender
{
    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly MessageCodec $codec,
        private readonly LoggerInterface $logger,
        private readonly SentMessageLog $sentLog
    ) {
    }

    public function send(ReindexMessageInterface $message): bool
    {
        $json = null;
        try {
            $json = $this->codec->encode($message);
            $this->publisher->publish(ReindexMessageInterface::TOPIC, $json);
            $this->sentLog->record($message);
            return true;
        } catch (\Throwable $exception) {
            try {
                $this->logger->error(
                    'publish failed ' . ($json ?? '(unencodable message)') . ': ' . $exception->getMessage(),
                    ['exception' => $exception]
                );
            } catch (\Throwable) {
                // Never throw to the caller, not even for a broken log.
            }
            return false;
        }
    }
}
