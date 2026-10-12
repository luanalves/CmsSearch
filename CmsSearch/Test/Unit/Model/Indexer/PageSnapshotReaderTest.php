<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\Page\IdentityMap;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\PageSnapshotReader;

class PageSnapshotReaderTest extends TestCase
{
    /** @param array<string, mixed> $data */
    private function page(array $data): Page&MockObject
    {
        $page = $this->createMock(Page::class);
        $page->method('getIdentifier')->willReturn($data['identifier'] ?? 'about');
        $page->method('getTitle')->willReturn($data['title'] ?? 'About us');
        $page->method('getContentHeading')->willReturn($data['content_heading'] ?? null);
        $page->method('getContent')->willReturn($data['content'] ?? '<p>x</p>');
        $page->method('getMetaDescription')->willReturn($data['meta_description'] ?? null);
        $page->method('isActive')->willReturn($data['is_active'] ?? true);
        $page->method('getUpdateTime')->willReturn($data['update_time'] ?? null);
        $page->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => is_string($key) ? ($data[$key] ?? null) : null
        );
        return $page;
    }

    private function reader(PageRepositoryInterface $repository, ?IdentityMap $map = null, ?VersionHistoryInterface $history = null): PageSnapshotReader
    {
        return new PageSnapshotReader(
            $repository,
            $map ?? $this->createMock(IdentityMap::class),
            $history ?? $this->createMock(VersionHistoryInterface::class)
        );
    }

    public function testTheStagingVersionIsResetOnEveryRead(): void
    {
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->page([]));
        $history = $this->createMockForIntersectionOfInterfaces([VersionHistoryInterface::class, ResetAfterRequestInterface::class]);
        $history->expects($this->exactly(3))->method('_resetState');
        $reader = $this->reader($repository, null, $history);
        $reader->read([1]);
        $reader->read([1, 2]);
        $reader->read([]);
    }

    public function testThePageIsReleasedEvenWhenTheSnapshotFails(): void
    {
        $page = $this->createMock(Page::class);
        $page->method('getIdentifier')->willThrowException(new \RuntimeException('boom'));
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getById')->willReturn($page);
        $map = $this->createMock(IdentityMap::class);
        $map->expects($this->once())->method('remove')->with(6);
        $this->expectException(\RuntimeException::class);
        $this->reader($repository, $map)->read([6]);
    }

    public function testTheCurrentVersionIsReadByPageIdThroughTheRepository(): void
    {
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->expects($this->exactly(2))->method('getById')->willReturnMap([
            [4, $this->page(['store_id' => ['0'], 'title' => 'Privacy', 'identifier' => 'privacy', 'update_time' => '2026-10-11 10:00:00'])],
            [11, $this->page(['store_id' => ['2', '3'], 'is_active' => false, 'content_heading' => 'Welcome', 'meta_description' => 'Meta'])],
        ]);
        $snapshots = $this->reader($repository)->read([4, '11', 4]);
        $this->assertSame([4, 11], array_keys($snapshots));
        $privacy = $snapshots[4];
        $this->assertSame(
            [4, 'privacy', 'Privacy', '', '<p>x</p>', '', true, [0], '2026-10-11 10:00:00'],
            [$privacy->getPageId(), $privacy->getIdentifier(), $privacy->getTitle(), $privacy->getContentHeading(),
                $privacy->getContent(), $privacy->getMetaDescription(), $privacy->isActive(), $privacy->getStoreIds(),
                $privacy->getUpdateTime()]
        );
        $this->assertSame([false, [2, 3], 'Welcome', 'Meta', null], [
            $snapshots[11]->isActive(), $snapshots[11]->getStoreIds(), $snapshots[11]->getContentHeading(),
            $snapshots[11]->getMetaDescription(), $snapshots[11]->getUpdateTime(),
        ]);
    }

    public function testLoadedPagesAreReleasedFromTheRepositoryIdentityMap(): void
    {
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->page([]));
        $identityMap = $this->createMock(IdentityMap::class);
        $removed = [];
        $identityMap->expects($this->exactly(2))->method('remove')
            ->willReturnCallback(static function (int $id) use (&$removed): void {
                $removed[] = $id;
            });
        $this->reader($repository, $identityMap)->read([3, 8]);
        $this->assertSame([3, 8], $removed);
    }

    public function testAMissingPageIsSimplyAbsent(): void
    {
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));
        $this->assertSame([], $this->reader($repository)->read([7]));
    }

    public function testOtherFailuresPropagateSoTheMessageIsRetried(): void
    {
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new \RuntimeException('MySQL server has gone away'));
        $this->expectException(\RuntimeException::class);
        $this->reader($repository)->read([7]);
    }

    public function testStoreIdsFallBackToStoresAndInvalidIdsAreIgnored(): void
    {
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->page(['stores' => ['3', '3', '5']]));
        $this->assertSame([3, 5], $this->reader($repository)->read([2, 0, -1])[2]->getStoreIds());
    }

    public function testTheCollaboratorsAreRequiredSoTheContainerAlwaysInjectsThem(): void
    {
        $parameters = (new \ReflectionClass(PageSnapshotReader::class))->getConstructor()->getParameters();
        $this->assertSame(['pageRepository', 'identityMap', 'versionHistory'], array_map(static fn ($p): string => $p->getName(), $parameters));
        foreach ($parameters as $parameter) {
            $this->assertFalse($parameter->isOptional() || $parameter->allowsNull(), $parameter->getName());
        }
    }
}
