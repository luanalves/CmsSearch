<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\CatalogIdSource;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class CatalogIdSourceTest extends TestCase
{
    use DbStubTrait;

    public function testCategoryIdsBelowTheRootsInKeysetBatches(): void
    {
        $this->assertSame([37, 40], (new CatalogIdSource($this->db([], [['37', '40']])))->nextCategoryIds(36, 500));
        $this->assertSame([36], $this->whereValues('entity_id > ?'));
        $this->assertSame([2], $this->whereValues('level >= ?'));
        $this->assertSame(['catalog_category_entity'], $this->tables);
    }

    public function testProductIdsInKeysetBatches(): void
    {
        $this->assertSame([7, 8], (new CatalogIdSource($this->db([], [['7', '8']])))->nextProductIds(0, 2));
        $this->assertSame([0], $this->whereValues('entity_id > ?'));
        $this->assertSame(['catalog_product_entity'], $this->tables);
    }

    public function testAStoreViewRestrictsCategoriesToItsRootCategoryPath(): void
    {
        $this->assertSame([37], (new CatalogIdSource($this->db([], [['37']])))->nextCategoryIds(0, 500, 6));
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
        (new CatalogIdSource($this->db([], [['37']])))->nextCategoryIds(0, 500);
        $this->assertSame([], array_filter(array_column($this->wheres, 0), static fn (string $c): bool => str_contains($c, 'path')));
    }
}
