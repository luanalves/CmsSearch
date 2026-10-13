<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreListRefresher;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

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
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStores')->with(false)->willReturn($memory);
        $manager->expects($this->exactly($reinits))->method('reinitStores');
        return new StoreListRefresher($this->db([$rows]), $manager);
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
