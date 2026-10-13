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
}
