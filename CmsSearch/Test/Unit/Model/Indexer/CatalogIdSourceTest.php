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
}
