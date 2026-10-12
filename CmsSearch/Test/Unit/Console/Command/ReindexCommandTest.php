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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Console\Command\ReindexCommand;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\FullReindexer;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;

class ReindexCommandTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private StoreManagerInterface&MockObject $storeManager;
    private FullReindexer&MockObject $fullReindexer;
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
        $this->fullReindexer = $this->createMock(FullReindexer::class);
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
        return new CommandTester(new ReindexCommand($resource, $this->storeManager, $sender, $this->fullReindexer, $batchSize));
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

    public function testWithoutSyncNothingIsRebuilt(): void
    {
        $this->fullReindexer->expects($this->never())->method('rebuild');
        $this->assertSame(0, $this->tester([['1']])->execute(['--entity' => 'page']));
        $this->assertSame([1], array_column($this->sent, 'entity_id'));
    }

    public function testSyncRebuildsEveryStoreViewAndPublishesNothing(): void
    {
        $this->fullReindexer->expects($this->once())->method('rebuild')->with(null)->willReturn([
            'stores' => [
                ['store_id' => 1, 'index' => 'magento2_thedevkitchen_cmssearch_1_v2', 'documents' => 0, 'removed' => ['magento2_thedevkitchen_cmssearch_1_v1'], 'leftovers' => []],
                ['store_id' => 2, 'index' => 'magento2_thedevkitchen_cmssearch_2_v3', 'documents' => 4, 'removed' => [], 'leftovers' => []],
            ],
            'orphans' => ['magento2_thedevkitchen_cmssearch_9_v1'],
        ]);
        $tester = $this->tester([]);
        $this->assertSame(0, $tester->execute(['--sync' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Store 1: 0 page document(s) indexed into magento2_thedevkitchen_cmssearch_1_v2 (removed: magento2_thedevkitchen_cmssearch_1_v1).', $display);
        $this->assertStringContainsString('Store 2: 4 page document(s) indexed into magento2_thedevkitchen_cmssearch_2_v3 (removed: none).', $display);
        $this->assertStringContainsString('Dropped the index(es) of deleted store views: magento2_thedevkitchen_cmssearch_9_v1.', $display);
        $this->assertStringNotContainsString('could not be deleted', $display);
        $this->assertSame([], $this->sent);
    }

    public function testSyncCanBeLimitedToOneStoreView(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $this->storeManager->method('getStore')->with(3)->willReturn($store);
        $this->fullReindexer->expects($this->once())->method('rebuild')->with(3)
            ->willReturn(['stores' => [['store_id' => 3, 'index' => 'i_3_v2', 'documents' => 1, 'removed' => [], 'leftovers' => []]], 'orphans' => []]);
        $tester = $this->tester([]);
        $this->assertSame(0, $tester->execute(['--sync' => true, '--store' => '3']));
        $this->assertStringNotContainsString('Dropped', $tester->getDisplay());
    }

    public function testAFailedSyncGivesAFailingExitCode(): void
    {
        $this->fullReindexer->method('rebuild')->willThrowException(new IndexingException('Rebuild of store view 2 failed: No alive nodes'));
        $tester = $this->tester([]);
        $this->assertSame(1, $tester->execute(['--sync' => true]));
        $this->assertStringContainsString('Rebuild of store view 2 failed: No alive nodes', $tester->getDisplay());
        $this->assertSame([], $this->sent);
    }

    public function testSyncRefusesUnsupportedEntitiesBeforeRebuilding(): void
    {
        $this->fullReindexer->expects($this->never())->method('rebuild');
        $tester = $this->tester([]);
        $this->assertSame(1, $tester->execute(['--sync' => true, '--entity' => 'category']));
        $this->assertStringContainsString('Unsupported --entity "category". Supported: page, all.', $tester->getDisplay());
    }

    public function testOldVersionsThatCouldNotBeDeletedAreNamed(): void
    {
        $this->fullReindexer->method('rebuild')->willReturn(['stores' => [
            ['store_id' => 2, 'index' => 'i_2_v4', 'documents' => 1, 'removed' => ['i_2_v2'], 'leftovers' => ['i_2_v3']],
        ], 'orphans' => []]);
        $tester = $this->tester([]);
        $this->assertSame(0, $tester->execute(['--sync' => true]));
        $this->assertStringContainsString(
            'Store 2: the old version(s) i_2_v3 could not be deleted (logged); the next --sync removes them.',
            $tester->getDisplay()
        );
    }

    public function testProgressIsShownOnlyWhenVerbose(): void
    {
        $this->fullReindexer->method('rebuild')->willReturnCallback(function (?int $storeId, ?callable $progress = null): array {
            $this->assertNotNull($progress);
            $progress('Store 2: building i_2_v2.');
            return ['stores' => [], 'orphans' => []];
        });
        $quiet = $this->tester([]);
        $quiet->execute(['--sync' => true]);
        $this->assertStringNotContainsString('building', $quiet->getDisplay());
        $verbose = $this->tester([]);
        $verbose->execute(['--sync' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $this->assertStringContainsString('Store 2: building i_2_v2.', $verbose->getDisplay());
    }
}
