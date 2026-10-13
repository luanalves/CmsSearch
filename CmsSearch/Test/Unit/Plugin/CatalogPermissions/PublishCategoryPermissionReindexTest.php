<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Plugin\CatalogPermissions;

use Magento\CatalogPermissions\Model\Indexer\Category as PermissionIndexer;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Plugin\CatalogPermissions\PublishCategoryPermissionReindex;

/**
 * The permission index is written after Category::save commits: the plugin asks for the categories again afterwards.
 */
class PublishCategoryPermissionReindexTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function plugin(bool $failing = false): PublishCategoryPermissionReindex
    {
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action) use ($failing): void {
            if ($failing) {
                throw new \RuntimeException('broker down');
            }
            $this->calls[] = "$type $id $action";
        });
        return new PublishCategoryPermissionReindex($publisher);
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
}
