<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Console\Command;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Console\Command\ReindexCommand;
use Thedevkitchen\CmsSearch\Model\Indexer\CatalogIdSource;
use Thedevkitchen\CmsSearch\Model\Indexer\FullReindexer;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class ReindexCommandEntitiesTest extends TestCase
{
    use DbStubTrait;

    /** @var list<string> "type id action store" of every sent message */
    private array $sent = [];
    /** @var list<string> "kind afterId limit" of every catalog id read */
    private array $reads = [];
    /** @var list<bool> results of the next sends (true when empty) */
    private array $sendResults = [];
    private FullReindexer&MockObject $full;

    private function tester(array $pageBatches, array $categoryBatches, array $productBatches): CommandTester
    {
        $sender = $this->createMock(MessageSender::class);
        $sender->method('send')->willReturnCallback(function (ReindexMessageInterface $m): bool {
            $this->sent[] = sprintf('%s %d %s %s', $m->getEntityType(), $m->getEntityId(), $m->getAction(), $m->getStoreId() ?? '*');
            return $this->sendResults === [] ? true : array_shift($this->sendResults);
        });
        $catalog = $this->createMock(CatalogIdSource::class);
        $catalog->method('nextCategoryIds')->willReturnCallback(function (int $afterId, int $limit) use (&$categoryBatches): array {
            $this->reads[] = sprintf('category %d %d', $afterId, $limit);
            return array_shift($categoryBatches) ?? [];
        });
        $catalog->method('nextProductIds')->willReturnCallback(function (int $afterId, int $limit) use (&$productBatches): array {
            $this->reads[] = sprintf('product %d %d', $afterId, $limit);
            return array_shift($productBatches) ?? [];
        });
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(2);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $this->full = $this->createMock(FullReindexer::class);
        return new CommandTester(new ReindexCommand($this->db([], $pageBatches), $storeManager, $sender, $this->full, $catalog, 2));
    }

    public function testCategoriesArePublishedOneMessagePerIdInKeysetBatches(): void
    {
        $tester = $this->tester([], [[37, 38], [40]], []);
        $this->assertSame(0, $tester->execute(['--entity' => 'category']));
        $this->assertSame(['category 37 save *', 'category 38 save *', 'category 40 save *'], $this->sent);
        $this->assertSame(['category 0 2', 'category 38 2'], $this->reads);
        $this->assertStringContainsString('Published 3 reindex message(s) for categories (store: all).', $tester->getDisplay());
        $this->assertStringNotContainsString('CMS pages', $tester->getDisplay());
        $this->assertStringNotContainsString('products', $tester->getDisplay());
    }

    public function testProductsAreStreamedInKeysetBatchesNeverCollected(): void
    {
        // memory is bounded by one batch: the next batch is read only after the previous one was published
        $tester = $this->tester([], [], [[3, 9], [12, 40], []]);
        $this->assertSame(0, $tester->execute(['--entity' => 'product']));
        $this->assertSame(['product 3 save *', 'product 9 save *', 'product 12 save *', 'product 40 save *'], $this->sent);
        $this->assertSame(['product 0 2', 'product 9 2', 'product 40 2'], $this->reads);
        $this->assertStringContainsString('Published 4 reindex message(s) for products (store: all).', $tester->getDisplay());
    }

    public function testAllPublishesPagesCategoriesAndProductsOfOneStoreView(): void
    {
        $tester = $this->tester([[1]], [[]], [[7]]);
        $this->assertSame(0, $tester->execute(['--entity' => 'all', '--store' => '2']));
        $this->assertSame(['page 1 save 2', 'product 7 save 2'], $this->sent);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Published 1 reindex message(s) for CMS pages (store: 2).', $display);
        $this->assertStringContainsString('Published 0 reindex message(s) for categories (store: 2).', $display);
        $this->assertStringContainsString('Published 1 reindex message(s) for products (store: 2).', $display);
    }

    public function testPublishingStopsAtTheFirstFailedSend(): void
    {
        // broker down: one logged failure and a failing exit code, not one timeout per remaining product
        $this->sendResults = [true, false];
        $tester = $this->tester([[1, 2]], [[37]], [[7]]);
        $this->assertSame(1, $tester->execute([]));
        $this->assertSame(['page 1 save *', 'page 2 save *'], $this->sent);
        $this->assertSame([], $this->reads, 'nothing else is read once publishing failed');
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Published 1 reindex message(s) for CMS pages (store: all).', $display);
        $this->assertStringContainsString('1 message(s) could not be published; publishing stopped at the first failure', $display);
        $this->assertStringNotContainsString('categories', $display);
    }

    public function testSyncRefusesProducts(): void
    {
        $tester = $this->tester([], [], []);
        $this->full->expects($this->never())->method('rebuild');
        $this->assertSame(1, $tester->execute(['--entity' => 'product', '--sync' => true]));
        $this->assertStringContainsString('--sync rebuilds the CMS search index (pages and categories)', $tester->getDisplay());
        $this->assertSame([], $this->sent);
    }

    public function testSyncOfCategoriesRebuildsTheCmsIndex(): void
    {
        $tester = $this->tester([], [], []);
        $this->full->expects($this->once())->method('rebuild')->willReturn(['stores' => [], 'orphans' => []]);
        $this->assertSame(0, $tester->execute(['--entity' => 'category', '--sync' => true]));
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->reads);
    }

    public function testAnUnknownEntityListsTheSupportedOnes(): void
    {
        $tester = $this->tester([], [], []);
        $this->assertSame(1, $tester->execute(['--entity' => 'block']));
        $this->assertStringContainsString('Supported: page, category, product, all.', $tester->getDisplay());
        $this->assertSame([], $this->sent);
    }
}
