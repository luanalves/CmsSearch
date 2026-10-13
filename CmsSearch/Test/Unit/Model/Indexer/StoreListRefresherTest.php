<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreListRefresher;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;
use Thedevkitchen\CmsSearch\Test\Unit\Support\RecordingLogger;

class StoreListRefresherTest extends TestCase
{
    use DbStubTrait;

    private function store(int $id, string $code, int $websiteId, int $groupId, int $active, int $root): Store&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn((string) $id);
        $store->method('getCode')->willReturn($code);
        $store->method('getWebsiteId')->willReturn((string) $websiteId);
        $store->method('getStoreGroupId')->willReturn((string) $groupId);
        $store->method('getIsActive')->willReturn((string) $active);
        $store->method('getRootCategoryId')->willReturn((string) $root);
        return $store;
    }

    /** @return array<string, string> a row as the database returns it */
    private static function row(int $id, string $code, int $websiteId, int $groupId, int $active, int $root): array
    {
        return ['store_id' => (string) $id, 'code' => $code, 'website_id' => (string) $websiteId,
            'group_id' => (string) $groupId, 'is_active' => (string) $active, 'root_category_id' => (string) $root];
    }

    /**
     * @param list<array<string, string>> $rows
     * @param array<int, Store> $memory
     */
    private function refresher(array $rows, array $memory, int $reinits): StoreListRefresher
    {
        return $this->refresherOver([$rows], $memory, $reinits);
    }

    private RecordingLogger $logger;

    /**
     * @param list<list<array<string, string>>> $reads rows of each database read, in call order
     * @param array<int, Store> $memory what the StoreManager keeps (stays the same, as with dumped scopes)
     */
    private function refresherOver(array $reads, array $memory, int $reinits): StoreListRefresher
    {
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStores')->with(false)->willReturn($memory);
        $manager->expects($this->exactly($reinits))->method('reinitStores');
        $this->logger = new RecordingLogger();
        return new StoreListRefresher($this->db($reads), $manager, $this->logger);
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}> */
    public static function changedField(): array
    {
        return [
            'code' => [1, 0, 0, 0, 0],
            'website' => [0, 1, 0, 0, 0],
            'group' => [0, 0, 1, 0, 0],
            'active' => [0, 0, 0, 1, 0],
            'root category' => [0, 0, 0, 0, 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedField')]
    public function testEveryFieldOfTheLineCountsAsAChange(int $code, int $website, int $group, int $active, int $root): void
    {
        $refresher = $this->refresher(
            [self::row(2, 'w1' . ($code ? 'x' : ''), 2 + $website, 2 + $group, 1 - $active, 103 + $root)],
            [2 => $this->store(2, 'w1', 2, 2, 1, 103)],
            1
        );
        $this->assertTrue($refresher->refreshIfChanged());
    }

    public function testTheSameDatabaseRowsAreReinitialisedOnceEvenIfMemoryStaysDifferent(): void
    {
        // dumped scopes: the file source wins, so memory never converges to the database
        $rows = [self::row(1, 'default', 1, 1, 1, 2), self::row(8, 'new', 2, 2, 1, 103)];
        $refresher = $this->refresherOver([$rows, $rows, $rows], [1 => $this->store(1, 'default', 1, 1, 1, 2)], 1);
        $this->assertTrue($refresher->refreshIfChanged());
        $this->assertFalse($refresher->refreshIfChanged());
        $this->assertFalse($refresher->refreshIfChanged());
        $warnings = array_filter($this->logger->records, static fn (array $r): bool => $r[0] === 'warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('still differs', array_values($warnings)[0][1]);
    }

    public function testAnotherDatabaseStateIsReinitialisedAgain(): void
    {
        $first = [self::row(1, 'default', 1, 1, 1, 2), self::row(8, 'new', 2, 2, 1, 103)];
        $second = [self::row(1, 'default', 1, 1, 1, 2), self::row(9, 'newer', 2, 2, 1, 103)];
        $refresher = $this->refresherOver([$first, $first, $second, $second], [1 => $this->store(1, 'default', 1, 1, 1, 2)], 2);
        $this->assertTrue($refresher->refreshIfChanged());
        $this->assertFalse($refresher->refreshIfChanged());
        $this->assertTrue($refresher->refreshIfChanged());
        $this->assertFalse($refresher->refreshIfChanged());
        $this->assertCount(2, array_filter($this->logger->records, static fn (array $r): bool => $r[0] === 'warning'));
    }

    public function testNoWarningWhenTheReinitialisationConverges(): void
    {
        $rows = [self::row(1, 'default', 1, 1, 1, 2), self::row(8, 'new', 2, 2, 1, 103)];
        $old = [1 => $this->store(1, 'default', 1, 1, 1, 2)];
        $new = [1 => $this->store(1, 'default', 1, 1, 1, 2), 8 => $this->store(8, 'new', 2, 2, 1, 103)];
        $manager = $this->createMock(StoreManagerInterface::class);
        $current = $old;
        $manager->method('getStores')->willReturnCallback(function () use (&$current) {
            return $current;
        });
        $manager->expects($this->once())->method('reinitStores')->willReturnCallback(function () use (&$current, $new): void {
            $current = $new;
        });
        $this->logger = new RecordingLogger();
        $refresher = new StoreListRefresher($this->db([$rows, $rows]), $manager, $this->logger);
        $this->assertTrue($refresher->refreshIfChanged());
        $this->assertFalse($refresher->refreshIfChanged());
        $this->assertSame([], $this->logger->records);
    }

    public function testTheFirstLookInAProcessComparesWithMemoryAndLaterOnesWithTheLastDatabaseState(): void
    {
        $rows = [self::row(1, 'default', 1, 1, 1, 2)];
        $refresher = $this->refresherOver([$rows, $rows], [1 => $this->store(1, 'default', 1, 1, 1, 2)], 0);
        $this->assertFalse($refresher->refreshIfChanged());
        $this->assertFalse($refresher->refreshIfChanged());
    }

    public function testAnUnchangedStoreListIsNotReinitialised(): void
    {
        $refresher = $this->refresher(
            [self::row(1, 'default', 1, 1, 1, 2), self::row(2, 'w1', 2, 2, 1, 103)],
            [2 => $this->store(2, 'w1', 2, 2, 1, 103), 1 => $this->store(1, 'default', 1, 1, 1, 2)],
            0
        );
        $this->assertFalse($refresher->refreshIfChanged());
        $this->assertSame([0], $this->whereValues('s.store_id > ?'));
        $this->assertSame(['store', 'store_group'], $this->tables);
        $this->assertSame(['s.store_id ASC'], $this->orders);
    }

    public function testANewOrDeletedStoreViewReinitialisesTheStoreManager(): void
    {
        $refresher = $this->refresher(
            [self::row(1, 'default', 1, 1, 1, 2), self::row(8, 'new', 2, 2, 1, 103)],
            [1 => $this->store(1, 'default', 1, 1, 1, 2), 2 => $this->store(2, 'w1', 2, 2, 1, 103)],
            1
        );
        $this->assertTrue($refresher->refreshIfChanged());
    }

    public function testAMovedOrReRootedStoreViewReinitialisesToo(): void
    {
        $refresher = $this->refresher(
            [self::row(2, 'w1', 2, 2, 1, 104)],          // the group got another root category
            [2 => $this->store(2, 'w1', 2, 2, 1, 103)],
            1
        );
        $this->assertTrue($refresher->refreshIfChanged());
    }
}
