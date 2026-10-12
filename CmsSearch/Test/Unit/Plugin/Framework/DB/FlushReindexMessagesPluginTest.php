<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Plugin\Framework\DB;

use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\PendingMessages;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Plugin\Framework\DB\FlushReindexMessagesPlugin;

class FlushReindexMessagesPluginTest extends TestCase
{
    private PendingMessages $pending;
    private MessageSender&MockObject $sender;
    private AdapterInterface&MockObject $connection;
    private int $level = 0;
    private string $id;

    protected function setUp(): void
    {
        $this->pending = new PendingMessages();
        $this->sender = $this->createMock(MessageSender::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTransactionLevel')->willReturnCallback(fn (): int => $this->level);
        $this->id = spl_object_hash($this->connection);
    }

    private function plugin(): FlushReindexMessagesPlugin
    {
        return new FlushReindexMessagesPlugin($this->pending, $this->sender);
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
}
