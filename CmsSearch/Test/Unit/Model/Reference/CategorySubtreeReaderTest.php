<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Reference\CategorySubtreeReader;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ResourceConnectionStubTrait;

class CategorySubtreeReaderTest extends TestCase
{
    use ResourceConnectionStubTrait;

    public function testEveryDescendantByPathInOneQueryWhateverItsActiveFlag(): void
    {
        $reader = new CategorySubtreeReader($this->resourceReturning([], [['6', '7', '7', '12']]));
        $this->assertSame([6, 7, 12], $reader->descendantIds('1/2/5'));
        // path only: Category::getAllChildren() would join is_active and miss children that are inactive by default
        // but active in a store view
        $this->assertSame([['path LIKE ?', '1/2/5/%']], $this->wheres);
    }

    public function testAMalformedPathReadsNothing(): void
    {
        $reader = new CategorySubtreeReader($this->resourceReturning());
        foreach (['', '1/2/', '1/%', "1/2/5' OR 1=1", '/1/2'] as $path) {
            $this->assertSame([], $reader->descendantIds($path), $path);
        }
    }

    public function testWithDescendantsReadsThePathsOnceAndWalksEachTopPathOnly(): void
    {
        // paths of 5, 6 and 9; 1/2/5/6 is under 1/2/5, so only two walks follow
        $reader = new CategorySubtreeReader($this->resourceReturning([], [['1/2/5', '1/2/5/6', '1/3/9'], [6, 7], [10]]));
        $this->assertSame([5, 6, 7, 9, 10], $reader->withDescendants([9, 5, 6, 5, 0]));
        $this->assertSame([['entity_id IN (?)', [9, 5, 6]], ['path LIKE ?', '1/2/5/%'], ['path LIKE ?', '1/3/9/%']], $this->wheres);
    }

    public function testWithDescendantsOfNothingReadsNothing(): void
    {
        $reader = new CategorySubtreeReader($this->resourceReturning());
        $this->assertSame([], $reader->withDescendants([0, -3]));
    }
}
