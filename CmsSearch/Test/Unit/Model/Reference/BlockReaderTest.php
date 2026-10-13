<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReader;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockSource;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class BlockReaderTest extends TestCase
{
    use DbStubTrait;

    private (VersionHistoryInterface&ResetAfterRequestInterface&MockObject)|null $history = null;

    private function reader(ResourceConnection $resource, int $chunkSize = 1000): BlockReader
    {
        $this->history = $this->createMockForIntersectionOfInterfaces([VersionHistoryInterface::class, ResetAfterRequestInterface::class]);
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('row_id');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);
        return new BlockReader($resource, $pool, $this->history, $chunkSize);
    }

    private static function block(int $id, string $identifier, string $content, int $storeId): array
    {
        return ['block_id' => $id, 'identifier' => $identifier, 'content' => $content, 'store_id' => $storeId];
    }

    public function testNoReferenceRunsNoQuery(): void
    {
        $this->assertSame([], $this->reader($this->db())->read([], 2));
    }

    public function testBlocksByIdFollowTheNativeActiveAndStoreRules(): void
    {
        $sources = $this->reader($this->db([[self::block(5, 'promo', '<p>Promo</p>', 0)]]))
            ->read([new BlockRef('block', 5), new BlockRef('block', 9)], 2);
        $this->assertSame(['block#5'], array_keys($sources));
        $this->assertSame(['block', 5, 'promo', '<p>Promo</p>', []], [
            $sources['block#5']->getKind(), $sources['block#5']->getId(), $sources['block#5']->getIdentifier(),
            $sources['block#5']->getContent(), $sources['block#5']->getSegmentIds(),
        ]);
        $this->assertSame([[5, 9]], $this->whereValues('b.block_id IN (?)'));
        $this->assertSame([1], $this->whereValues('b.is_active = ?'));
        $this->assertSame([[0, 2]], $this->whereValues('s.store_id IN (?)'));
        $this->assertSame(['s.row_id = b.row_id'], $this->joins);
        $this->assertSame(['cms_block', 'cms_block_store'], $this->tables);
        $this->assertSame(['s.store_id DESC'], $this->orders);
    }

    public function testAnIdentifierPrefersTheStoreViewsOwnBlockAndIgnoresCase(): void
    {
        // ORDER BY store_id DESC: the store view's own "Promo" (8) comes before the All Store Views "promo" (3)
        $sources = $this->reader($this->db([[
            self::block(8, 'Promo', '<p>own</p>', 2),
            self::block(3, 'promo', '<p>shared</p>', 0),
        ]]))->read([new BlockRef('block', 0, 'PROMO')], 2);
        $this->assertSame(['block@promo'], array_keys($sources));
        $this->assertSame(8, $sources['block@promo']->getId());
        $this->assertSame([['PROMO']], $this->whereValues('b.identifier IN (?)'));
    }

    public function testIdAndIdentifierReferencesUseOneQueryEach(): void
    {
        $sources = $this->reader($this->db([
            [self::block(4, 'four', 'a', 0)],
            [self::block(6, 'six', 'b', 3)],
        ]))->read([new BlockRef('block', 4), new BlockRef('block', 0, 'six'), new BlockRef('block', 4)], 3);
        $this->assertSame(['block#4', 'block@six'], array_keys($sources));
        $this->assertSame([[4]], $this->whereValues('b.block_id IN (?)'));
    }

    public function testDynamicBlocksUseTheStoreContentElseTheDefaultAndCarryTheirSegments(): void
    {
        $sources = $this->reader($this->db([
            [
                ['banner_id' => 3, 'store_id' => 2, 'banner_content' => '<p>store two</p>'],
                ['banner_id' => 7, 'store_id' => 0, 'banner_content' => '<p>default</p>'],
                ['banner_id' => 3, 'store_id' => 0, 'banner_content' => '<p>default of three</p>'],
            ],
            [['banner_id' => 7, 'segment_id' => 4], ['banner_id' => 7, 'segment_id' => 2], ['banner_id' => 7, 'segment_id' => 4]],
        ]))->read([new BlockRef('dynamic_block', 3), new BlockRef('dynamic_block', 7)], 2);
        $this->assertSame(['dynamic_block#3', 'dynamic_block#7'], array_keys($sources));
        $this->assertSame('<p>store two</p>', $sources['dynamic_block#3']->getContent());
        $this->assertFalse($sources['dynamic_block#3']->hasSegments());
        $this->assertSame([2, 4], $sources['dynamic_block#7']->getSegmentIds());
        $this->assertTrue($sources['dynamic_block#7']->hasSegments());
        $this->assertSame([[3, 7]], $this->whereValues('b.banner_id IN (?)'));
        $this->assertSame([1], $this->whereValues('b.is_enabled = ?'));
        $this->assertSame([[0, 2]], $this->whereValues('c.store_id IN (?)'));
        $this->assertSame([[3, 7]], $this->whereValues('banner_id IN (?)'));
        $this->assertSame(['c.store_id DESC'], $this->orders);
    }

    public function testAnEmptyStoreContentShowsNothingAndSkipsTheSegmentQuery(): void
    {
        // the store view's own empty content wins over the default one, exactly like Banner::getStoreContent()
        $sources = $this->reader($this->db([[
            ['banner_id' => 3, 'store_id' => 2, 'banner_content' => '  '],
            ['banner_id' => 3, 'store_id' => 0, 'banner_content' => '<p>default</p>'],
        ]]))->read([new BlockRef('dynamic_block', 3)], 2);
        $this->assertSame([], $sources);
    }

    public function testIdentifierOfReadsTheCurrentVersion(): void
    {
        $reader = $this->reader($this->db([], [['footer'], []]));
        $this->assertSame('footer', $reader->identifierOf(5));
        $this->assertNull($reader->identifierOf(6));
        $this->assertSame([5, 6], $this->whereValues('block_id = ?'));
    }

    public function testTheStagingVersionIsReloadedBeforeEveryReadOfBlocks(): void
    {
        $reader = $this->reader($this->db([[], []], [[]]));
        $this->history->expects($this->exactly(3))->method('_resetState');
        $reader->read([new BlockRef('block', 5)], 2);
        $reader->read([new BlockRef('block', 0, 'x')], 2);
        $reader->identifierOf(5);
    }

    public function testDynamicBlocksAreNotStagedSoNoVersionReload(): void
    {
        $reader = $this->reader($this->db([[]]));
        $this->history->expects($this->never())->method('_resetState');
        $reader->read([new BlockRef('dynamic_block', 5)], 2);
    }

    public function testIdsAreQueriedInChunksAndEachChunkKeepsTheStorePrecedence(): void
    {
        $sources = $this->reader($this->db([
            [self::block(1, 'a', 'a', 0)],
            [self::block(3, 'c', 'c', 2)],
            [self::block(5, 'e', 'e', 0)],
        ]), 2)->read([
            new BlockRef('block', 1), new BlockRef('block', 2), new BlockRef('block', 3),
            new BlockRef('block', 4), new BlockRef('block', 5),
        ], 2);
        $this->assertSame(['block#1', 'block#3', 'block#5'], array_keys($sources));
        $this->assertSame([[1, 2], [3, 4], [5]], $this->whereValues('b.block_id IN (?)'));
    }

    public function testIdentifiersAreChunkedAndDeduplicatedIgnoringCase(): void
    {
        $sources = $this->reader($this->db([
            [self::block(1, 'a', 'a', 0), self::block(2, 'b', 'b', 0)],
            [self::block(3, 'c', 'c', 0)],
        ]), 2)->read([
            new BlockRef('block', 0, 'a'), new BlockRef('block', 0, 'A'), new BlockRef('block', 0, 'b'),
            new BlockRef('block', 0, 'c'),
        ], 2);
        $this->assertSame(['block@a', 'block@b', 'block@c'], array_keys($sources));
        $this->assertSame([['a', 'b'], ['c']], $this->whereValues('b.identifier IN (?)'));
    }

    public function testDynamicBlocksAndSegmentsAreChunked(): void
    {
        $sources = $this->reader($this->db([
            [['banner_id' => 1, 'store_id' => 0, 'banner_content' => 'x'], ['banner_id' => 2, 'store_id' => 0, 'banner_content' => 'y']],
            [['banner_id' => 3, 'store_id' => 0, 'banner_content' => 'z']],
            [['banner_id' => 2, 'segment_id' => 9]],
            [],
        ]), 2)->read([
            new BlockRef('dynamic_block', 1), new BlockRef('dynamic_block', 2), new BlockRef('dynamic_block', 3),
        ], 2);
        $this->assertSame(['dynamic_block#1', 'dynamic_block#2', 'dynamic_block#3'], array_keys($sources));
        $this->assertTrue($sources['dynamic_block#2']->hasSegments());
        $this->assertFalse($sources['dynamic_block#3']->hasSegments());
        $this->assertSame([[1, 2], [3]], $this->whereValues('b.banner_id IN (?)'));
        $this->assertSame([[1, 2], [3]], $this->whereValues('banner_id IN (?)'));
    }

    public function testBlockSourceKey(): void
    {
        $this->assertSame('dynamic_block#3', (new BlockSource('dynamic_block', 3, '', 'x', [1]))->key());
    }
}
