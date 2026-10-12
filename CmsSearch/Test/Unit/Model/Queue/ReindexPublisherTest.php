<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Queue\MessageCodec;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\PendingMessages;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexPublisher;
use Thedevkitchen\CmsSearch\Plugin\Framework\DB\FlushReindexMessagesPlugin;

class ReindexPublisherTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private MessageSender&MockObject $sender;
    private LoggerInterface&MockObject $logger;
    private PendingMessages $pending;
    private int $level = 0;
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTransactionLevel')->willReturnCallback(fn (): int => $this->level);
        $this->sender = $this->createMock(MessageSender::class);
        $this->sender->method('send')->willReturnCallback(function (ReindexMessageInterface $message): bool {
            $this->sent[] = $message->toArray();
            return true;
        });
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->pending = new PendingMessages();
    }

    private function publisher(): ReindexPublisher
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        return new ReindexPublisher($resource, $this->pending, $this->sender, $this->logger);
    }

    private function plugin(): FlushReindexMessagesPlugin
    {
        return new FlushReindexMessagesPlugin($this->pending, $this->sender, new MessageCodec(), $this->logger);
    }

    public function testOutsideATransactionTheMessageIsSentAtOnce(): void
    {
        $before = time();
        $this->publisher()->publish('classification_term', 9, 'save', null, 2);
        $this->assertCount(1, $this->sent);
        $sent = $this->sent[0];
        $this->assertGreaterThanOrEqual($before, $sent['timestamp']);
        $this->assertLessThanOrEqual(time(), $sent['timestamp']);
        unset($sent['timestamp']);
        $this->assertSame(
            ['entity_type' => 'classification_term', 'entity_id' => 9, 'action' => 'save', 'store_id' => null,
                'website_id' => 2, 'attempt' => 0],
            $sent
        );
    }

    public function testInsideATransactionNothingIsSentBeforeTheCommit(): void
    {
        $this->level = 1;
        $this->publisher()->publish('page', 7, 'save');
        $this->assertSame([], $this->sent);
    }

    public function testOneTransactionSendsOneMessagePerEntityAndActionAfterTheOutermostCommit(): void
    {
        $publisher = $this->publisher();
        $this->level = 2;                              // e.g. a page save nested in an outer transaction
        $publisher->publish('page', 7, 'save');        // page save hook
        $publisher->publish('page', 7, 'save');        // page link hook of the same save
        $publisher->publish('classification_term', 4, 'save', null, 2);
        $publisher->publish('page', 7, 'delete');
        $this->level = 1;
        $this->plugin()->afterCommit($this->connection, $this->connection);   // inner commit: still inside
        $this->assertSame([], $this->sent);
        $this->level = 0;
        $this->plugin()->afterCommit($this->connection, $this->connection);   // outermost commit
        $this->assertSame(
            [['page', 7, 'save'], ['classification_term', 4, 'save'], ['page', 7, 'delete']],
            array_map(static fn (array $m): array => [$m['entity_type'], $m['entity_id'], $m['action']], $this->sent)
        );
    }

    public function testARollbackDropsWhatTheTransactionRequested(): void
    {
        $publisher = $this->publisher();
        $this->level = 1;
        $publisher->publish('page', 7, 'save');
        $this->level = 0;
        $this->plugin()->afterRollBack($this->connection, $this->connection);
        $this->plugin()->afterCommit($this->connection, $this->connection);   // a later, unrelated commit
        $this->assertSame([], $this->sent);
    }

    public function testAnInvalidRequestIsLoggedNeverThrown(): void
    {
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('reindex request dropped (page 0 save)'));
        $this->publisher()->publish('page', 0, 'save');
        $this->assertSame([], $this->sent);
    }

    public function testADatabaseConnectionFailureIsLoggedNeverThrown(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willThrowException(new \RuntimeException('SQLSTATE[HY000] gone away'));
        $this->logger->expects($this->once())->method('error');
        (new ReindexPublisher($resource, $this->pending, $this->sender, $this->logger))->publish('page', 7, 'save');
        $this->assertSame([], $this->sent);
    }
}
