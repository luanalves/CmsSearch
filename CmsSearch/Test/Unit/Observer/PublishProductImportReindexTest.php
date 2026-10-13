<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\CatalogImportExport\Model\Import\Product as ProductImport;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Observer\PublishProductImportReindex;

/**
 * Product import writes by raw SQL (no product model event): the bunch events name the products.
 */
class PublishProductImportReindexTest extends TestCase
{
    /** @return list<string> */
    private function publish(string $eventName, array $data): array
    {
        $calls = [];
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action) use (&$calls): void {
            $calls[] = "$type $id $action";
        });
        $event = new Event($data);
        $event->setName($eventName);
        (new PublishProductImportReindex($publisher))->execute(new Observer(['event' => $event]));
        return $calls;
    }

    private function adapter(): ProductImport
    {
        $adapter = $this->createMock(ProductImport::class);
        $adapter->method('getNewSku')->willReturnCallback(static fn ($sku = null) => match (strtolower((string) $sku)) {
            'a-1' => ['entity_id' => '11', 'type_id' => 'simple'],
            'b-2' => ['entity_id' => 12],
            'c-3' => ['entity_id' => '13'],
            default => null,
        });
        return $adapter;
    }

    public function testRowsThatTouchTheBlockTextAskForTheirProductOnce(): void
    {
        $bunch = [
            ['sku' => 'A-1', 'description' => '<p>x</p>', 'price' => '1'],
            ['sku' => 'b-2', 'short_description' => 'y'],
            ['sku' => 'c-3', 'product_websites' => 'base'],
            ['sku' => 'a-1', 'product_websites' => 'base'],
            ['sku' => 'unknown', 'description' => 'z'],
        ];
        $this->assertSame(
            ['product 11 save', 'product 12 save', 'product 13 save'],
            $this->publish('catalog_product_import_bunch_save_after', ['adapter' => $this->adapter(), 'bunch' => $bunch])
        );
    }

    /**
     * An import column left empty does not change the value (Magento imports skip empty cells), so price-only rows of
     * a full export ask for nothing. product_type is NOT a signal: Import\Product::_prepareRowForDb() fills it (and
     * attr_set_code) with the CURRENT type of every existing product before the bunch is stored, and an import cannot
     * change the type; a new product without a website has no block text anywhere (ProductSourceReader).
     */
    public function testRowsWithoutABlockTextColumnValueAskForNothing(): void
    {
        $bunch = [
            ['sku' => 'A-1', 'price' => '1', 'description' => '', 'short_description' => ' ', 'product_type' => '', 'product_websites' => ''],
            ['sku' => 'b-2', 'qty' => '3', 'product_type' => 'simple', 'attr_set_code' => 'Default'],
            'not a row',
            ['description' => 'no sku'],
        ];
        $this->assertSame([], $this->publish('catalog_product_import_bunch_save_after', ['adapter' => $this->adapter(), 'bunch' => $bunch]));
    }

    public function testImportDeletesAskForADeletePerProduct(): void
    {
        $this->assertSame(
            ['product 21 delete', 'product 22 delete'],
            $this->publish('catalog_product_import_bunch_delete_after', ['adapter' => $this->adapter(), 'bunch' => [], 'ids_to_delete' => ['21', 22, 21, 0]])
        );
    }

    public function testAnythingElseIsIgnored(): void
    {
        $this->assertSame([], $this->publish('catalog_product_import_bunch_save_after', ['adapter' => new DataObject(), 'bunch' => [['sku' => 'a-1', 'description' => 'x']]]));
        $this->assertSame([], $this->publish('catalog_product_import_bunch_save_after', ['adapter' => $this->adapter(), 'bunch' => null]));
        $this->assertSame([], $this->publish('catalog_product_import_bunch_delete_after', ['ids_to_delete' => 'x']));
    }
}
