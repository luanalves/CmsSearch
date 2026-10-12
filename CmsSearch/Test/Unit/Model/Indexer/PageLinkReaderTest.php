<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Db\Tables;
use Thedevkitchen\CmsSearch\Model\Indexer\PageLinkReader;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ResourceConnectionStubTrait;

class PageLinkReaderTest extends TestCase
{
    use ResourceConnectionStubTrait;

    public function testLinksOfManyPagesAreReadInOneQuery(): void
    {
        $resource = $this->resourceReturning([[
            ['page_id' => '4', 'type_id' => '2', 'term_id' => '9'],
            ['page_id' => '11', 'type_id' => '3', 'term_id' => '10'],
        ]]);
        $this->assertSame(
            [4 => ['type_id' => 2, 'term_id' => 9], 11 => ['type_id' => 3, 'term_id' => 10]],
            (new PageLinkReader($resource))->read([4, 11, 12, 4])
        );
        $this->assertSame([['page_id IN (?)', [4, 11, 12]]], $this->wheres);
        $this->assertSame('thedevkitchen_cmssearch_page_classification', Tables::PAGE_CLASSIFICATION);
    }

    public function testLargeIdSetsAreReadInBoundedChunks(): void
    {
        $resource = $this->resourceReturning([
            [['page_id' => '1', 'type_id' => '2', 'term_id' => '9'], ['page_id' => '2', 'type_id' => '2', 'term_id' => '9']],
            [['page_id' => '3', 'type_id' => '3', 'term_id' => '10']],
        ]);
        $links = (new PageLinkReader($resource, 2))->read([3, 1, 2]);
        $this->assertSame([1, 2, 3], array_keys($links));
        $this->assertSame([['page_id IN (?)', [3, 1]], ['page_id IN (?)', [2]]], $this->wheres);
    }

    public function testNoPagesNoQuery(): void
    {
        $this->assertSame([], (new PageLinkReader($this->resourceReturning()))->read([0, -3]));
    }
}
