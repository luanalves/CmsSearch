<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSource;
use Thedevkitchen\CmsSearch\Model\Reference\ProductSourceReader;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class ProductSourceReaderTest extends TestCase
{
    use DbStubTrait;

    private (VersionHistoryInterface&ResetAfterRequestInterface&MockObject)|null $history = null;

    private function reader(array $fetchAll, string $linkField = 'row_id', int $chunkSize = 500, ?StoreManagerInterface $storeManager = null): ProductSourceReader
    {
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);
        $eav = $this->createMock(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function (string $entity, string $code) {
            $this->assertSame('catalog_product', $entity);
            $attribute = $this->createMock(AbstractAttribute::class);
            $attribute->method('getAttributeId')->willReturn(['description' => 75, 'short_description' => 76][$code]);
            return $attribute;
        });
        if ($storeManager === null) {
            $store = $this->createMock(Store::class);
            $store->method('getWebsiteId')->willReturn(4);
            $storeManager = $this->createMock(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($store);
        }
        $this->history ??= $this->createMockForIntersectionOfInterfaces([VersionHistoryInterface::class, ResetAfterRequestInterface::class]);
        return new ProductSourceReader($this->db($fetchAll), $pool, $eav, $storeManager, $this->history, $chunkSize);
    }

    public function testDescriptionsWithStoreFallbackAndTheTypeInTwoQueries(): void
    {
        $sources = $this->reader([
            [['entity_id' => 7, 'row_id' => 70, 'type_id' => 'simple'], ['entity_id' => 8, 'row_id' => 80, 'type_id' => 'bundle']],
            [
                ['row_id' => 70, 'attribute_id' => 75, 'store_id' => 0, 'value' => '<p>default</p>'],
                ['row_id' => 70, 'attribute_id' => 76, 'store_id' => 0, 'value' => '  '],
                ['row_id' => 70, 'attribute_id' => 75, 'store_id' => 2, 'value' => '<p>store</p>'],
                ['row_id' => 80, 'attribute_id' => 76, 'store_id' => 0, 'value' => '{{block id="x"}}'],
            ],
        ])->read([7, 8], 2);
        $this->assertSame([7, 8], array_keys($sources));
        $this->assertSame(['<p>store</p>'], $sources[7]->getContents());
        $this->assertSame(['product', 7, 'simple'], [$sources[7]->getFacts()->getHostType(), $sources[7]->getFacts()->getHostId(), $sources[7]->getFacts()->getProductType()]);
        $this->assertSame(['{{block id="x"}}'], $sources[8]->getContents());
        $this->assertSame('bundle', $sources[8]->getFacts()->getProductType());
        $this->assertSame([], $sources[8]->getExtraRefs());
        $this->assertSame([[7, 8]], $this->whereValues('e.entity_id IN (?)'));
        $this->assertSame([4], $this->whereValues('w.website_id = ?'), 'only products of the store view website');
        $this->assertSame(['w.product_id = e.entity_id'], $this->joins);
        $this->assertSame([[70, 80]], $this->whereValues('row_id IN (?)'));
        $this->assertSame([[75, 76]], $this->whereValues('attribute_id IN (?)'));
        $this->assertSame([[0, 2]], $this->whereValues('store_id IN (?)'));
        $this->assertSame(['catalog_product_entity', 'catalog_product_website', 'catalog_product_entity_text'], $this->tables);
    }

    public function testBothDescriptionsInAttributeOrderAndAStoreValueOverridesWhicheverComesFirst(): void
    {
        $sources = $this->reader([
            [['entity_id' => 7, 'row_id' => 70, 'type_id' => 'simple']],
            [
                ['row_id' => 70, 'attribute_id' => 76, 'store_id' => 2, 'value' => '<p>short store</p>'],
                ['row_id' => 70, 'attribute_id' => 76, 'store_id' => 0, 'value' => '<p>short default</p>'],
                ['row_id' => 70, 'attribute_id' => 75, 'store_id' => 0, 'value' => '<p>long</p>'],
                ['row_id' => 70, 'attribute_id' => 75, 'store_id' => 2, 'value' => null],
            ],
        ])->read([7], 2);
        // a NULL store value is the store's own value ("Use Default Value" removes the row instead)
        $this->assertSame(['<p>short store</p>'], $sources[7]->getContents());
    }

    public function testCommunityEditionLinksValuesByEntityId(): void
    {
        $sources = $this->reader([
            [['entity_id' => 7, 'type_id' => 'simple']],
            [['entity_id' => 7, 'attribute_id' => 75, 'store_id' => 0, 'value' => '<p>d</p>']],
        ], 'entity_id')->read([7], 1);
        $this->assertSame(['<p>d</p>'], $sources[7]->getContents());
        $this->assertSame([[7]], $this->whereValues('entity_id IN (?)'));
        $this->assertSame([], $this->whereValues('row_id IN (?)'));
    }

    public function testLargeIdListsAreReadInChunksOfTwoQueries(): void
    {
        $sources = $this->reader([
            [['entity_id' => 7, 'row_id' => 70, 'type_id' => 'simple'], ['entity_id' => 8, 'row_id' => 80, 'type_id' => 'simple']],
            [['row_id' => 80, 'attribute_id' => 75, 'store_id' => 0, 'value' => 'b']],
            [['entity_id' => 9, 'row_id' => 90, 'type_id' => 'virtual']],
            [['row_id' => 90, 'attribute_id' => 75, 'store_id' => 0, 'value' => 'c']],
        ], 'row_id', 2)->read([9, 8, 7], 1);
        $this->assertSame([7, 8, 9], array_keys($sources));
        $this->assertSame([[], ['b'], ['c']], array_map(static fn (HostSource $s): array => $s->getContents(), array_values($sources)));
        $this->assertSame([[7, 8], [9]], $this->whereValues('e.entity_id IN (?)'));
        $this->assertSame([[70, 80], [90]], $this->whereValues('row_id IN (?)'));
    }

    public function testTheStagingVersionIsReloadedOncePerRead(): void
    {
        $reader = $this->reader([[]]);
        $this->history->expects($this->once())->method('_resetState');
        $reader->read([7], 1);
    }

    public function testAnUnknownStoreViewHasNoProducts(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException());
        $this->assertSame([], $this->reader([], 'row_id', 500, $storeManager)->read([7], 99));
    }

    public function testMissingProductsStopAfterTheEntityQuery(): void
    {
        $this->assertSame([], $this->reader([[]])->read([99], 2));
        $this->history = null;
        $this->assertSame([], $this->reader([])->read([0, -1], 2));
    }
}
