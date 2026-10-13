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

    public function testHostsForABlockMatchItsIdOrItsIdentifier(): void
    {
        $store = new ReferenceStore($this->db([[
            ['store_id' => '2', 'host_type' => 'page', 'host_id' => '5'],
            ['store_id' => '3', 'host_type' => 'category', 'host_id' => '37'],
        ]]));
        $this->assertSame(
            [['store_id' => 2, 'host_type' => 'page', 'host_id' => 5], ['store_id' => 3, 'host_type' => 'category', 'host_id' => 37]],
            $store->hostsForBlock('block', 7, 'footer')
        );
        $this->assertSame(['block'], $this->whereValues('block_kind = ?'));
        $this->assertSame([null], $this->whereValues("block_id = '7' OR block_identifier = 'footer'"));
        $this->assertSame([self::TABLE], $this->tables);
        $this->assertSame(['store_id ASC,host_type ASC,host_id ASC'], $this->orders);
    }

    public function testHostsForADynamicBlockUseTheIdOnlyAndAnInvalidIdRunsNoQuery(): void
    {
        $store = new ReferenceStore($this->db([[]]));
        $this->assertSame([], $store->hostsForBlock('dynamic_block', 3));
        $this->assertSame([null], $this->whereValues("block_id = '3'"));
        $this->assertSame([], $store->hostsForBlock('block', 0, 'footer'));
        $this->assertSame([], $store->hostsForBlock('banner', 3));
    }

    public function testHostsForAWidgetInstance(): void
    {
        $store = new ReferenceStore($this->db([[['store_id' => '1', 'host_type' => 'product', 'host_id' => '7']]]));
        $this->assertSame([['store_id' => 1, 'host_type' => 'product', 'host_id' => 7]], $store->hostsForSource('widget', 18));
        $this->assertSame(['widget'], $this->whereValues('source = ?'));
        $this->assertSame([18], $this->whereValues('source_id = ?'));
        // source id 0 (every content row) or an unknown source would match unrelated hosts: no query.
        $this->assertSame([], $store->hostsForSource('content', 0));
        $this->assertSame([], $store->hostsForSource('widget', 0));
        $this->assertSame([], $store->hostsForSource('layout', 18));
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
