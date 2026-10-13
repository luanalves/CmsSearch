<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Observer\PublishProductReindex;

class PublishProductReindexTest extends TestCase
{
    /**
     * @param list<string> $changed fields whose value changed in this save
     * @param array{0: mixed, 1: mixed}|null $websites [website ids before the save, after the save]; null = not loaded
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function publish(string $eventName, bool $new, array $changed, ?array $websites = null, string $key = 'data_object'): array
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(7);
        $product->method('getOrigData')->willReturnCallback(static fn ($k = null) => match (true) {
            $k === 'entity_id' => $new ? null : 7,
            $k === 'website_ids' => $websites[0] ?? null,
            default => null,
        });
        $product->method('dataHasChangedFor')->willReturnCallback(static fn (string $field): bool => in_array($field, $changed, true));
        if (($websites[1] ?? null) instanceof \Throwable) {
            $product->method('getWebsiteIds')->willThrowException($websites[1]);
        } else {
            $product->method('getWebsiteIds')->willReturn($websites[1] ?? []);
        }
        $calls = [];
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action) use (&$calls): void {
            $calls[] = [$type, $id, $action];
        });
        $event = new Event($key === 'data_object' ? ['product' => $product, 'data_object' => $product] : [$key => $product]);
        $event->setName($eventName);
        (new PublishProductReindex($publisher))->execute(new Observer(['event' => $event]));
        return $calls;
    }

    public function testANewProductAsksForItsBlockText(): void
    {
        $this->assertSame([['product', 7, 'save']], $this->publish('catalog_product_save_after', true, []));
    }

    public function testADescriptionChangeAsksForItsBlockText(): void
    {
        $this->assertSame([['product', 7, 'save']], $this->publish('catalog_product_save_after', false, ['price', 'short_description']));
    }

    public function testEveryTrackedFieldAsks(): void
    {
        foreach (['description', 'short_description', 'type_id'] as $field) {
            $this->assertSame([['product', 7, 'save']], $this->publish('catalog_product_save_after', false, [$field]), $field);
        }
    }

    public function testAnUnrelatedChangeAsksForNothing(): void
    {
        $this->assertSame([], $this->publish('catalog_product_save_after', false, ['price', 'qty', 'name']));
    }

    public function testADeletedProductAsksForADelete(): void
    {
        $this->assertSame([['product', 7, 'delete']], $this->publish('catalog_product_delete_after', false, []));
    }

    /**
     * The block text of a product exists per store view of its websites (ProductSourceReader): adding or removing a
     * website changes where it is written, without any attribute change.
     */
    public function testAWebsiteAssignmentChangeAsksForItsBlockText(): void
    {
        $this->assertSame([['product', 7, 'save']], $this->publish('catalog_product_save_after', false, ['price'], [['1'], [1, 2]]));
        $this->assertSame([['product', 7, 'save']], $this->publish('catalog_product_save_after', false, [], [[1, 2], ['2']]));
    }

    public function testTheSameWebsitesInAnotherOrderOrTypeAskForNothing(): void
    {
        $this->assertSame([], $this->publish('catalog_product_save_after', false, ['price'], [['2', '1'], [1, 2]]));
        $this->assertSame([], $this->publish('catalog_product_save_after', false, ['price'], [null, [1, 2]]));
    }

    public function testAWebsiteLookupFailureAsksAnyway(): void
    {
        // never breaks the save; a redundant reindex is harmless, a missed one is not
        $this->assertSame([['product', 7, 'save']], $this->publish('catalog_product_save_after', false, [], [[1], new \RuntimeException('db')]));
    }

    public function testTheProductIsAlsoFoundUnderItsEventObjectName(): void
    {
        $this->assertSame([['product', 7, 'delete']], $this->publish('catalog_product_delete_after', false, [], null, 'product'));
    }

    public function testAnythingElseIsIgnored(): void
    {
        $calls = [];
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function () use (&$calls): void {
            $calls[] = func_get_args();
        });
        $unsaved = $this->createMock(Product::class);
        $unsaved->method('getId')->willReturn(null);
        foreach ([new DataObject(['id' => 7]), $unsaved] as $object) {
            $event = new Event(['data_object' => $object]);
            $event->setName('catalog_product_save_after');
            (new PublishProductReindex($publisher))->execute(new Observer(['event' => $event]));
        }
        $this->assertSame([], $calls);
    }
}
