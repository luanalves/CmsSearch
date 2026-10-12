<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\ContentTypeReader;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ResourceConnectionStubTrait;

class ContentTypeReaderTest extends TestCase
{
    use ResourceConnectionStubTrait;

    public function testTypesAreReadInOneQuery(): void
    {
        $resource = $this->resourceReturning([[
            ['type_id' => '2', 'code' => 'news', 'is_active' => '1'],
            ['type_id' => '3', 'code' => 'help', 'is_active' => '0'],
        ]]);
        $types = (new ContentTypeReader($resource))->read([3, 2, 2]);
        $this->assertSame([2, 3], array_keys($types));
        $this->assertSame([2, 'news', true], [$types[2]->getTypeId(), $types[2]->getCode(), $types[2]->isActive()]);
        $this->assertSame([3, 'help', false], [$types[3]->getTypeId(), $types[3]->getCode(), $types[3]->isActive()]);
        $this->assertSame([['type_id IN (?)', [2, 3]]], $this->wheres);
    }

    public function testLargeIdSetsAreReadInBoundedChunks(): void
    {
        $resource = $this->resourceReturning([
            [['type_id' => '1', 'code' => 'a', 'is_active' => '1'], ['type_id' => '2', 'code' => 'b', 'is_active' => '1']],
            [['type_id' => '3', 'code' => 'c', 'is_active' => '0']],
        ]);
        $types = (new ContentTypeReader($resource, 2))->read([3, 1, 2]);
        $this->assertSame([1, 2, 3], array_keys($types));
        $this->assertSame([['type_id IN (?)', [1, 2]], ['type_id IN (?)', [3]]], $this->wheres);
    }

    public function testNoTypesNoQuery(): void
    {
        $this->assertSame([], (new ContentTypeReader($this->resourceReturning()))->read([]));
    }
}
