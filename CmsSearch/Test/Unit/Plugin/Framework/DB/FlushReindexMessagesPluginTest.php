<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Plugin\Framework\DB;

use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Queue\MessageCodec;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\PendingMessages;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Plugin\Framework\DB\FlushReindexMessagesPlugin;

class FlushReindexMessagesPluginTest extends TestCase
{
    private PendingMessages $pending;
    private MessageSender&MockObject $sender;
    private AdapterInterface&MockObject $connection;
    private LoggerInterface&MockObject $logger;
    private int $level = 0;
    private string $id;

    protected function setUp(): void
    {
        $this->pending = new PendingMessages();
        $this->sender = $this->createMock(MessageSender::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTransactionLevel')->willReturnCallback(fn (): int => $this->level);
        $this->id = spl_object_hash($this->connection);
    }

    private function plugin(): FlushReindexMessagesPlugin
    {
        return new FlushReindexMessagesPlugin($this->pending, $this->sender, new MessageCodec(), $this->logger);
    }

    public function testTheOutermostCommitSendsThePendingMessagesInOrder(): void
    {
        $this->pending->add($this->id, new ReindexMessage('page', 1, 'save', 1));
        $this->pending->add($this->id, new ReindexMessage('page', 2, 'save', 1));
        $sent = [];
        $this->sender->expects($this->exactly(2))->method('send')
            ->willReturnCallback(function (ReindexMessageInterface $message) use (&$sent): bool {
                $sent[] = $message->getEntityId();
                return true;
            });
        $this->assertSame($this->connection, $this->plugin()->afterCommit($this->connection, $this->connection));
        $this->assertSame([1, 2], $sent);
        $this->assertSame([], $this->pending->take($this->id));
    }

    public function testANestedCommitKeepsThemPending(): void
    {
        $this->level = 1;
        $this->pending->add($this->id, new ReindexMessage('page', 1, 'save', 1));
        $this->sender->expects($this->never())->method('send');
        $this->plugin()->afterCommit($this->connection, $this->connection);
        $this->assertCount(1, $this->pending->take($this->id));
    }

    public function testARollbackAtAnyLevelDropsThem(): void
    {
        $this->level = 1;
        $this->pending->add($this->id, new ReindexMessage('page', 1, 'save', 1));
        $this->sender->expects($this->never())->method('send');
        $this->assertSame($this->connection, $this->plugin()->afterRollBack($this->connection, $this->connection));
        $this->assertSame([], $this->pending->take($this->id));
    }

    public function testACommitOnAnotherConnectionDoesNotFlushThisOne(): void
    {
        $other = $this->createMock(AdapterInterface::class);
        $other->method('getTransactionLevel')->willReturn(0);
        $this->pending->add($this->id, new ReindexMessage('page', 1, 'save', 1));
        $this->sender->expects($this->never())->method('send');
        $this->plugin()->afterCommit($other, $other);
        $this->assertCount(1, $this->pending->take($this->id));
    }

    private static function json(int $id): string
    {
        return (new MessageCodec())->encode(new ReindexMessage('content_type', $id, 'save', 1));
    }

    /** @param list<int> $ids */
    private function park(array $ids): void
    {
        foreach ($ids as $id) {
            $this->pending->add($this->id, new ReindexMessage('content_type', $id, 'save', 1));
        }
    }

    public function testAnErrorWhileSendingOneMessageIsLoggedAndTheOthersAreStillSent(): void
    {
        $this->park([1, 2]);
        $sent = [];
        $this->sender->expects($this->exactly(2))->method('send')
            ->willReturnCallback(function (ReindexMessageInterface $message) use (&$sent): bool {
                if ($message->getEntityId() === 1) {
                    throw new \Error('proxy could not be built');
                }
                $sent[] = $message->getEntityId();
                return true;
            });
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('publish failed ' . self::json(1) . ': proxy could not be built'));
        $this->assertSame($this->connection, $this->plugin()->afterCommit($this->connection, $this->connection));
        $this->assertSame([2], $sent);
        $this->assertSame([], $this->pending->take($this->id));
    }

    public function testAfterTheFirstBrokerFailureTheRestIsSkippedAndLogged(): void
    {
        $this->park([1, 2, 3]);
        $this->sender->expects($this->once())->method('send')->willReturn(false);   // the sender logged its own failure
        $logged = [];
        $this->logger->expects($this->exactly(2))->method('error')
            ->willReturnCallback(function (string $line) use (&$logged): void {
                $logged[] = $line;
            });
        $this->assertSame($this->connection, $this->plugin()->afterCommit($this->connection, $this->connection));
        $this->assertSame(
            [
                'publish failed ' . self::json(2) . ': skipped, broker unavailable',
                'publish failed ' . self::json(3) . ': skipped, broker unavailable',
            ],
            $logged
        );
        $this->assertSame([], $this->pending->take($this->id));
    }

    public function testAFailingLoggerCannotFailTheCommit(): void
    {
        $this->park([1, 2]);
        $sent = [];
        $this->sender->method('send')->willReturnCallback(function (ReindexMessageInterface $message) use (&$sent): bool {
            if ($message->getEntityId() === 1) {
                throw new \Error('boom');
            }
            $sent[] = $message->getEntityId();
            return true;
        });
        $this->logger->method('error')->willThrowException(new \UnexpectedValueException('log file not writable'));
        $this->assertSame($this->connection, $this->plugin()->afterCommit($this->connection, $this->connection));
        $this->assertSame([2], $sent);
    }
}
