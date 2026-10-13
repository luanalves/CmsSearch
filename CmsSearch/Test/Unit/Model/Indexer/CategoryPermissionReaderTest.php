<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\CatalogPermissions\Model\ResourceModel\Permission\Index as PermissionIndex;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\CategoryPermissionReader;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class CategoryPermissionReaderTest extends TestCase
{
    use DbStubTrait;

    private PermissionIndex&MockObject $index;
    /** @var array<string, mixed> "scope|scope id|path" => value */
    private array $config = [];

    protected function setUp(): void
    {
        $this->index = $this->createMock(PermissionIndex::class);
    }

    private function reader(?ResourceConnection $resource = null): CategoryPermissionReader
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $value = function (string $path, string $scope = 'default', $scopeId = null) {
            return $this->config[$scope . '|' . $scopeId . '|' . $path] ?? null;
        };
        $scopeConfig->method('getValue')->willReturnCallback($value);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope = 'default', $scopeId = null): bool => (bool) $value($path, $scope, $scopeId)
        );
        return new CategoryPermissionReader($scopeConfig, $this->index, $resource ?? $this->db());
    }

    /** Catalog permissions as the storefront reads them: store scope of store view 4 (website 2). */
    private function permissions(bool $enabled, ?string $mode = '1', ?string $groups = null): void
    {
        $this->config[ScopeInterface::SCOPE_STORE . '|4|' . CategoryPermissionReader::XML_PATH_ENABLED] = $enabled ? '1' : '0';
        $this->config[ScopeInterface::SCOPE_STORE . '|4|' . CategoryPermissionReader::XML_PATH_GRANT_CATEGORY_VIEW] = $mode;
        $this->config[ScopeInterface::SCOPE_STORE . '|4|' . CategoryPermissionReader::XML_PATH_GRANT_CATEGORY_VIEW . '_groups'] = $groups;
    }

    public function testWithCatalogPermissionsDisabledEveryCategoryIsPublic(): void
    {
        $this->permissions(false, '0');
        $this->index->expects($this->never())->method('getIndexForCategory');
        $this->assertSame([37, 38], $this->reader()->visibleToGuests([37, 38], 4, 2));
    }

    public function testTheGuestRowOfTheWebsiteDecidesAndTheConfigDefaultFillsTheGaps(): void
    {
        $this->permissions(true, '1');
        $this->index->expects($this->once())->method('getIndexForCategory')->with([37, 38, 39], 0, 2)->willReturn([
            38 => ['category_id' => 38, 'website_id' => 2, 'customer_group_id' => 0, 'grant_catalog_category_view' => '-2'],
            39 => ['category_id' => 39, 'website_id' => 2, 'customer_group_id' => 0, 'grant_catalog_category_view' => '-1'],
        ]);
        $this->assertSame([37, 39], $this->reader()->visibleToGuests([37, 38, 39], 4, 2));
    }

    public function testWhenGuestsMayNotBrowseByDefaultOnlyExplicitlyAllowedCategoriesArePublic(): void
    {
        $this->permissions(true, '0');
        $this->index->method('getIndexForCategory')->willReturn([
            38 => ['grant_catalog_category_view' => '-1'],
            39 => ['grant_catalog_category_view' => null],
        ]);
        $this->assertSame([38], $this->reader()->visibleToGuests([37, 38, 39], 4, 2));
    }

    /**
     * @return array<string, array{0: ?string, 1: bool}>
     */
    public static function groupLists(): array
    {
        return [
            'guests listed' => ['1,0,3', true],
            'guests not listed' => ['1,2', false],
            'group 10 is not group 0' => ['10', false],
            'empty list' => ['', false],
            'no list' => [null, false],
        ];
    }

    #[DataProvider('groupLists')]
    public function testSpecifiedCustomerGroupsMustListTheNotLoggedInGroup(?string $groups, bool $public): void
    {
        $this->permissions(true, '2', $groups);
        $this->index->method('getIndexForCategory')->willReturn([]);
        $this->assertSame($public ? [37] : [], $this->reader()->visibleToGuests([37], 4, 2));
    }

    public function testAnActiveSharedCatalogThatDeniesGuestsHidesTheCategoryBeforeItsPermissionsAreApplied(): void
    {
        $this->permissions(true, '1');
        $this->config[ScopeInterface::SCOPE_WEBSITE . '|2|' . CategoryPermissionReader::XML_PATH_SHARED_CATALOG_ACTIVE] = '1';
        $this->index->method('getIndexForCategory')->willReturn([39 => ['grant_catalog_category_view' => '-1']]);
        $resource = $this->db([], [['38', '39']]);
        $this->assertSame([37], $this->reader($resource)->visibleToGuests([37, 38, 39], 4, 2));
        $this->assertSame(['sharedcatalog_category_permissions'], $this->tables);
        $this->assertSame([[37, 38, 39]], $this->whereValues('category_id IN (?)'));
        $this->assertSame([0], $this->whereValues('customer_group_id = ?'));
        $this->assertSame([-2], $this->whereValues('permission = ?'));
        $this->assertSame([2], $this->whereValues('website_id IS NULL OR website_id = ?'));
    }

    public function testAnInactiveSharedCatalogIsNotRead(): void
    {
        $this->permissions(true, '1');
        $this->index->method('getIndexForCategory')->willReturn([]);
        // db() without fetchCol results: any read of the shared catalog table fails the test
        $this->assertSame([37], $this->reader($this->db())->visibleToGuests([37], 4, 2));
    }

    public function testNoCategoriesNoQueries(): void
    {
        $this->permissions(true, '1');
        $this->index->expects($this->never())->method('getIndexForCategory');
        $this->assertSame([], $this->reader()->visibleToGuests([], 4, 2));
    }
}
