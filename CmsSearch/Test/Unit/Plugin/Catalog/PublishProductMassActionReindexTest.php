<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Plugin\Catalog;

use Magento\Catalog\Model\Product\Action;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Plugin\Catalog\PublishProductMassActionReindex;

/**
 * Admin "Update attributes" (async consumer product_action_attribute.update / .website.update) and every other caller
 * of Product\Action write by raw SQL: no product model event fires, so the plugin asks for the products itself.
 */
class PublishProductMassActionReindexTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function plugin(): PublishProductMassActionReindex
    {
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action): void {
            $this->calls[] = "$type $id $action";
        });
        return new PublishProductMassActionReindex($publisher);
    }

    public function testADescriptionUpdateAsksForEachProductOnce(): void
    {
        $subject = $this->createMock(Action::class);
        $result = $this->plugin()->afterUpdateAttributes($subject, $subject, ['3', 4, 3, 0, -2], ['price' => '1', 'description' => 'x'], 2);
        $this->assertSame($subject, $result);
        $this->assertSame(['product 3 save', 'product 4 save'], $this->calls);
    }

    public function testAShortDescriptionUpdateAsksToo(): void
    {
        $subject = $this->createMock(Action::class);
        $this->plugin()->afterUpdateAttributes($subject, $subject, [9], ['short_description' => ''], 0);
        $this->assertSame(['product 9 save'], $this->calls);
    }

    public function testOtherAttributesAskForNothing(): void
    {
        $subject = $this->createMock(Action::class);
        $this->plugin()->afterUpdateAttributes($subject, $subject, [3, 4], ['price' => '1', 'status' => 2, 'name' => 'x'], 0);
        $this->assertSame([], $this->calls);
    }

    public function testAWebsiteChangeAsksForEachProduct(): void
    {
        $subject = $this->createMock(Action::class);
        $this->assertNull($this->plugin()->afterUpdateWebsites($subject, null, [5, '6', 5], [2], 'remove'));
        $this->assertSame(['product 5 save', 'product 6 save'], $this->calls);
    }

    public function testMalformedArgumentsAskForNothing(): void
    {
        $subject = $this->createMock(Action::class);
        $plugin = $this->plugin();
        $plugin->afterUpdateAttributes($subject, $subject, null, ['description' => 'x'], 0);
        $plugin->afterUpdateAttributes($subject, $subject, [3], null, 0);
        $plugin->afterUpdateWebsites($subject, null, 'x', [2], 'add');
        $this->assertSame([], $this->calls);
    }
}
