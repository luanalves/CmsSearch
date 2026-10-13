<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Plugin\CatalogPermissions;

use Magento\CatalogPermissions\Model\Indexer\Category as PermissionIndexer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Reference\CategorySubtreeReader;
use Thedevkitchen\CmsSearch\Plugin\CatalogPermissions\PublishCategoryPermissionReindex;

/**
 * The permission index is written after Category::save commits: the plugin asks for the categories again afterwards.
 */
class PublishCategoryPermissionReindexTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    /** @var array<int, list<int>> category id => descendants (the lookup stub) */
    private array $children = [];
    private bool $lookupFails = false;
    private int $logged = 0;

    private function plugin(bool $failing = false): PublishCategoryPermissionReindex
    {
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action) use ($failing): void {
            if ($failing) {
                throw new \RuntimeException('broker down');
            }
            $this->calls[] = "$type $id $action";
        });
        $reader = $this->createMock(CategorySubtreeReader::class);
        $reader->method('withDescendants')->willReturnCallback(function (array $ids): array {
            if ($this->lookupFails) {
                throw new \RuntimeException('secret sql detail');
            }
            $all = $ids;
            foreach ($ids as $id) {
                $all = array_merge($all, $this->children[$id] ?? []);
            }
            return $all;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message) use (&$loggedMessage): void {
            $this->logged++;
            $this->assertStringNotContainsString('secret', $message, 'class only');
        });
        return new PublishCategoryPermissionReindex($publisher, $reader, $logger);
    }

    public function testExecuteRowAsksForTheCategory(): void
    {
        $subject = $this->createMock(PermissionIndexer::class);
        $this->assertNull($this->plugin()->afterExecuteRow($subject, null, '37'));
        $this->assertSame(['category 37 save'], $this->calls);
    }

    public function testExecuteListAsksForEachDistinctPositiveId(): void
    {
        $subject = $this->createMock(PermissionIndexer::class);
        $this->plugin()->afterExecuteList($subject, null, [3, '4', 3, 0, -1, 'x']);
        $this->assertSame(['category 3 save', 'category 4 save'], $this->calls);
    }

    public function testTheMviewEntryPointAsksToo(): void
    {
        $subject = $this->createMock(PermissionIndexer::class);
        $this->plugin()->afterExecute($subject, null, [8, 9]);
        $this->assertSame(['category 8 save', 'category 9 save'], $this->calls);
    }

    public function testAnEmptyOrMalformedListAsksForNothing(): void
    {
        $subject = $this->createMock(PermissionIndexer::class);
        $plugin = $this->plugin();
        $plugin->afterExecuteList($subject, null, []);
        $plugin->afterExecuteList($subject, null, null);
        $plugin->afterExecuteRow($subject, null, 0);
        $this->assertSame([], $this->calls);
    }

    public function testAFailingPublisherNeverBreaksThePermissionReindex(): void
    {
        $subject = $this->createMock(PermissionIndexer::class);
        $this->assertSame('kept', $this->plugin(true)->afterExecuteList($subject, 'kept', [1, 2]));
    }

    public function testThereIsNoFullReindexHook(): void
    {
        $this->assertFalse(method_exists(PublishCategoryPermissionReindex::class, 'afterExecuteFull'));
    }

    public function testADenyOnAParentReachesItsDescendants(): void
    {
        $this->children = [5 => [6, 7]];
        $subject = $this->createMock(PermissionIndexer::class);
        $this->plugin()->afterExecuteRow($subject, null, 5);
        $this->assertSame(['category 5 save', 'category 6 save', 'category 7 save'], $this->calls);
    }

    public function testListAndMviewEntryPointsExpandTheSubtreeAndDedupe(): void
    {
        $this->children = [5 => [6, 7], 8 => [7, 9]];
        $subject = $this->createMock(PermissionIndexer::class);
        $plugin = $this->plugin();
        $plugin->afterExecuteList($subject, null, [5, 8, 6]);
        $plugin->afterExecute($subject, null, [8]);
        $this->assertSame([
            'category 5 save', 'category 8 save', 'category 6 save', 'category 7 save', 'category 9 save',
            'category 8 save', 'category 7 save', 'category 9 save',
        ], $this->calls);
    }

    public function testAFailingSubtreeLookupStillPublishesTheOriginalIdsAndLogsTheClassOnly(): void
    {
        $this->lookupFails = true;
        $subject = $this->createMock(PermissionIndexer::class);
        $this->plugin()->afterExecuteList($subject, 'kept', [5, 5, 9]);
        $this->assertSame(['category 5 save', 'category 9 save'], $this->calls);
        $this->assertSame(1, $this->logged);
    }
}
