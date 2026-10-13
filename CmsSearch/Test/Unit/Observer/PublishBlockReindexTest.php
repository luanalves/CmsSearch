<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Cms\Model\Block;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Observer\PublishBlockReindex;

class PublishBlockReindexTest extends TestCase
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
        (new PublishBlockReindex($publisher))->execute(new Observer(['event' => $event]));
        return $calls;
    }

    private function block(?int $id): Block
    {
        $block = $this->createMock(Block::class);
        $block->method('getId')->willReturn($id);
        return $block;
    }

    public function testASavedBlockAsksForOneBlockReindex(): void
    {
        $block = $this->block(12);
        $this->assertSame([['block', 12, 'save']], $this->publish('cms_block_save_after', ['data_object' => $block, 'object' => $block]));
    }

    public function testADeletedBlockAsksForADelete(): void
    {
        $this->assertSame([['block', 12, 'delete']], $this->publish('cms_block_delete_after', ['object' => $this->block(12)]));
    }

    public function testAnythingElseIsIgnored(): void
    {
        $this->assertSame([], $this->publish('cms_block_save_after', ['data_object' => new DataObject(['id' => 3])]));
        $this->assertSame([], $this->publish('cms_block_save_after', ['data_object' => $this->block(null)]));
    }

    /**
     * cms_block is staged: the model also carries row_id (the version row). Messages are keyed by block_id (getId(),
     * the MetadataPool identifier field), which every version of the block shares.
     */
    public function testTheMessageIsKeyedByBlockIdNeverByRowId(): void
    {
        $block = $this->createMock(Block::class);
        $block->method('getId')->willReturn(12);
        $block->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => match ($key) {
                'row_id' => 97,
                'block_id' => 12,
                default => null,
            }
        );
        $this->assertSame([['block', 12, 'save']], $this->publish('cms_block_save_after', ['data_object' => $block, 'object' => $block]));
    }
}
