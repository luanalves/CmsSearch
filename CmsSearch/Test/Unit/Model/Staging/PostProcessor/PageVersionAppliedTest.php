<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Staging\PostProcessor;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Staging\PostProcessor\PageVersionApplied;

class PageVersionAppliedTest extends TestCase
{
    public function testPublishesOneStagingMessagePerDistinctPage(): void
    {
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $calls = [];
        $publisher->expects($this->exactly(2))->method('publish')
            ->willReturnCallback(function (string $type, int $id, string $action) use (&$calls): void {
                $calls[] = [$type, $id, $action];
            });
        (new PageVersionApplied($publisher))->execute(4, 5, [5, '6', 5, 0, -1], PageInterface::class);
        $this->assertSame([['page', 5, 'staging'], ['page', 6, 'staging']], $calls);
    }

    /** @return array<string, array{0: string}> */
    public static function otherEntityTypes(): array
    {
        return [
            'CMS block (Plan 6)' => [BlockInterface::class],
            'product' => ['Magento\\Catalog\\Api\\Data\\ProductInterface'],
            'empty' => [''],
        ];
    }

    #[DataProvider('otherEntityTypes')]
    public function testOtherEntityTypesAreIgnored(string $entityType): void
    {
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        (new PageVersionApplied($publisher))->execute(4, 5, [5], $entityType);
    }

    public function testALeadingBackslashIsAccepted(): void
    {
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->expects($this->once())->method('publish')->with('page', 5, 'staging');
        (new PageVersionApplied($publisher))->execute(4, 5, [5], '\\' . PageInterface::class);
    }
}
