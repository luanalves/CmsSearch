<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Classification;

use Magento\Framework\Exception\InputException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Classification\PathManager;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;

class PathManagerTest extends TestCase
{
    private TermResource&MockObject $resource;
    /** @var list<array{0: int, 1: string, 2: int}> */
    private array $writes = [];

    protected function setUp(): void
    {
        $this->resource = $this->createMock(TermResource::class);
        $this->resource->method('updatePathLevel')->willReturnCallback(
            function (int $id, string $path, int $level): void {
                $this->writes[] = [$id, $path, $level];
            }
        );
    }

    private function state(int $id, string $path, int $level): array
    {
        return ['term_id' => $id, 'website_id' => 2, 'parent_id' => null, 'code' => 'c' . $id,
            'path' => $path, 'level' => $level, 'is_active' => true];
    }

    public function testRebaseRewritesThePrefixOfEveryRowAndShiftsTheLevel(): void
    {
        $rows = [
            ['term_id' => 4, 'path' => '1/4', 'level' => 1],
            ['term_id' => 9, 'path' => '1/4/9', 'level' => 2],
            ['term_id' => 12, 'path' => '1/4/9/12', 'level' => 3],
        ];
        $this->assertSame(
            [
                4 => ['path' => '7/8/4', 'level' => 2],
                9 => ['path' => '7/8/4/9', 'level' => 3],
                12 => ['path' => '7/8/4/9/12', 'level' => 4],
            ],
            (new PathManager($this->resource))->rebase($rows, '1/4', '7/8/4', 1)
        );
    }

    public function testRebaseRefusesARowThatOnlySharesTheDigitsOfThePrefix(): void
    {
        // "1/40" is a sibling of "1/4", not a descendant: the prefix must end at a "/".
        $this->expectException(\LogicException::class);
        (new PathManager($this->resource))->rebase(
            [['term_id' => 40, 'path' => '1/40', 'level' => 1]],
            '1/4',
            '4',
            -1
        );
    }

    public function testANewRootTermGetsItsOwnIdAsPath(): void
    {
        $this->resource->expects($this->never())->method('fetchSubtree');
        $placed = (new PathManager($this->resource))->relocate(15, null, '');
        $this->assertSame(['path' => '15', 'level' => 0], $placed);
        $this->assertSame([[15, '15', 0]], $this->writes);
    }

    public function testANewChildIsPlacedUnderItsParent(): void
    {
        $this->resource->method('getStoredState')->with(4)->willReturn($this->state(4, '1/4', 1));
        $placed = (new PathManager($this->resource))->relocate(15, 4, '');
        $this->assertSame(['path' => '1/4/15', 'level' => 2], $placed);
        $this->assertSame([[15, '1/4/15', 2]], $this->writes);
    }

    public function testMovingToTheRootRewritesTheWholeSubtree(): void
    {
        $this->resource->expects($this->once())->method('fetchSubtree')->with('1/4')->willReturn([
            ['term_id' => 4, 'path' => '1/4', 'level' => 1],
            ['term_id' => 9, 'path' => '1/4/9', 'level' => 2],
            ['term_id' => 12, 'path' => '1/4/9/12', 'level' => 3],
        ]);
        $placed = (new PathManager($this->resource))->relocate(4, null, '1/4');
        $this->assertSame(['path' => '4', 'level' => 0], $placed);
        $this->assertSame([[4, '4', 0], [9, '4/9', 1], [12, '4/9/12', 2]], $this->writes);
    }

    public function testMovingUnderAnotherParentUsesThatParentsPathAndLevel(): void
    {
        $this->resource->method('getStoredState')->with(7)->willReturn($this->state(7, '3/7', 1));
        $this->resource->method('fetchSubtree')->willReturn([
            ['term_id' => 4, 'path' => '1/4', 'level' => 1],
            ['term_id' => 9, 'path' => '1/4/9', 'level' => 2],
        ]);
        (new PathManager($this->resource))->relocate(4, 7, '1/4');
        $this->assertSame([[4, '3/7/4', 2], [9, '3/7/4/9', 3]], $this->writes);
    }

    public function testAMissingParentIsRejected(): void
    {
        $this->resource->method('getStoredState')->willReturn(null);
        $this->expectException(InputException::class);
        try {
            (new PathManager($this->resource))->relocate(15, 99, '');
        } finally {
            $this->assertSame([], $this->writes);
        }
    }

    public function testATreeDeeperThanTheLimitIsRejectedBeforeAnyWrite(): void
    {
        // Parent at level 7, moved term at level 1 with a child at level 3 => the child would land on level 10.
        $this->resource->method('getStoredState')->with(70)->willReturn($this->state(70, '1/2/3/4/5/6/7/70', 7));
        $this->resource->method('fetchSubtree')->willReturn([
            ['term_id' => 8, 'path' => '9/8', 'level' => 1],
            ['term_id' => 11, 'path' => '9/8/10/11', 'level' => 3],
        ]);
        $this->expectException(InputException::class);
        try {
            (new PathManager($this->resource))->relocate(8, 70, '9/8');
        } finally {
            $this->assertSame([], $this->writes);
        }
    }

    public function testTheDeepestAllowedLevelIsAccepted(): void
    {
        $this->resource->method('getStoredState')->with(8)->willReturn($this->state(8, '1/2/3/4/5/6/7/8', PathManager::MAX_LEVEL - 1));
        $placed = (new PathManager($this->resource))->relocate(15, 8, '');
        $this->assertSame(PathManager::MAX_LEVEL, $placed['level']);
    }

    public function testAPathLongerThanTheColumnIsRejected(): void
    {
        $longParentPath = implode('/', array_fill(0, 3, str_repeat('9', 84))); // 254 chars
        $this->resource->method('getStoredState')->willReturn($this->state(5, $longParentPath, 2));
        $this->expectException(InputException::class);
        (new PathManager($this->resource))->relocate(15, 5, '');
    }
}
