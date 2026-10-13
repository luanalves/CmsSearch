<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\CategorySnapshotReader;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\CategorySnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\PageSnapshotReader;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostFacts;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSource;
use Thedevkitchen\CmsSearch\Model\Reference\HostSourceReader;
use Thedevkitchen\CmsSearch\Model\Reference\ProductSourceReader;

class HostSourceReaderTest extends TestCase
{
    private function reader(
        ?PageSnapshotReader $pages = null,
        ?ProductSourceReader $products = null,
        ?CategorySnapshotReader $categories = null,
        ?ShadowedPageReader $shadowed = null,
        ?StoreManagerInterface $storeManager = null
    ): HostSourceReader {
        if ($storeManager === null) {
            $store = $this->createMock(Store::class);
            $store->method('getRootCategoryId')->willReturn(2);
            $storeManager = $this->createMock(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($store);
        }
        if ($shadowed === null) {
            $shadowed = $this->createMock(ShadowedPageReader::class);
            $shadowed->method('read')->willReturn([]);
        }
        return new HostSourceReader(
            $pages ?? $this->createMock(PageSnapshotReader::class),
            $products ?? $this->createMock(ProductSourceReader::class),
            $categories ?? $this->createMock(CategorySnapshotReader::class),
            $storeManager,
            $shadowed
        );
    }

    private static function page(int $id, string $identifier, bool $active, array $stores, string $content = '<p>x</p>'): PageSnapshot
    {
        return new PageSnapshot($id, $identifier, 't', '', $content, '', $active, $stores, null);
    }

    public function testOnlyActivePagesOfTheStoreViewAreSources(): void
    {
        $pages = $this->createMock(PageSnapshotReader::class);
        $pages->expects($this->once())->method('read')->with([1, 2, 3, 4])->willReturn([
            1 => self::page(1, 'all-stores', true, [0], '<p>a</p>'),
            2 => self::page(2, 'other-store', true, [3], '<p>b</p>'),
            3 => self::page(3, 'disabled', false, [2], '<p>c</p>'),
            4 => self::page(4, 'news/x', true, [2], '{{block id="n"}}'),
        ]);
        $sources = $this->reader($pages)->read('page', [1, 2, 3, 4, 4, 0], 2);
        $this->assertSame([1, 4], array_keys($sources));
        $this->assertSame(['{{block id="n"}}'], $sources[4]->getContents());
        $this->assertSame(['page', 4, 'news/x'], [$sources[4]->getFacts()->getHostType(), $sources[4]->getFacts()->getHostId(), $sources[4]->getFacts()->getIdentifier()]);
        $this->assertSame([], $sources[4]->getExtraRefs());
    }

    public function testAStoreZeroPageShadowedInTheStoreViewIsNoSource(): void
    {
        // this install: page 2 `home` (All Store Views) is not served in store views 2-7, which have their own `home`
        $snapshots = [
            2 => self::page(2, 'home', true, [0]),
            12 => self::page(12, 'home', true, [2]),
            5 => self::page(5, 'about', true, [0]),
            6 => self::page(6, 'off', false, [0]),
        ];
        $pages = $this->createMock(PageSnapshotReader::class);
        $pages->method('read')->willReturn($snapshots);
        $shadowed = $this->createMock(ShadowedPageReader::class);
        $shadowed->expects($this->once())->method('read')
            ->with([2 => $snapshots[2], 12 => $snapshots[12], 5 => $snapshots[5]], [2])
            ->willReturn([2 => [2]]);
        $sources = $this->reader($pages, null, null, $shadowed)->read('page', [2, 12, 5, 6], 2);
        $this->assertSame([5, 12], array_keys($sources));
    }

    public function testNoShadowLookupWhenNoPageIsShown(): void
    {
        $pages = $this->createMock(PageSnapshotReader::class);
        $pages->method('read')->willReturn([6 => self::page(6, 'off', false, [0])]);
        $shadowed = $this->createMock(ShadowedPageReader::class);
        $shadowed->expects($this->never())->method('read');
        $this->assertSame([], $this->reader($pages, null, null, $shadowed)->read('page', [6], 1));
    }

    public function testAPageWithoutContentHasNoContents(): void
    {
        $pages = $this->createMock(PageSnapshotReader::class);
        $pages->method('read')->willReturn([7 => self::page(7, 'empty', true, [0], "  \n")]);
        $sources = $this->reader($pages)->read('page', [7], 1);
        $this->assertSame([], $sources[7]->getContents());
    }

    public function testCategoriesOfTheStoreTreeWithTheirLandingBlock(): void
    {
        $categories = $this->createMock(CategorySnapshotReader::class);
        $categories->expects($this->once())->method('read')->with([37, 38, 39, 40], 1)->willReturn([
            37 => new CategorySnapshot(37, '1/2/37', 2, 'Gear', '<p>{{block id="g"}}</p>', 5, 'PRODUCTS_AND_PAGE', true, true, null, null),
            38 => new CategorySnapshot(38, '1/103/38', 2, 'Other root', '<p>x</p>', 0, 'PRODUCTS', false, true, null, null),
            39 => new CategorySnapshot(39, '1/2/39', 2, 'Off', '<p>x</p>', 0, 'PRODUCTS', false, false, null, null),
            40 => new CategorySnapshot(40, '1/2/40', 2, 'Products only', '', 6, 'PRODUCTS', false, true, null, null),
        ]);
        $sources = $this->reader(null, null, $categories)->read('category', [37, 38, 39, 40], 1);
        $this->assertSame([37, 40], array_keys($sources));
        $this->assertSame(['<p>{{block id="g"}}</p>'], $sources[37]->getContents());
        $this->assertSame(['block#5'], array_map(static fn (BlockRef $r): string => $r->key(), $sources[37]->getExtraRefs()));
        $this->assertTrue($sources[37]->getFacts()->isAnchor());
        $this->assertSame(['category', 37], [$sources[37]->getFacts()->getHostType(), $sources[37]->getFacts()->getHostId()]);
        $this->assertFalse($sources[40]->getFacts()->isAnchor());
        $this->assertSame([[], []], [$sources[40]->getContents(), $sources[40]->getExtraRefs()]);
    }

    public function testALandingPageIdOutsideTheBlockIdRangeIsNoReference(): void
    {
        $categories = $this->createMock(CategorySnapshotReader::class);
        $categories->method('read')->willReturn([
            37 => new CategorySnapshot(37, '1/2/37', 2, 'n', '', 32768, 'PAGE', false, true, null, null),
            38 => new CategorySnapshot(38, '1/2/38', 2, 'n', '', 32767, 'PAGE', false, true, null, null),
        ]);
        $sources = $this->reader(null, null, $categories)->read('category', [37, 38], 1);
        $this->assertSame([], $sources[37]->getExtraRefs());
        $this->assertSame(['block#32767'], array_map(static fn (BlockRef $r): string => $r->key(), $sources[38]->getExtraRefs()));
    }

    public function testAnUnknownStoreViewHasNoCategories(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException());
        $categories = $this->createMock(CategorySnapshotReader::class);
        $categories->expects($this->never())->method('read');
        $this->assertSame([], $this->reader(null, null, $categories, null, $storeManager)->read('category', [37], 99));
    }

    public function testProductsComeFromTheProductReader(): void
    {
        $source = new HostSource(new HostFacts('product', 7, '', 'simple'), ['<p>d</p>']);
        $products = $this->createMock(ProductSourceReader::class);
        $products->expects($this->once())->method('read')->with([7], 3)->willReturn([7 => $source]);
        $this->assertSame([7 => $source], $this->reader(null, $products)->read('product', ['7'], 3));
    }

    public function testNoValidIdReadsNothing(): void
    {
        $pages = $this->createMock(PageSnapshotReader::class);
        $pages->expects($this->never())->method('read');
        $products = $this->createMock(ProductSourceReader::class);
        $products->expects($this->never())->method('read');
        $categories = $this->createMock(CategorySnapshotReader::class);
        $categories->expects($this->never())->method('read');
        $reader = $this->reader($pages, $products, $categories);
        foreach (['page', 'product', 'category'] as $type) {
            $this->assertSame([], $reader->read($type, [0, -4, 'x'], 1));
        }
    }

    public function testAnUnknownHostTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reader()->read('cart', [1], 1);
    }
}
