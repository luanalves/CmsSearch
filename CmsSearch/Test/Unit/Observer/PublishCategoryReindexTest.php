<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Reference\CategorySubtreeReader;
use Thedevkitchen\CmsSearch\Observer\PublishCategoryReindex;

class PublishCategoryReindexTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    /** @var list<string> paths the subtree reader was asked for */
    private array $subtreeReads = [];

    /**
     * @param list<string> $changed
     * @param list<int>|\Throwable $descendants what the subtree reader returns (or throws) for the category's path
     * @return list<string> "type id action"
     */
    private function publish(
        string $eventName,
        array $changed,
        bool $new = false,
        string $key = 'data_object',
        array|\Throwable $descendants = [6, 7]
    ): array {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(5);
        $category->method('getPath')->willReturn('1/2/5');
        $category->method('getOrigData')->willReturnCallback(static fn ($k = null) => $k === 'entity_id' && !$new ? 5 : null);
        $category->method('dataHasChangedFor')->willReturnCallback(static fn (string $field): bool => in_array($field, $changed, true));
        $category->method('getData')->willReturnCallback(static fn ($k = '') => $k === 'deleted_children_ids' ? ['6', '7'] : null);
        $reader = $this->createMock(CategorySubtreeReader::class);
        $reader->method('descendantIds')->willReturnCallback(function (string $path) use ($descendants): array {
            $this->subtreeReads[] = $path;
            if ($descendants instanceof \Throwable) {
                throw $descendants;
            }
            return $descendants;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message): void {
            $this->logged[] = $message;
        });
        $calls = [];
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action) use (&$calls): void {
            $calls[] = "$type $id $action";
        });
        $event = new Event([$key => $category]);
        $event->setName($eventName);
        (new PublishCategoryReindex($publisher, $reader, $logger))->execute(new Observer(['event' => $event]));
        return $calls;
    }

    public function testATrackedChangeOrANewCategoryAsksForTheCategoryOnly(): void
    {
        $this->assertSame(['category 5 save'], $this->publish('catalog_category_save_after', ['description', 'position']));
        $this->assertSame(['category 5 save'], $this->publish('catalog_category_save_after', [], true));
        $this->assertSame([], $this->subtreeReads, 'no subtree read without a URL change');
    }

    public function testEveryTrackedFieldAsks(): void
    {
        foreach (['name', 'description', 'landing_page', 'display_mode', 'is_active', 'is_anchor'] as $field) {
            $this->assertSame(['category 5 save'], $this->publish('catalog_category_save_after', [$field]), $field);
        }
    }

    public function testAUrlKeyChangeAlsoAsksForEveryDescendant(): void
    {
        $this->assertSame(['category 5 save', 'category 6 save', 'category 7 save'], $this->publish('catalog_category_save_after', ['url_key']));
        $this->assertSame(['category 5 save', 'category 6 save', 'category 7 save'], $this->publish('catalog_category_save_after', ['url_path']));
        $this->assertSame(['1/2/5', '1/2/5'], $this->subtreeReads);
    }

    public function testAnUntrackedChangeAsksForNothing(): void
    {
        $this->assertSame([], $this->publish('catalog_category_save_after', ['position', 'children_count']));
    }

    public function testADeleteAsksForTheCategoryAndItsDeletedChildren(): void
    {
        $this->assertSame(['category 5 delete', 'category 6 delete', 'category 7 delete'], $this->publish('catalog_category_delete_after', []));
        $this->assertSame([], $this->subtreeReads, 'the deleted children come from the event, the tree is gone');
    }

    public function testAMoveAsksForTheMovedSubtree(): void
    {
        $this->assertSame(['category 5 save', 'category 6 save', 'category 7 save'], $this->publish('catalog_category_move_after', [], false, 'category'));
    }

    public function testDescendantsAreDeduplicatedAndBounded(): void
    {
        $this->assertSame(
            ['category 5 save', 'category 6 save', 'category 9 save'],
            $this->publish('catalog_category_move_after', [], false, 'category', [6, 5, 0, -3, 6, 9])
        );
    }

    public function testASubtreeReadFailureStillAsksForTheCategoryAndNeverThrows(): void
    {
        $this->assertSame(
            ['category 5 save'],
            $this->publish('catalog_category_move_after', [], false, 'category', new \RuntimeException('secret detail'))
        );
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('category 5', $this->logged[0]);
    }

    public function testAnythingElseIsIgnored(): void
    {
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        $unsaved = $this->createMock(Category::class);
        $unsaved->method('getId')->willReturn(null);
        foreach ([new DataObject(['id' => 5]), $unsaved] as $object) {
            $event = new Event(['data_object' => $object]);
            $event->setName('catalog_category_save_after');
            (new PublishCategoryReindex($publisher, $this->createMock(CategorySubtreeReader::class), $this->createMock(LoggerInterface::class)))
                ->execute(new Observer(['event' => $event]));
        }
    }
}
