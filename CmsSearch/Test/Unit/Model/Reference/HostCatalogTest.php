<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSelector;
use Thedevkitchen\CmsSearch\Model\Reference\HostCatalog;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class HostCatalogTest extends TestCase
{
    use DbStubTrait;

    private (VersionHistoryInterface&ResetAfterRequestInterface&MockObject)|null $history = null;

    private function catalog(ResourceConnection $resource, string $linkField = 'row_id', ?StoreManagerInterface $storeManager = null): HostCatalog
    {
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn(53);
        $eav = $this->createMock(EavConfig::class);
        $eav->method('getAttribute')->with('catalog_category', 'is_anchor')->willReturn($attribute);
        if ($storeManager === null) {
            $store = $this->createMock(Store::class);
            $store->method('getRootCategoryId')->willReturn(2);
            $storeManager = $this->createMock(StoreManagerInterface::class);
            $storeManager->method('getStore')->with(1)->willReturn($store);
        }
        $this->history = $this->createMockForIntersectionOfInterfaces([VersionHistoryInterface::class, ResetAfterRequestInterface::class]);
        return new HostCatalog($resource, $pool, $eav, $storeManager, $this->history);
    }

    private static function store(string $home = 'home'): StoreContext
    {
        return new StoreContext(1, 3, 'default', 'en_US', 'https://a.test/', 'https://a.test/media/', $home);
    }

    public function testEveryPageAndTheHomePage(): void
    {
        $catalog = $this->catalog($this->db([], [['3', '1', '3'], ['2']]));
        $this->assertSame([1, 3], $catalog->ids(new HostSelector('page', 'all'), self::store()));
        $this->assertSame([2], $catalog->ids(new HostSelector('page', 'home'), self::store()));
        $this->assertSame(['home'], $this->whereValues('identifier = ?'));
        $this->assertSame(['cms_page', 'cms_page'], $this->tables);
        $this->assertSame([], $catalog->ids(new HostSelector('page', 'home'), self::store('')), 'no home page configured: no query');
    }

    public function testPagesByLayoutHandleId(): void
    {
        $catalog = $this->catalog($this->db([], [['9']]));
        $this->assertSame([9], $catalog->ids(new HostSelector('page', 'page_handle', ['news_offers']), self::store()));
        $this->assertSame([['news_offers']], $this->whereValues("REPLACE(identifier, '/', '_') IN (?)"));
    }

    public function testProductsOfTheStoreWebsiteByTypeAndAll(): void
    {
        $catalog = $this->catalog($this->db([], [['8', '7'], ['5', '6']]));
        $this->assertSame([7, 8], $catalog->ids(new HostSelector('product', 'product_type', ['bundle']), self::store()));
        $this->assertSame([5, 6], $catalog->ids(new HostSelector('product', 'all'), self::store()));
        $this->assertSame([['bundle']], $this->whereValues('e.type_id IN (?)'));
        $this->assertSame([3, 3], $this->whereValues('w.website_id = ?'));
        $this->assertSame(['w.product_id = e.entity_id', 'w.product_id = e.entity_id'], $this->joins);
        $this->assertSame(['catalog_product_entity', 'catalog_product_website', 'catalog_product_entity', 'catalog_product_website'], $this->tables);
    }

    public function testAnchorCategoriesBelowTheStoreRoot(): void
    {
        $catalog = $this->catalog($this->db([], [['37', '40']]));
        $this->assertSame([37, 40], $catalog->ids(new HostSelector('category', 'anchor'), self::store()));
        $this->assertSame(['1/2/%'], $this->whereValues('e.path LIKE ?'));
        $this->assertSame([2], $this->whereValues('e.level >= ?'));
        // no is_anchor row is NOT anchor: the category controller tests getIsAnchor() (no default applied on load)
        $this->assertSame([1], $this->whereValues('COALESCE(a.value, 0) = ?'));
        $this->assertSame(['a.row_id = e.row_id AND a.attribute_id = 53 AND a.store_id = 0'], $this->joins);
    }

    public function testNonAnchorCategoriesAndTheCommunityLinkField(): void
    {
        $catalog = $this->catalog($this->db([], [['41']]), 'entity_id');
        $this->assertSame([41], $catalog->ids(new HostSelector('category', 'non_anchor'), self::store()));
        $this->assertSame([0], $this->whereValues('COALESCE(a.value, 0) = ?'));
        $this->assertSame(['a.entity_id = e.entity_id AND a.attribute_id = 53 AND a.store_id = 0'], $this->joins);
    }

    public function testEveryCategoryNeedsNoAnchorJoin(): void
    {
        $catalog = $this->catalog($this->db([], [['37']]));
        $this->assertSame([37], $catalog->ids(new HostSelector('category', 'all'), self::store()));
        $this->assertSame([], $this->joins);
        $this->assertSame([], $this->whereValues('COALESCE(a.value, 0) = ?'));
    }

    public function testCategoriesOfAnUnknownStoreOrWithoutRootNeedNoQuery(): void
    {
        $missing = $this->createMock(StoreManagerInterface::class);
        $missing->method('getStore')->willThrowException(new NoSuchEntityException());
        $this->assertSame([], $this->catalog($this->db(), 'row_id', $missing)->ids(new HostSelector('category', 'all'), self::store()));
        $store = $this->createMock(Store::class);
        $store->method('getRootCategoryId')->willReturn(0);
        $noRoot = $this->createMock(StoreManagerInterface::class);
        $noRoot->method('getStore')->willReturn($store);
        $this->assertSame([], $this->catalog($this->db(), 'row_id', $noRoot)->ids(new HostSelector('category', 'anchor'), self::store()));
    }

    public function testTheStagingVersionIsReloadedBeforeEveryQueryAndNeverForListedIds(): void
    {
        $catalog = $this->catalog($this->db([], [['1'], ['7']]));
        $this->history->expects($this->exactly(2))->method('_resetState');
        $catalog->ids(new HostSelector('page', 'all'), self::store());
        $catalog->ids(new HostSelector('product', 'all'), self::store());
        $catalog->ids(new HostSelector('product', 'ids', [7]), self::store());
    }

    public function testListedIdsNeedNoQuery(): void
    {
        $catalog = $this->catalog($this->db());
        $this->assertSame([5, 9], $catalog->ids(new HostSelector('category', 'ids', [9, 5, 9, 0, -1]), self::store()));
        $this->assertSame([7], $catalog->ids(new HostSelector('product', 'ids', ['7']), self::store()));
    }

    public function testModesAHostTypeDoesNotHaveMatchNothingWithoutQuery(): void
    {
        $catalog = $this->catalog($this->db());
        foreach ([['page', 'anchor'], ['page', 'product_type'], ['product', 'home'], ['product', 'anchor'], ['category', 'home'],
            ['category', 'product_type'], ['cart', 'all'], ['page', 'bogus']] as [$type, $mode]) {
            $this->assertSame([], $catalog->ids(new HostSelector($type, $mode, ['x']), self::store()), $type . ':' . $mode);
        }
    }
}
