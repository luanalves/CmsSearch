<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Widget\Model\Widget\Instance;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Observer\PublishWidgetInstanceReindex;

class PublishWidgetInstanceReindexTest extends TestCase
{
    /** @return list<array{0: string, 1: int, 2: string}> */
    private function publish(string $eventName, string $type, int $id = 18): array
    {
        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn($id);
        $instance->method('getData')->willReturnCallback(static fn ($key = '') => $key === 'instance_type' ? $type : null);
        $calls = [];
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $t, int $i, string $action) use (&$calls): void {
            $calls[] = [$t, $i, $action];
        });
        $event = new Event(['data_object' => $instance, 'object' => $instance]);
        $event->setName($eventName);
        (new PublishWidgetInstanceReindex($publisher))->execute(new Observer(['event' => $event]));
        return $calls;
    }

    public function testACmsBlockWidgetInstanceAsksForItsReindex(): void
    {
        $this->assertSame([['widget_instance', 18, 'save']], $this->publish('widget_widget_instance_save_after', 'Magento\\Cms\\Block\\Widget\\Block'));
    }

    public function testADeletedDynamicBlocksWidgetInstanceAsksForADelete(): void
    {
        $this->assertSame([['widget_instance', 18, 'delete']], $this->publish('widget_widget_instance_delete_after', 'Magento\\\\Banner\\\\Block\\\\Widget\\\\Banner'));
    }

    public function testOtherWidgetsCauseNoWork(): void
    {
        $this->assertSame([], $this->publish('widget_widget_instance_save_after', 'Magento\\CatalogWidget\\Block\\Product\\ProductsList'));
        $this->assertSame([], $this->publish('widget_widget_instance_save_after', 'Magento\\Cms\\Block\\Widget\\Block', 0));
    }
}
