<?php

declare(strict_types=1);

namespace Thedevkitchen\RestrictionReason\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\RestrictionReason\Model\Config\Paths;
use Thedevkitchen\RestrictionReason\Model\RestrictionReasonRepository;

class RestrictionReasonRepositoryTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private StoreManagerInterface&MockObject $storeManager;
    private RestrictionReasonRepository $repository;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->repository = new RestrictionReasonRepository($this->scopeConfig, $this->storeManager);
    }

    private function websiteExists(int $id): void
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn($id);
        $this->storeManager->method('getWebsite')->with($id)->willReturn($website);
    }

    public function testReturnsReasonReadAtWebsiteScope(): void
    {
        $this->websiteExists(2);
        $this->scopeConfig->method('isSetFlag')
            ->with(Paths::IS_ACTIVE, ScopeInterface::SCOPE_WEBSITE, 2)
            ->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope, int $scopeId) {
                $this->assertSame(ScopeInterface::SCOPE_WEBSITE, $scope);
                $this->assertSame(2, $scopeId);
                return [Paths::MODE => '1', Paths::REASON => 'Closed for stocktake'][$path] ?? null;
            }
        );

        $result = $this->repository->getByWebsiteId(2);

        $this->assertSame(2, $result->getWebsiteId());
        $this->assertTrue($result->getIsActive());
        $this->assertSame(1, $result->getMode());
        $this->assertSame('Closed for stocktake', $result->getReason());
    }

    public function testStillReturnsTheReasonWhenRestrictionIsInactive(): void
    {
        $this->websiteExists(3);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => [Paths::MODE => '0', Paths::REASON => 'Old reason'][$path] ?? null
        );

        $result = $this->repository->getByWebsiteId(3);

        $this->assertFalse($result->getIsActive());
        $this->assertSame('Old reason', $result->getReason());
    }

    public function testMissingReasonBecomesEmptyString(): void
    {
        $this->websiteExists(1);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->scopeConfig->method('getValue')->willReturn(null);

        $result = $this->repository->getByWebsiteId(1);

        $this->assertSame('', $result->getReason());
        $this->assertSame(0, $result->getMode());
    }

    public function testUnknownWebsiteThrowsNoSuchEntity(): void
    {
        $this->storeManager->method('getWebsite')->with(9999)
            ->willThrowException(new NoSuchEntityException(__('Website not found')));
        $this->expectException(NoSuchEntityException::class);

        $this->repository->getByWebsiteId(9999);
    }

    /**
     * @dataProvider nonPositiveIds
     */
    public function testNonPositiveWebsiteIdThrowsNoSuchEntityWithoutHittingTheStoreManager(int $id): void
    {
        $this->storeManager->expects($this->never())->method('getWebsite');
        $this->expectException(NoSuchEntityException::class);

        $this->repository->getByWebsiteId($id);
    }

    public static function nonPositiveIds(): array
    {
        return ['admin pseudo-website' => [0], 'negative' => [-1]];
    }
}
