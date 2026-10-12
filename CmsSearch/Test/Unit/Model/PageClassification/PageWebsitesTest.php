<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\PageClassification;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\Page;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\PageClassification\PageWebsites;

class PageWebsitesTest extends TestCase
{
    private function pageWebsites(): PageWebsites
    {
        $websites = [];
        foreach ([3, 1, 2] as $id) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $websites[] = $website;
        }
        $stores = [];
        foreach ([1 => 1, 2 => 2, 3 => 2, 5 => 3] as $storeId => $websiteId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getStores')->willReturn($stores);
        return new PageWebsites($storeManager);
    }

    public function testStoreViewsMapToTheirWebsitesOnce(): void
    {
        $this->assertSame([2, 3], $this->pageWebsites()->fromStoreIds([5, 3, 2]));
        $this->assertSame([2], $this->pageWebsites()->fromStoreIds(['2', '3']));
    }

    public function testAllStoreViewsMeansEveryWebsite(): void
    {
        $this->assertSame([1, 2, 3], $this->pageWebsites()->fromStoreIds([0]));
        $this->assertSame([1, 2, 3], $this->pageWebsites()->fromStoreIds([2, 0]));
    }

    public function testUnknownOrNoStoresGiveNoWebsite(): void
    {
        $this->assertSame([], $this->pageWebsites()->fromStoreIds([99]));
        $this->assertSame([], $this->pageWebsites()->fromStoreIds([]));
    }

    public function testStoreIdsOfAPageModel(): void
    {
        $page = $this->getMockBuilder(Page::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $page->setData('store_id', ['2', '5']);
        $this->assertSame([2, 5], $this->pageWebsites()->storeIdsOf($page));
        $page->setData('stores', [0]);
        $this->assertSame([0], $this->pageWebsites()->storeIdsOf($page));
        $this->assertSame([], $this->pageWebsites()->storeIdsOf($this->createMock(PageInterface::class)));
    }
}
