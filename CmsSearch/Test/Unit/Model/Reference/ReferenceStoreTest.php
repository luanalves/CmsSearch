<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Reference\ReferenceStore;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class ReferenceStoreTest extends TestCase
{
    use DbStubTrait;

    private const TABLE = 'thedevkitchen_cmssearch_block_reference';

    private static function row(int $hostId, int $blockId): array
    {
        return ['host_id' => $hostId, 'block_kind' => 'block', 'block_id' => $blockId, 'block_identifier' => '',
            'source' => 'content', 'source_id' => 0, 'depth' => 0, 'segment_ids' => null];
    }

    public function testReplaceDeletesAndInsertsTheHostsInOneTransaction(): void
    {
        $store = new ReferenceStore($this->db());
        $calls = [];
        $this->connection->expects($this->once())->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'begin';
            return $this->connection;
        });
        $this->connection->expects($this->once())->method('delete')
            ->with(self::TABLE, ['store_id = ?' => 2, 'host_type = ?' => 'page', 'host_id IN (?)' => [5, 6]])
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'delete';
                return 3;
            });
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->with(
                self::TABLE,
                [['store_id' => 2, 'host_type' => 'page'] + self::row(5, 7)],
                ['depth' => new \Magento\Framework\DB\Sql\Expression('LEAST(`depth`, VALUES(`depth`))')]
            )
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'insert';
                return 1;
            });
        $this->connection->expects($this->once())->method('commit')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'commit';
            return $this->connection;
        });
        $this->connection->expects($this->never())->method('rollBack');
        $store->replace('page', [5, 6], 2, [self::row(5, 7)]);
        $this->assertSame(['begin', 'delete', 'insert', 'commit'], $calls);
    }

    public function testRowsTheCollationConsidersEqualKeepTheFirstAndTheSmallestDepth(): void
    {
        // the unique key compares block_identifier with utf8mb4_general_ci (accent- and case-insensitive): two spellings
        // of one identifier must not raise a duplicate-key error that would roll back the whole batch refresh
        $update = null;
        $store = new ReferenceStore($this->db());
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $rows, array $fields) use (&$update): int {
                $update = $fields;
                return count($rows);
            });
        $this->connection->expects($this->never())->method('insertMultiple');
        $store->replace('page', [5], 2, [self::row(5, 7)]);
        $this->assertSame(['depth'], array_keys($update), 'only the depth is merged; identifier spelling and segments of the first row stay');
        $this->assertSame('LEAST(`depth`, VALUES(`depth`))', (string) $update['depth']);
    }

    public function testAHostWithoutReferencesOnlyLosesItsOldRows(): void
    {
        $store = new ReferenceStore($this->db());
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('delete');
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->once())->method('commit');
        $store->replace('category', [9], 1, []);
        $store->replace('category', [], 1, []); // nothing to do, no transaction
    }

    public function testAFailedInsertRollsBackAndRethrows(): void
    {
        $store = new ReferenceStore($this->db());
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('delete');
        $this->connection->method('insertOnDuplicate')->willThrowException(new \RuntimeException('deadlock'));
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');
        $this->expectExceptionMessage('deadlock');
        $store->replace('page', [5], 2, [self::row(5, 7)]);
    }

    public function testAFailedDeleteRollsBackWithoutInserting(): void
    {
        $store = new ReferenceStore($this->db());
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->method('delete')->willThrowException(new \RuntimeException('lock wait'));
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');
        $this->expectExceptionMessage('lock wait');
        $store->replace('page', [5], 2, [self::row(5, 7)]);
    }

    public function testRowsAreInsertedInChunksInsideTheSameTransaction(): void
    {
        $store = new ReferenceStore($this->db(), 2);
        $calls = [];
        $this->connection->expects($this->once())->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'begin';
            return $this->connection;
        });
        $this->connection->expects($this->once())->method('delete')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'delete';
            return 0;
        });
        $this->connection->expects($this->exactly(3))->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $rows) use (&$calls) {
                $calls[] = 'insert:' . implode(',', array_column($rows, 'block_id'));
                return count($rows);
            });
        $this->connection->expects($this->once())->method('commit')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'commit';
            return $this->connection;
        });
        $store->replace('page', [5], 2, [self::row(5, 1), self::row(5, 2), self::row(5, 3), self::row(5, 4), self::row(5, 5)]);
        $this->assertSame(['begin', 'delete', 'insert:1,2', 'insert:3,4', 'insert:5', 'commit'], $calls);
    }

    public function testAnExactMultipleOfTheChunkSizeRunsNoEmptyInsert(): void
    {
        $store = new ReferenceStore($this->db(), 2);
        $this->connection->expects($this->exactly(2))->method('insertOnDuplicate')
            ->willReturnCallback(static fn (string $table, array $rows): int => count($rows));
        $store->replace('page', [5], 2, [self::row(5, 1), self::row(5, 2), self::row(5, 3), self::row(5, 4)]);
    }

    public function testAFailureInALaterChunkRollsBackTheWholeReplace(): void
    {
        $store = new ReferenceStore($this->db(), 1);
        $this->connection->expects($this->exactly(2))->method('insertOnDuplicate')
            ->willReturnOnConsecutiveCalls(1, $this->throwException(new \RuntimeException('packet too large')));
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');
        $this->expectExceptionMessage('packet too large');
        $store->replace('page', [5], 2, [self::row(5, 1), self::row(5, 2), self::row(5, 3)]);
    }

    public function testAnUnknownHostTypeOrAnInvalidChunkSizeIsRefused(): void
    {
        $store = new ReferenceStore($this->db());
        $this->connection->expects($this->never())->method('beginTransaction');
        try {
            $store->replace('cart', [5], 2, []);
            $this->fail('An unknown host type must be refused.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new ReferenceStore($this->db(), 0);
    }

    public function testHostsForABlockAreReadInKeysetPagesByIdThenByIdentifier(): void
    {
        $store = new ReferenceStore($this->db([
            [['reference_id' => '4', 'store_id' => '2', 'host_type' => 'page', 'host_id' => '5'],
                ['reference_id' => '9', 'store_id' => '3', 'host_type' => 'category', 'host_id' => '37']],
            [['reference_id' => '12', 'store_id' => '2', 'host_type' => 'page', 'host_id' => '6']],
            [['reference_id' => '15', 'store_id' => '2', 'host_type' => 'page', 'host_id' => '7']],
        ]));
        $this->assertSame([
            [['store_id' => 2, 'host_type' => 'page', 'host_id' => 5], ['store_id' => 3, 'host_type' => 'category', 'host_id' => 37]],
            [['store_id' => 2, 'host_type' => 'page', 'host_id' => 6]],
            [['store_id' => 2, 'host_type' => 'page', 'host_id' => 7]],
        ], iterator_to_array($store->hostsForBlock('block', 7, 'footer', 2), false));
        // page size 2: a full first page asks for the next one (after reference_id 9); the identifier pass starts again at 0
        $this->assertSame([0, 9, 0], $this->whereValues('reference_id > ?'));
        $this->assertSame(['block', 'block', 'block'], $this->whereValues('block_kind = ?'));
        $this->assertSame([7, 7], $this->whereValues('block_id = ?'));
        $this->assertSame(['footer'], $this->whereValues('block_identifier = ?'));
        $this->assertSame(['reference_id ASC', 'reference_id ASC', 'reference_id ASC'], $this->orders);
        $this->assertSame([self::TABLE, self::TABLE, self::TABLE], $this->tables);
        $this->assertSame([2, 2, 2], $this->limits, 'every keyset page is bounded by the page size');
    }

    public function testADynamicBlockIsLookedUpByIdOnlyAndInvalidInputRunsNoQuery(): void
    {
        $store = new ReferenceStore($this->db([[]]));
        $this->assertSame([], iterator_to_array($store->hostsForBlock('dynamic_block', 3), false));
        $this->assertSame([3], $this->whereValues('block_id = ?'));
        $this->assertSame([], $this->whereValues('block_identifier = ?'));
        $this->assertSame([], iterator_to_array($store->hostsForBlock('block', 0, 'footer'), false));
        $this->assertSame([], iterator_to_array($store->hostsForBlock('banner', 3), false));
    }

    public function testHostsForAWidgetInstanceAreReadInKeysetPages(): void
    {
        $store = new ReferenceStore($this->db([[['reference_id' => '8', 'store_id' => '1', 'host_type' => 'product', 'host_id' => '7']]]));
        $this->assertSame(
            [[['store_id' => 1, 'host_type' => 'product', 'host_id' => 7]]],
            iterator_to_array($store->hostsForSource('widget', 18), false)
        );
        $this->assertSame(['widget'], $this->whereValues('source = ?'));
        $this->assertSame([18], $this->whereValues('source_id = ?'));
        $this->assertSame([ReferenceStore::HOST_PAGE_SIZE], $this->limits, 'the default page size bounds the query');
        // source id 0 (every content row) or an unknown source would match unrelated hosts: no query.
        $this->assertSame([], iterator_to_array($store->hostsForSource('content', 0), false));
        $this->assertSame([], iterator_to_array($store->hostsForSource('widget', 0), false));
        $this->assertSame([], iterator_to_array($store->hostsForSource('layout', 18), false));
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($store->hostsForSource('widget', 18, 0));
    }

    public function testDirectReferencesAreGroupedByHostInInsertionOrder(): void
    {
        $store = new ReferenceStore($this->db([[
            ['host_id' => '5', 'block_kind' => 'block', 'block_id' => '7', 'block_identifier' => '', 'segment_ids' => null],
            ['host_id' => '6', 'block_kind' => 'dynamic_block', 'block_id' => '3', 'block_identifier' => '', 'segment_ids' => '4'],
            ['host_id' => '5', 'block_kind' => 'block', 'block_id' => '0', 'block_identifier' => 'missing', 'segment_ids' => null],
        ]]));
        $this->assertSame([
            5 => [
                ['block_kind' => 'block', 'block_id' => 7, 'block_identifier' => '', 'segment_ids' => null],
                ['block_kind' => 'block', 'block_id' => 0, 'block_identifier' => 'missing', 'segment_ids' => null],
            ],
            6 => [['block_kind' => 'dynamic_block', 'block_id' => 3, 'block_identifier' => '', 'segment_ids' => '4']],
        ], $store->directRefs('page', [5, 6], 2));
        $this->assertSame([0], $this->whereValues('depth = ?'));
        $this->assertSame([2], $this->whereValues('store_id = ?'));
        $this->assertSame(['page'], $this->whereValues('host_type = ?'));
        $this->assertSame([[5, 6]], $this->whereValues('host_id IN (?)'));
        $this->assertSame(['reference_id ASC'], $this->orders);
        $this->assertSame([], (new ReferenceStore($this->db()))->directRefs('page', [], 2));
    }
}
