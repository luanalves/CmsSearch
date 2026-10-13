<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\ProductBlockTextWriter;
use Thedevkitchen\CmsSearch\Model\Reference\BlockTextResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostEnrichment;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class ProductBlockTextWriterTest extends TestCase
{
    use DbStubTrait;

    private const TABLE = 'thedevkitchen_cmssearch_product_block_text';

    /** @var list<array{0: string, 1: list<int>, 2: int}> forHosts() calls: host type, ids, store view */
    private array $resolverCalls = [];

    /**
     * @param array<int, string> $texts block text per product (missing = the resolver returns nothing for it)
     * @param array<int, string> $hashes stored text_hash per product
     */
    private function writer(array $texts, array $hashes): ProductBlockTextWriter
    {
        $blockText = $this->createMock(BlockTextResolver::class);
        $blockText->method('forHosts')->willReturnCallback(function (string $type, array $ids, int $storeId) use ($texts): array {
            $this->resolverCalls[] = [$type, $ids, $storeId];
            $out = [];
            foreach ($ids as $id) {
                if (array_key_exists($id, $texts)) {
                    $out[$id] = new HostEnrichment($texts[$id], []);
                }
            }
            return $out;
        });
        return new ProductBlockTextWriter($this->db([], [], [$hashes]), $blockText);
    }

    public function testOnlyChangedTextIsWrittenAndEmptyTextIsDeleted(): void
    {
        $writer = $this->writer(
            [1 => 'New text', 2 => 'Same text', 3 => '', 5 => 'Fresh text'],
            [1 => sha1('Old text'), 2 => sha1('Same text'), 3 => sha1('Gone')]
        );
        $this->connection->expects($this->once())->method('insertOnDuplicate')->with(
            self::TABLE,
            [
                ['product_id' => 1, 'store_id' => 2, 'text' => 'New text', 'text_hash' => sha1('New text')],
                ['product_id' => 5, 'store_id' => 2, 'text' => 'Fresh text', 'text_hash' => sha1('Fresh text')],
            ],
            ['text', 'text_hash']
        );
        $this->connection->expects($this->once())->method('delete')
            ->with(self::TABLE, ['store_id = ?' => 2, 'product_id IN (?)' => [3]]);
        $this->assertSame(['written' => 2, 'deleted' => 1, 'unchanged' => 1], $writer->refresh([1, 2, 3, 4, 5], 2));
        $this->assertSame([['product', [1, 2, 3, 4, 5], 2]], $this->resolverCalls, 'product hosts of THIS store view');
        $this->assertSame([2], $this->whereValues('store_id = ?'));
        $this->assertSame([[1, 2, 3, 4, 5]], $this->whereValues('product_id IN (?)'));
        $this->assertSame([self::TABLE], $this->tables);
    }

    /**
     * A product the store view does not show (another website, deleted) gets no enrichment from the resolver: its
     * stale row is deleted, and a product without a row writes nothing.
     */
    public function testAProductWithoutEnrichmentLosesItsRow(): void
    {
        $writer = $this->writer([], [8 => sha1('Old text')]);
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->once())->method('delete')
            ->with(self::TABLE, ['store_id = ?' => 3, 'product_id IN (?)' => [8]]);
        $this->assertSame(['written' => 0, 'deleted' => 1, 'unchanged' => 0], $writer->refresh([8, 9], 3));
    }

    public function testNothingChangedWritesNothingSoNoChangelogRowIsCreated(): void
    {
        $writer = $this->writer([1 => 'Same text', 4 => ''], [1 => sha1('Same text')]);
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->never())->method('delete');
        $this->assertSame(['written' => 0, 'deleted' => 0, 'unchanged' => 1], $writer->refresh([1, 4], 2));
    }

    public function testSurroundingWhitespaceIsNotAChange(): void
    {
        $writer = $this->writer([1 => "  Same text \n"], [1 => sha1('Same text')]);
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->assertSame(['written' => 0, 'deleted' => 0, 'unchanged' => 1], $writer->refresh([1], 2));
    }

    public function testTheTextIsCapped(): void
    {
        $long = str_repeat('é', ProductBlockTextWriter::MAX_TEXT_LENGTH + 10);
        $writer = $this->writer([1 => $long], []);
        $this->connection->expects($this->once())->method('insertOnDuplicate')->willReturnCallback(function (string $table, array $rows): int {
            $this->assertSame(ProductBlockTextWriter::MAX_TEXT_LENGTH, mb_strlen($rows[0]['text']));
            $this->assertSame(sha1($rows[0]['text']), $rows[0]['text_hash']);
            return 1;
        });
        $writer->refresh([1], 2);
    }

    public function testNoProductNoQuery(): void
    {
        $blockText = $this->createMock(BlockTextResolver::class);
        $blockText->expects($this->never())->method('forHosts');
        $writer = new ProductBlockTextWriter($this->db(), $blockText);
        $this->assertSame(['written' => 0, 'deleted' => 0, 'unchanged' => 0], $writer->refresh([0, -3, 'x'], 2));
    }

    public function testNoStoreViewNoQuery(): void
    {
        $blockText = $this->createMock(BlockTextResolver::class);
        $blockText->expects($this->never())->method('forHosts');
        $writer = new ProductBlockTextWriter($this->db(), $blockText);
        $this->assertSame(['written' => 0, 'deleted' => 0, 'unchanged' => 0], $writer->refresh([1], 0));
    }
}
