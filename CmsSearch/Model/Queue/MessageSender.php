<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;

/**
 * Sends one reindex message to the broker now. Never throws: a failure (broker down, wrong credentials...) is logged
 * with the message, so it can be replayed with bin/magento thedevkitchen:cmssearch:reindex, and false is returned.
 */
class MessageSender
{
    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly MessageCodec $codec,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(ReindexMessageInterface $message): bool
    {
        $json = $this->codec->encode($message);
        try {
            $this->publisher->publish(ReindexMessageInterface::TOPIC, $json);
            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('publish failed ' . $json . ': ' . $exception->getMessage(), ['exception' => $exception]);
            return false;
        }
    }
}
