<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Banner\Model\Banner;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Observer\PublishDynamicBlockReindex;

class PublishDynamicBlockReindexTest extends TestCase
{
    /** @return list<array{0: string, 1: int, 2: string}> */
    private function publish(string $eventName, array $data): array
    {
        $calls = [];
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action) use (&$calls): void {
            $calls[] = [$type, $id, $action];
        });
        $event = new Event($data);
        $event->setName($eventName);
        (new PublishDynamicBlockReindex($publisher))->execute(new Observer(['event' => $event]));
        return $calls;
    }

    public function testSaveAndDeleteOfADynamicBlock(): void
    {
        $banner = $this->createMock(Banner::class);
        $banner->method('getId')->willReturn(3);
        $this->assertSame([['dynamic_block', 3, 'save']], $this->publish('magento_banner_save_after', ['banner' => $banner]));
        $this->assertSame([['dynamic_block', 3, 'delete']], $this->publish('magento_banner_delete_after', ['data_object' => $banner]));
    }

    public function testAnythingElseIsIgnored(): void
    {
        $this->assertSame([], $this->publish('magento_banner_save_after', ['banner' => new DataObject(['id' => 3])]));
        $unsaved = $this->createMock(Banner::class);
        $unsaved->method('getId')->willReturn(null);
        $this->assertSame([], $this->publish('magento_banner_save_after', ['banner' => $unsaved, 'data_object' => $unsaved]));
    }
}
