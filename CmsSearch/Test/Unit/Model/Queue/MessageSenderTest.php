<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Model\Queue\MessageCodec;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;

class MessageSenderTest extends TestCase
{
    private const JSON = '{"entity_type":"page","entity_id":7,"action":"save","store_id":null,"website_id":null,"timestamp":1760000000,"attempt":0}';

    public function testSendsTheJsonOnTheTopic(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')->with('thedevkitchen.cmssearch.reindex', self::JSON);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $sender = new MessageSender($publisher, new MessageCodec(), $logger);
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
        $sender = new MessageSender($publisher, new MessageCodec(), $logger);
        $this->assertFalse($sender->send(new ReindexMessage('page', 7, 'save', 1760000000)));
    }
}
