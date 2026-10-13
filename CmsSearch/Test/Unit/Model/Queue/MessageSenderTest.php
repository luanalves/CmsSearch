<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Queue\MessageCodec;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Queue\SentMessageLog;

class MessageSenderTest extends TestCase
{
    private const JSON = '{"entity_type":"page","entity_id":7,"action":"save","store_id":null,"website_id":null,"timestamp":1760000000,"attempt":0}';

    public function testSendsTheJsonOnTheTopic(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')->with('thedevkitchen.cmssearch.reindex', self::JSON);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $sender = new MessageSender($publisher, new MessageCodec(), $logger, new SentMessageLog());
        $this->assertTrue($sender->send(new ReindexMessage('page', 7, 'save', 1760000000)));
    }

    /** @return array<string, array{0: \Throwable}> */
    public static function failures(): array
    {
        return [
            'broker unreachable' => [new \RuntimeException('Connection refused')],
            'PHP error' => [new \TypeError('bad argument')],
        ];
    }

    #[DataProvider('failures')]
    public function testAFailureIsLoggedWithTheMessageAndNeverThrown(\Throwable $failure): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->method('publish')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('publish failed ' . self::JSON . ': ' . $failure->getMessage()));
        $sender = new MessageSender($publisher, new MessageCodec(), $logger, new SentMessageLog());
        $this->assertFalse($sender->send(new ReindexMessage('page', 7, 'save', 1760000000)));
    }

    public function testAnEncodingErrorIsLoggedAndNeverThrown(): void
    {
        $message = $this->createMock(ReindexMessageInterface::class);
        $message->method('toArray')->willThrowException(new \Error('cannot encode'));
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('publish failed (unencodable message): cannot encode'));
        $this->assertFalse((new MessageSender($publisher, new MessageCodec(), $logger, new SentMessageLog()))->send($message));
    }

    public function testAFailingLoggerIsNeverThrownEither(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('Connection refused'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \UnexpectedValueException('log file not writable'));
        $this->assertFalse((new MessageSender($publisher, new MessageCodec(), $logger, new SentMessageLog()))->send(new ReindexMessage('page', 7, 'save', 1760000000)));
    }

    public function testASentMessageIsRecordedAndAFailedOneIsNot(): void
    {
        $log = new SentMessageLog();
        $log->open();
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(static function (string $topic, string $json): void {
            if (str_contains($json, '"entity_id":8')) {
                throw new \RuntimeException('broker down');
            }
        });
        $sender = new MessageSender($publisher, new MessageCodec(), $this->createMock(LoggerInterface::class), $log);
        $this->assertTrue($sender->send(new ReindexMessage('block', 7, 'save', 1760000000)));
        $this->assertFalse($sender->send(new ReindexMessage('block', 8, 'save', 1760000000)));
        $this->assertTrue($log->wasSent('block', 7));
        $this->assertFalse($log->wasSent('block', 8));
    }
}
