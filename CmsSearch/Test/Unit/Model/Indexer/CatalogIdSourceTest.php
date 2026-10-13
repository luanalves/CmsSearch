<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\CatalogIdSource;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class CatalogIdSourceTest extends TestCase
{
    use DbStubTrait;

    public function testCategoryIdsBelowTheRootsInKeysetBatches(): void
    {
        $this->assertSame([37, 40], ($this->source($this->db([], [['37', '40']])))->nextCategoryIds(36, 500));
        $this->assertSame([36], $this->whereValues('entity_id > ?'));
        $this->assertSame([2], $this->whereValues('level >= ?'));
        $this->assertSame(['catalog_category_entity'], $this->tables);
    }

    public function testProductIdsInKeysetBatches(): void
    {
        $this->assertSame([7, 8], ($this->source($this->db([], [['7', '8']])))->nextProductIds(0, 2));
        $this->assertSame([0], $this->whereValues('entity_id > ?'));
        $this->assertSame(['catalog_product_entity'], $this->tables);
    }

    public function testAStoreViewRestrictsCategoriesToItsRootCategoryPath(): void
    {
        $this->assertSame([37], ($this->source($this->db([], [['37']])))->nextCategoryIds(0, 500, 6));
        $paths = array_values(array_filter(
            array_column($this->wheres, 0),
            static fn (string $condition): bool => str_contains($condition, 'path LIKE')
        ));
        $this->assertCount(1, $paths);
        $this->assertStringContainsString('s.store_id = 6', $paths[0]);
        $this->assertStringContainsString('root_category_id', $paths[0]);
        $this->assertStringContainsString("'1/'", $paths[0]);
    }

    public function testWithoutAStoreViewThereIsNoPathFilter(): void
    {
        ($this->source($this->db([], [['37']])))->nextCategoryIds(0, 500);
        $this->assertSame([], array_filter(array_column($this->wheres, 0), static fn (string $c): bool => str_contains($c, 'path')));
    }

    private function source(ResourceConnection $resource, string $linkField = 'row_id'): CatalogIdSource
    {
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->with(PageInterface::class)->willReturn($metadata);
        return new CatalogIdSource($resource, $pool);
    }

    public function testPageIdsOfAStoreViewAreThoseAssignedToItOrToAllStoreViews(): void
    {
        $this->assertSame([3, 11], $this->source($this->db([], [['3', '11']]))->nextPageIds(2, 500, 2));
        $this->assertSame([[0, 2]], $this->whereValues('s.store_id IN (?)'));
        $this->assertSame([2], $this->whereValues('p.page_id > ?'));
        $this->assertSame(['cms_page', 'cms_page_store'], $this->tables);
        $this->assertSame(['s.row_id = p.row_id'], $this->joins);
        $this->assertSame(['p.page_id ASC'], $this->orders);
    }

    public function testThePageLinkFieldComesFromTheEntityMetadata(): void
    {
        $this->source($this->db([], [[]]), 'page_id')->nextPageIds(0, 500, 3);
        $this->assertSame(['s.page_id = p.page_id'], $this->joins); // Open Source layout (no Staging row_id)
        $this->assertSame([[0, 3]], $this->whereValues('s.store_id IN (?)'));
    }
}
