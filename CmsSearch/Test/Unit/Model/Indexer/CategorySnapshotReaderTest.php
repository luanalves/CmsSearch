<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\CategorySnapshotReader;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\CategorySnapshot;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class CategorySnapshotReaderTest extends TestCase
{
    use DbStubTrait;

    private const IDS = ['name' => 45, 'display_mode' => 61, 'description' => 50, 'landing_page' => 52, 'is_anchor' => 53, 'is_active' => 54];

    private (VersionHistoryInterface&ResetAfterRequestInterface&MockObject)|null $history = null;

    private function reader(array $fetchAll, array $fetchPairs = [], string $linkField = 'row_id', int $chunkSize = 500): CategorySnapshotReader
    {
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);
        $eav = $this->createMock(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function (string $entity, string $code) {
            $this->assertSame('catalog_category', $entity);
            $attribute = $this->createMock(AbstractAttribute::class);
            $attribute->method('getAttributeId')->willReturn(self::IDS[$code]);
            return $attribute;
        });
        $this->history ??= $this->createMockForIntersectionOfInterfaces([VersionHistoryInterface::class, ResetAfterRequestInterface::class]);
        return new CategorySnapshotReader($this->db($fetchAll, [], $fetchPairs), $pool, $eav, $this->history, $chunkSize);
    }

    private static function value(int $rowId, string $code, int $storeId, mixed $value, string $linkField = 'row_id'): array
    {
        return [$linkField => $rowId, 'attribute_id' => self::IDS[$code], 'store_id' => $storeId, 'value' => $value];
    }

    public function testEveryAttributeWithStoreFallbackAndTheUrlInFiveQueries(): void
    {
        $snapshots = $this->reader([
            [
                ['entity_id' => 37, 'row_id' => 370, 'path' => '1/2/37', 'level' => 2, 'updated_at' => '2026-10-10 10:00:00'],
                ['entity_id' => 38, 'row_id' => 380, 'path' => '1/2/37/38', 'level' => 3, 'updated_at' => null],
            ],
            [self::value(370, 'name', 0, 'Gear'), self::value(370, 'display_mode', 0, 'PRODUCTS_AND_PAGE'),
                self::value(380, 'name', 0, 'Bags'), self::value(370, 'name', 1, 'Gear EN')],
            [self::value(370, 'description', 0, '<p>About gear</p>')],
            [self::value(370, 'landing_page', 0, '5'), self::value(370, 'is_anchor', 0, '1'), self::value(370, 'is_active', 0, '1'),
                self::value(380, 'is_active', 0, '1'), self::value(380, 'is_active', 1, '0')],
        ], [[37 => 'gear.html']])->read([37, 38, 37, 0], 1);
        $this->assertSame([37, 38], array_keys($snapshots));
        $gear = $snapshots[37];
        $this->assertSame(
            [37, '1/2/37', 2, 'Gear EN', '<p>About gear</p>', 5, 'PRODUCTS_AND_PAGE', true, true, 'gear.html', '2026-10-10 10:00:00'],
            [$gear->getCategoryId(), $gear->getPath(), $gear->getLevel(), $gear->getName(), $gear->getDescription(), $gear->getLandingPage(),
                $gear->getDisplayMode(), $gear->isAnchor(), $gear->isActive(), $gear->getRequestPath(), $gear->getUpdatedAt()]
        );
        $bags = $snapshots[38];
        $this->assertSame(['Bags', '', 0, 'PRODUCTS', false, false, null], [
            $bags->getName(), $bags->getDescription(), $bags->getLandingPage(), $bags->getDisplayMode(), $bags->isAnchor(), $bags->isActive(), $bags->getRequestPath(),
        ]);
        $this->assertSame([[37, 38], [37, 38]], $this->whereValues('entity_id IN (?)')); // entities, url rewrites
        $this->assertSame([[370, 380], [370, 380], [370, 380]], $this->whereValues('row_id IN (?)'));
        $this->assertSame([[0, 1], [0, 1], [0, 1]], $this->whereValues('store_id IN (?)'));
        $this->assertSame([[45, 61], [50], [52, 53, 54]], $this->whereValues('attribute_id IN (?)'));
        $this->assertSame(['category'], $this->whereValues('entity_type = ?'));
        $this->assertSame([1], $this->whereValues('store_id = ?'));
        $this->assertSame([0], $this->whereValues('redirect_type = ?'));
        $this->assertSame(['store_id ASC', 'store_id ASC', 'store_id ASC'], $this->orders);
        $this->assertSame(
            ['catalog_category_entity', 'catalog_category_entity_varchar', 'catalog_category_entity_text', 'catalog_category_entity_int', 'url_rewrite'],
            $this->tables
        );
    }

    public function testTheStoreValueWinsWhicheverOrderTheRowsArriveIn(): void
    {
        // the store row arrives FIRST (the ORDER BY is a guarantee of the database, the reader must not depend on it)
        $snapshots = $this->reader([
            [['entity_id' => 37, 'row_id' => 370, 'path' => '1/2/37', 'level' => 2, 'updated_at' => null]],
            [self::value(370, 'name', 3, 'Loja'), self::value(370, 'name', 0, 'Store')],
            [],
            [self::value(370, 'is_active', 3, '0'), self::value(370, 'is_active', 0, '1')],
        ], [[]])->read([37], 3);
        $this->assertSame(['Loja', false], [$snapshots[37]->getName(), $snapshots[37]->isActive()]);
    }

    public function testCommunityEditionLinksValuesByEntityId(): void
    {
        $snapshots = $this->reader([
            [['entity_id' => 37, 'path' => '1/2/37', 'level' => 2, 'updated_at' => null]],
            [self::value(37, 'name', 0, 'Gear', 'entity_id')],
            [],
            [self::value(37, 'is_active', 0, '1', 'entity_id')],
        ], [[]], 'entity_id')->read([37], 1);
        $this->assertSame(['Gear', true], [$snapshots[37]->getName(), $snapshots[37]->isActive()]);
        $this->assertSame([[37], [37], [37], [37], [37]], $this->whereValues('entity_id IN (?)'));
        $this->assertSame([], $this->whereValues('row_id IN (?)'));
    }

    public function testValuesOfAnotherVersionRowAreIgnored(): void
    {
        // a row_id that is not the current version of a requested category (the entity query decides the version)
        $snapshots = $this->reader([
            [['entity_id' => 37, 'row_id' => 371, 'path' => '1/2/37', 'level' => 2, 'updated_at' => null]],
            [self::value(370, 'name', 0, 'Old'), self::value(371, 'name', 0, 'Current')],
            [],
            [],
        ], [[]])->read([37], 1);
        $this->assertSame('Current', $snapshots[37]->getName());
        $this->assertSame([[371], [371], [371]], $this->whereValues('row_id IN (?)'));
    }

    public function testLargeIdListsAreReadInChunksOfFiveQueries(): void
    {
        $entity = static fn (int $id): array => ['entity_id' => $id, 'row_id' => $id * 10, 'path' => '1/2/' . $id, 'level' => 2, 'updated_at' => null];
        $snapshots = $this->reader([
            [$entity(37), $entity(38)], [self::value(370, 'name', 0, 'A'), self::value(380, 'name', 0, 'B')], [], [],
            [$entity(39)], [self::value(390, 'name', 0, 'C')], [], [],
        ], [[37 => 'a.html'], [39 => 'c.html']], 'row_id', 2)->read([39, 37, 38], 1);
        $this->assertSame([37, 38, 39], array_keys($snapshots));
        $this->assertSame(['A', 'B', 'C'], array_map(static fn (CategorySnapshot $c): string => $c->getName(), array_values($snapshots)));
        $this->assertSame(['a.html', null, 'c.html'], array_map(static fn (CategorySnapshot $c): ?string => $c->getRequestPath(), array_values($snapshots)));
        $this->assertSame([[37, 38], [37, 38], [39], [39]], $this->whereValues('entity_id IN (?)'));
        $this->assertSame([[370, 380], [370, 380], [370, 380], [390], [390], [390]], $this->whereValues('row_id IN (?)'));
    }

    public function testAnEmptyChunkSkipsItsValueQueriesButNotTheNextChunk(): void
    {
        $snapshots = $this->reader([
            [],
            [['entity_id' => 39, 'row_id' => 390, 'path' => '1/2/39', 'level' => 2, 'updated_at' => null]], [], [], [],
        ], [[]], 'row_id', 1)->read([38, 39], 1);
        $this->assertSame([39], array_keys($snapshots));
    }

    public function testTheStagingVersionIsReloadedOncePerRead(): void
    {
        $reader = $this->reader([[]]);
        $this->history->expects($this->once())->method('_resetState');
        $reader->read([37], 1);
    }

    public function testMissingCategoriesStopAfterTheEntityQuery(): void
    {
        $this->assertSame([], $this->reader([[]])->read([99], 1));
        $this->history = null;
        $this->assertSame([], $this->reader([])->read([0, -3], 1));
    }

    public function testLandingPageAndTreeRules(): void
    {
        $category = static fn (string $path, int $level, string $mode, int $landing = 5): CategorySnapshot => new CategorySnapshot(37, $path, $level, 'n', '', $landing, $mode, false, true, null, null);
        $this->assertSame(5, $category('1/2/37', 2, 'PAGE')->getLandingBlockId());
        $this->assertSame(5, $category('1/2/37', 2, 'PRODUCTS_AND_PAGE')->getLandingBlockId());
        // Catalog\Block\Category\View::isContentMode()/isMixedMode() compare with ==: case matters on the storefront
        $this->assertSame(0, $category('1/2/37', 2, 'products_and_page')->getLandingBlockId());
        $this->assertSame(0, $category('1/2/37', 2, 'PRODUCTS')->getLandingBlockId());
        $this->assertSame(0, $category('1/2/37', 2, 'PAGE', 0)->getLandingBlockId());
        $this->assertTrue($category('1/2/37', 2, 'PRODUCTS')->isInTree(2));
        $this->assertTrue($category('1/2/37/38', 3, 'PRODUCTS')->isInTree(2));
        $this->assertFalse($category('1/2/37', 2, 'PRODUCTS')->isInTree(103), 'another store view root');
        $this->assertFalse($category('1/2', 1, 'PRODUCTS')->isInTree(2), 'the root itself has no page');
        $this->assertFalse($category('1/21/37', 2, 'PRODUCTS')->isInTree(2), 'prefix of another root id');
        $this->assertFalse($category('1/2/37', 2, 'PRODUCTS')->isInTree(0));
    }
}
