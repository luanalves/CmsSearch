<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\MessageQueue\ConnectionLostException;
use Magento\Framework\MessageQueue\MessageLockException;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Exception\ReindexFailedException;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Queue\MessageCodec;
use Thedevkitchen\CmsSearch\Model\Queue\NullReindexProcessor;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexHandler;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexProcessorInterface;
use Thedevkitchen\CmsSearch\Model\Queue\RetryPolicy;

class ReindexHandlerTest extends TestCase
{
    private ReindexProcessorInterface&MockObject $processor;
    private PublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private int $maxAttempts = 3;

    protected function setUp(): void
    {
        $this->processor = $this->createMock(ReindexProcessorInterface::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function handler(): ReindexHandler
    {
        $config = $this->createMock(Config::class);
        $config->method('getMaxAttempts')->willReturnCallback(fn (): int => $this->maxAttempts);
        return new ReindexHandler(
            new MessageCodec(),
            $this->processor,
            new RetryPolicy($config),
            $this->publisher,
            $this->logger
        );
    }

    private static function body(int $attempt = 0, int $id = 7): string
    {
        return (new MessageCodec())->encode(new ReindexMessage('page', $id, 'save', 1760000000, null, null, $attempt));
    }

    public function testAValidMessageReachesTheProcessorAndIsAcknowledged(): void
    {
        $this->processor->expects($this->once())->method('process')->with($this->callback(
            static fn (ReindexMessageInterface $message): bool => $message->toArray() === [
                'entity_type' => 'page', 'entity_id' => 7, 'action' => 'save', 'store_id' => null,
                'website_id' => null, 'timestamp' => 1760000000, 'attempt' => 0,
            ]
        ));
        $this->publisher->expects($this->never())->method('publish');
        $this->logger->expects($this->once())->method('info')->with('processed ' . self::body());
        $this->handler()->process(self::body());
    }

    public function testAnInvalidBodyGoesStraightToTheDlq(): void
    {
        $this->processor->expects($this->never())->method('process');
        $this->publisher->expects($this->never())->method('publish');
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('dead-lettered invalid message'));
        $this->expectException(InvalidReindexMessageException::class);
        $this->handler()->process('{"entity_type":"blog"}');
    }

    /** @return array<string, array{0: int}> */
    public static function retriedAttempts(): array
    {
        return ['first try failed' => [0], 'second try failed' => [1]];
    }

    #[DataProvider('retriedAttempts')]
    public function testAFailureIsRepublishedWithTheNextAttemptAndAcknowledged(int $attempt): void
    {
        $this->processor->method('process')->willThrowException(new \RuntimeException('OpenSearch timeout'));
        $this->publisher->expects($this->once())->method('publish')
            ->with(ReindexMessageInterface::TOPIC, self::body($attempt + 1));
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('retry scheduled ' . self::body($attempt + 1) . ' after: OpenSearch timeout'));
        $this->handler()->process(self::body($attempt)); // returns normally: the original is acknowledged
    }

    public function testTheThirdFailureIsDeadLettered(): void
    {
        $failure = new \RuntimeException('OpenSearch timeout');
        $this->processor->method('process')->willThrowException($failure);
        $this->publisher->expects($this->never())->method('publish');
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('dead-lettered after 3 attempt(s) ' . self::body(2)));
        try {
            $this->handler()->process(self::body(2));
            $this->fail('expected ReindexFailedException');
        } catch (ReindexFailedException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function testACapOfOneMeansNoRetry(): void
    {
        $this->maxAttempts = 1;
        $this->processor->method('process')->willThrowException(new \RuntimeException('x'));
        $this->publisher->expects($this->never())->method('publish');
        $this->expectException(ReindexFailedException::class);
        $this->handler()->process(self::body(0));
    }

    public function testAPermanentErrorFromTheProcessorIsNotRetried(): void
    {
        $permanent = new InvalidReindexMessageException('page 7 has a corrupt classification');
        $this->processor->method('process')->willThrowException($permanent);
        $this->publisher->expects($this->never())->method('publish');
        try {
            $this->handler()->process(self::body(0));
            $this->fail('expected InvalidReindexMessageException');
        } catch (InvalidReindexMessageException $exception) {
            $this->assertSame($permanent, $exception);
        }
    }

    public function testPhpErrorsAreTransientFailuresToo(): void
    {
        $this->processor->method('process')->willThrowException(new \TypeError('bad argument'));
        $this->publisher->expects($this->once())->method('publish')->with(ReindexMessageInterface::TOPIC, self::body(1));
        $this->handler()->process(self::body(0));
    }

    public function testAFailedRepublishSendsTheOriginalToTheDlq(): void
    {
        $this->processor->method('process')->willThrowException(new \RuntimeException('x'));
        $down = new \RuntimeException('broker down');
        $this->publisher->method('publish')->willThrowException($down);
        try {
            $this->handler()->process(self::body(0));
            $this->fail('expected ReindexFailedException');
        } catch (ReindexFailedException $exception) {
            $this->assertSame($down, $exception->getPrevious());
        }
    }

    public function testItsExceptionsAreOnesTheFrameworkConsumerRejectsToTheDlq(): void
    {
        foreach ([InvalidReindexMessageException::class, ReindexFailedException::class] as $class) {
            $this->assertTrue(is_a($class, \Exception::class, true), $class);
            foreach ([NotFoundException::class, MessageLockException::class, ConnectionLostException::class] as $dropped) {
                $this->assertFalse(is_a($class, $dropped, true), "$class must not be a $dropped");
            }
        }
    }

    public function testThePlaceholderProcessorAcceptsEveryMessage(): void
    {
        $processor = new NullReindexProcessor();
        $this->assertInstanceOf(ReindexProcessorInterface::class, $processor);
        $processor->process(new ReindexMessage('page', 1, 'delete', 1));
        $this->addToAssertionCount(1);
    }
}
