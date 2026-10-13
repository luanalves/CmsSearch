<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Staging\PostProcessor;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Queue\SentMessageLog;
use Thedevkitchen\CmsSearch\Model\Staging\PostProcessor\ReferenceVersionApplied;

class ReferenceVersionAppliedTest extends TestCase
{
    /** @return list<string> */
    private function apply(array $ids, string $entityType, ?SentMessageLog $log = null): array
    {
        $calls = [];
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action) use (&$calls): void {
            $calls[] = "$type $id $action";
        });
        (new ReferenceVersionApplied($publisher, $log ?? new SentMessageLog()))->execute(4, 5, $ids, $entityType);
        return $calls;
    }

    public function testBlocksPublishOneStagingMessagePerDistinctId(): void
    {
        $this->assertSame(['block 5 staging', 'block 6 staging'], $this->apply([5, '6', 5, 0, -1], BlockInterface::class));
    }

    public function testProductsAndCategoriesToo(): void
    {
        $this->assertSame(['product 7 staging'], $this->apply([7], '\\' . ProductInterface::class));
        $this->assertSame(['category 37 staging'], $this->apply(['37'], CategoryInterface::class));
    }

    public function testPagesAndOtherTypesAreLeftAlone(): void
    {
        $this->assertSame([], $this->apply([5], PageInterface::class)); // PageVersionApplied (Plan 3) handles pages
        $this->assertSame([], $this->apply([5], 'Magento\\SalesRule\\Api\\Data\\RuleInterface'));
        $this->assertSame([], $this->apply([5], ''));
    }

    public function testAnEntityTheApplierRunAlreadySentIsSkipped(): void
    {
        $log = new SentMessageLog();
        $log->open();
        $log->record(new ReindexMessage('block', 5, 'save', 1760000000)); // the applier's re-save of block 5
        $this->assertSame(['block 6 staging', 'product 5 staging'], array_merge(
            $this->apply([5, 6], BlockInterface::class, $log),
            $this->apply([5], ProductInterface::class, $log) // another entity type with the same id still publishes
        ));
    }
}
