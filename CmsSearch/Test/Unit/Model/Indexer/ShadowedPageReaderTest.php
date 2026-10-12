<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;

class ShadowedPageReaderTest extends TestCase
{
    /** @var list<string> every call made on the select, in order */
    private array $calls = [];
    private int $queries = 0;

    /**
     * @param list<array<string, mixed>> $rows result of the single query
     * @param list<string> $column result of the single siblingsOf() query
     */
    private function reader(array $rows = [], string $linkField = 'row_id', array $column = []): ShadowedPageReader
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(function ($name, $cols = '*') use ($select) {
            $this->calls[] = 'from ' . json_encode($name) . ' ' . json_encode($cols);
            return $select;
        });
        $select->method('join')->willReturnCallback(function ($name, $cond, $cols = '*') use ($select) {
            $this->calls[] = 'join ' . json_encode($name) . ' ON ' . $cond . ' ' . json_encode($cols);
            return $select;
        });
        $select->method('columns')->willReturnCallback(function ($cols = '*') use ($select) {
            $this->calls[] = 'columns ' . json_encode($cols);
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->calls[] = 'where ' . $cond . ' ' . json_encode($value);
            return $select;
        });
        $select->method('distinct')->willReturnCallback(function () use ($select) {
            $this->calls[] = 'distinct';
            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnCallback(function () use ($rows): array {
            $this->queries++;
            return $rows;
        });
        $connection->method('fetchCol')->willReturnCallback(function () use ($column): array {
            $this->queries++;
            return $column;
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $metadata->method('getIdentifierField')->willReturn('page_id');
        $metadata->method('getEntityTable')->willReturn('cms_page');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->with(PageInterface::class)->willReturn($metadata);
        return new ShadowedPageReader($resource, $pool);
    }

    /** @param int[] $storeIds */
    private static function page(int $id, array $storeIds, bool $active = true, string $identifier = 'home'): PageSnapshot
    {
        return new PageSnapshot($id, $identifier, 'T', '', '', '', $active, $storeIds, null);
    }

    public function testShadowedStoreViewsOfAllStoreViewsPagesAreReadInOneQuery(): void
    {
        $reader = $this->reader([
            ['page_id' => '2', 'store_id' => '3'],
            ['page_id' => '2', 'store_id' => '2'],
            ['page_id' => '8', 'store_id' => '5'],
        ]);
        $pages = [2 => self::page(2, [0]), 8 => self::page(8, [0], true, 'about'), 9 => self::page(9, [0])];
        $this->assertSame([2 => [2, 3], 8 => [5]], $reader->read($pages, [5, 2, 3, 2]));
        $this->assertSame(1, $this->queries);
        $this->assertSame([
            'from {"p":"cms_page"} []',
            'join {"q":"cms_page"} ON q.identifier = p.identifier AND q.page_id <> p.page_id []',
            'join {"qs":"cms_page_store"} ON qs.row_id = q.row_id []',
            'columns {"page_id":"p.page_id","store_id":"qs.store_id"}',
            'where p.page_id IN (?) [2,8,9]',
            'where qs.store_id IN (?) [2,3,5]',
            'where q.is_active = ? 1',
            'distinct',
        ], $this->calls);
    }

    public function testTheLinkFieldOfTheEditionIsUsed(): void
    {
        $this->reader([], 'page_id')->read([2 => self::page(2, [0])], [2]);
        $this->assertContains('join {"qs":"cms_page_store"} ON qs.page_id = q.page_id []', $this->calls);
    }

    public function testOnlyActivePagesOfAllStoreViewsCanBeShadowed(): void
    {
        $reader = $this->reader([['page_id' => '2', 'store_id' => '2']]);
        $pages = [
            2 => self::page(2, [0]),
            3 => self::page(3, [2]),       // store-specific: wins over store 0, never shadowed
            4 => self::page(4, [0], false), // disabled: hidden anyway
        ];
        $this->assertSame([2 => [2]], $reader->read($pages, [2]));
        $this->assertContains('where p.page_id IN (?) [2]', $this->calls);
    }

    public function testRowsOfStoreViewsOrPagesThatWereNotAskedForAreIgnored(): void
    {
        $reader = $this->reader([['page_id' => '2', 'store_id' => '7'], ['page_id' => '99', 'store_id' => '2'], ['page_id' => '2', 'store_id' => '2']]);
        $this->assertSame([2 => [2]], $reader->read([2 => self::page(2, [0])], [2]));
    }

    public function testNoCandidateOrNoStoreViewMeansNoQuery(): void
    {
        $reader = $this->reader([['page_id' => '2', 'store_id' => '2']]);
        $this->assertSame([], $reader->read([], [2]));
        $this->assertSame([], $reader->read([3 => self::page(3, [2]), 4 => self::page(4, [0], false)], [2]));
        $this->assertSame([], $reader->read([2 => self::page(2, [0])], []));
        $this->assertSame([], $reader->read([2 => self::page(2, [0])], [0, -1]));
        $this->assertSame(0, $this->queries);
    }

    public function testTheSiblingsOfAllStoreViewsThatChangedPagesMayShadowAreReadInOneQuery(): void
    {
        $reader = $this->reader([], 'row_id', ['2', '9', '11', '2']);
        $this->assertSame([2, 9], $reader->siblingsOf([11, '13', 11, 0, -4]));
        $this->assertSame(1, $this->queries);
        $this->assertSame([
            'from {"p":"cms_page"} []',
            'join {"q":"cms_page"} ON q.identifier = p.identifier AND q.page_id <> p.page_id []',
            'join {"qs":"cms_page_store"} ON qs.row_id = q.row_id []',
            'columns {"page_id":"q.page_id"}',
            'where p.page_id IN (?) [11,13]',
            'where qs.store_id = ? 0',
            'distinct',
        ], $this->calls);
    }

    public function testSiblingsUseTheLinkFieldOfTheEdition(): void
    {
        $this->reader([], 'page_id')->siblingsOf([11]);
        $this->assertContains('join {"qs":"cms_page_store"} ON qs.page_id = q.page_id []', $this->calls);
    }

    public function testNoPageMeansNoSiblingQuery(): void
    {
        $reader = $this->reader([], 'row_id', ['2']);
        $this->assertSame([], $reader->siblingsOf([]));
        $this->assertSame([], $reader->siblingsOf([0, -1]));
        $this->assertSame(0, $this->queries);
    }
}
