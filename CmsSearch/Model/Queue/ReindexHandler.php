<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Exception\ReindexFailedException;

/**
 * Handler of the consumer thedevkitchen.cmssearch.reindex (etc/queue_consumer.xml).
 *
 * Returning acknowledges the message. Throwing makes Magento\Framework\MessageQueue\Consumer reject it with
 * requeue=false; the queue's dead-letter exchange then moves it to thedevkitchen.cmssearch.reindex.dlq. AMQP has no
 * automatic retry, so a failed message is republished with attempt + 1 until the configured cap (D10).
 */
class ReindexHandler
{
    public function __construct(
        private readonly MessageCodec $codec,
        private readonly ReindexProcessorInterface $processor,
        private readonly RetryPolicy $retryPolicy,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $message JSON written by MessageCodec::encode() (the topic's request type is "string")
     * @throws InvalidReindexMessageException permanent: unreadable payload or refused by the processor (DLQ, no retry)
     * @throws ReindexFailedException the last allowed attempt failed, or its retry could not be published (DLQ)
     */
    public function process(string $message): void
    {
        try {
            $request = $this->codec->decode($message);
        } catch (InvalidReindexMessageException $exception) {
            $this->logger->error(
                'dead-lettered invalid message: ' . $exception->getMessage(),
                ['body' => mb_substr($message, 0, 300)]
            );
            throw $exception;
        }

        try {
            $this->processor->process($request);
        } catch (\Throwable $exception) { // PHP errors too: the framework consumer only catches \Exception
            $this->handleFailure($request, $exception);
            return;
        }
        $this->logger->info('processed ' . $this->codec->encode($request));
    }

    /**
     * @throws InvalidReindexMessageException|ReindexFailedException when the message must be dead-lettered
     */
    private function handleFailure(ReindexMessageInterface $request, \Throwable $exception): void
    {
        $json = $this->codec->encode($request);
        if ($exception instanceof InvalidReindexMessageException) {
            $this->logger->error('dead-lettered ' . $json . ': ' . $exception->getMessage());
            throw $exception;
        }
        $tries = $request->getAttempt() + 1;
        if (!$this->retryPolicy->shouldRetry($request->getAttempt())) {
            $this->logger->error(
                sprintf('dead-lettered after %d attempt(s) %s: %s', $tries, $json, $exception->getMessage()),
                ['exception' => $exception]
            );
            throw new ReindexFailedException(
                sprintf('Reindex failed %d time(s); moved to the dead-letter queue.', $tries),
                0,
                $exception
            );
        }
        $next = $this->codec->encode($request->withAttempt($tries));
        try {
            $this->publisher->publish(ReindexMessageInterface::TOPIC, $next);
        } catch (\Throwable $publishFailure) {
            $this->logger->error(
                'dead-lettered, the retry could not be published ' . $json . ': ' . $publishFailure->getMessage(),
                ['exception' => $publishFailure]
            );
            throw new ReindexFailedException(
                'Reindex failed and its retry could not be published; moved to the dead-letter queue.',
                0,
                $publishFailure
            );
        }
        $this->logger->warning('retry scheduled ' . $next . ' after: ' . $exception->getMessage());
    }
}
