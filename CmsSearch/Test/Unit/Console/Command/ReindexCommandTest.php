<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Console\Command\ReindexCommand;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;

class ReindexCommandTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private StoreManagerInterface&MockObject $storeManager;
    /** @var list<array<string, mixed>> */
    private array $sent = [];
    /** @var list<mixed> values bound to "page_id > ?" */
    private array $afterIds = [];

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        foreach (['distinct', 'from', 'order', 'limit'] as $method) {
            $this->select->method($method)->willReturnSelf();
        }
        $this->select->method('where')->willReturnCallback(function ($condition, $value = null) {
            $this->afterIds[] = $value;
            return $this->select;
        });
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
    }

    /** @param list<list<string>> $batches */
    private function tester(array $batches, ?MessageSender $sender = null, int $batchSize = 2): CommandTester
    {
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls(...$batches);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        if ($sender === null) {
            $sender = $this->createMock(MessageSender::class);
            $sender->method('send')->willReturnCallback(function (ReindexMessageInterface $message): bool {
                $this->sent[] = $message->toArray();
                return true;
            });
        }
        return new CommandTester(new ReindexCommand($resource, $this->storeManager, $sender, $batchSize));
    }

    public function testPublishesOnePageMessagePerPageInKeysetBatches(): void
    {
        $tester = $this->tester([['1', '2'], ['3', '4'], ['5']]);
        $this->assertSame(0, $tester->execute(['--entity' => 'page']));
        $this->assertSame([1, 2, 3, 4, 5], array_column($this->sent, 'entity_id'));
        $this->assertSame(['page'], array_values(array_unique(array_column($this->sent, 'entity_type'))));
        $this->assertSame(['save'], array_values(array_unique(array_column($this->sent, 'action'))));
        $this->assertSame([0, 2, 4], $this->afterIds);
        $this->assertStringContainsString('Published 5 reindex message(s) for CMS pages (store: all).', $tester->getDisplay());
    }

    public function testTheStoreOptionIsCarriedInEveryMessage(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $this->storeManager->method('getStore')->with(3)->willReturn($store);
        $tester = $this->tester([['1']]);
        $this->assertSame(0, $tester->execute(['--store' => '3']));   // --entity defaults to "all" (= page)
        $this->assertSame([3], array_column($this->sent, 'store_id'));
        $this->assertStringContainsString('(store: 3)', $tester->getDisplay());
    }

    /** @return array<string, array{0: string}> */
    public static function invalidEntities(): array
    {
        return ['category (Plan 6)' => ['category'], 'plural' => ['pages'], 'empty' => ['']];
    }

    #[DataProvider('invalidEntities')]
    public function testUnsupportedEntitiesAreRefused(string $entity): void
    {
        $tester = $this->tester([]);
        $this->assertSame(1, $tester->execute(['--entity' => $entity]));
        $this->assertStringContainsString('Unsupported --entity', $tester->getDisplay());
        $this->assertSame([], $this->sent);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidStores(): array
    {
        return [
            'text' => ['abc', 'must be a store view id'],
            'admin store 0' => ['0', 'must be a store view id'],
            'negative' => ['-1', 'must be a store view id'],
            'unknown' => ['99', 'Store view "99" does not exist'],
        ];
    }

    #[DataProvider('invalidStores')]
    public function testInvalidStoresAreRefused(string $store, string $message): void
    {
        $this->storeManager->method('getStore')->willThrowException(new NoSuchEntityException(__('missing')));
        $tester = $this->tester([]);
        $this->assertSame(1, $tester->execute(['--store' => $store]));
        $this->assertStringContainsString($message, $tester->getDisplay());
        $this->assertSame([], $this->sent);
    }

    public function testFailedPublicationsGiveAFailingExitCode(): void
    {
        $sender = $this->createMock(MessageSender::class);
        $sender->method('send')->willReturnOnConsecutiveCalls(true, false);
        $tester = $this->tester([['1', '2'], []], $sender);
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Published 1 reindex message(s)', $tester->getDisplay());
        $this->assertStringContainsString('1 message(s) could not be published', $tester->getDisplay());
    }
}
