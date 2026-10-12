<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;

class StoreContextProviderTest extends TestCase
{
    private StoreManagerInterface&MockObject $storeManager;
    private ScopeConfigInterface&MockObject $scopeConfig;
    /** @var array<int, array<string, string>> store id => path => value */
    private array $config = [];

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope = 'default', $scopeCode = null) {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scope);
                return $this->config[(int) $scopeCode][$path] ?? null;
            }
        );
    }

    private function store(int $id, int $websiteId, bool $secure = true): Store&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn((string) $id);
        $store->method('getWebsiteId')->willReturn((string) $websiteId);
        $store->method('getCode')->willReturn('store' . $id);
        $store->method('isFrontUrlSecure')->willReturn($secure);
        $store->method('getBaseUrl')->willReturnCallback(
            static fn (string $type, $isSecure): string => ($isSecure ? 'https' : 'http') . '://s' . $id . '.test/'
                . ($type === UrlInterface::URL_TYPE_MEDIA ? 'media/' : '')
        );
        $this->config[$id] = ['general/locale/code' => 'pt_BR', 'web/default/cms_home_page' => 'home'];
        return $store;
    }

    private function provider(): StoreContextProvider
    {
        return new StoreContextProvider($this->storeManager, $this->scopeConfig);
    }

    public function testAllReturnsEveryStoreViewSortedById(): void
    {
        $this->storeManager->method('getStores')->with(false)->willReturn([$this->store(3, 2), $this->store(1, 1), $this->store(2, 2)]);
        $this->assertSame([1, 2, 3], array_map(static fn (StoreContext $s): int => $s->getStoreId(), $this->provider()->all()));
    }

    public function testContextValuesComeFromTheStoreAndItsScopeConfig(): void
    {
        $store = $this->store(5, 3, false);
        $this->config[5] = ['general/locale/code' => 'en_GB', 'web/default/cms_home_page' => 'welcome|7'];
        $this->storeManager->method('getStore')->with(5)->willReturn($store);
        $context = $this->provider()->get(5);
        $this->assertNotNull($context);
        $this->assertSame(
            [5, 3, 'store5', 'en_GB', 'http://s5.test/', 'http://s5.test/media/', 'welcome'],
            [$context->getStoreId(), $context->getWebsiteId(), $context->getCode(), $context->getLocale(),
                $context->getBaseUrl(), $context->getMediaUrl(), $context->getHomeIdentifier()]
        );
    }

    public function testASecureStoreViewGetsHttpsUrls(): void
    {
        $this->storeManager->method('getStore')->with(4)->willReturn($this->store(4, 2, true));
        $context = $this->provider()->get(4);
        $this->assertSame(
            ['https://s4.test/', 'https://s4.test/media/'],
            [$context?->getBaseUrl(), $context?->getMediaUrl()]
        );
    }

    public function testUnknownOrAdminStoreGivesNull(): void
    {
        $this->storeManager->method('getStore')->willThrowException(new NoSuchEntityException(__('missing')));
        $this->assertNull($this->provider()->get(99));
        $this->assertNull($this->provider()->get(0));
        $this->assertNull($this->provider()->get(-1));
    }

    public function testAnEmptyLocaleFallsBackToEnUs(): void
    {
        $store = $this->store(2, 2);
        $this->config[2]['general/locale/code'] = '';
        $this->storeManager->method('getStore')->willReturn($store);
        $this->assertSame('en_US', $this->provider()->get(2)?->getLocale());
    }
}
