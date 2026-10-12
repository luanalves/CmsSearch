<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Classification;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;

class TreeBuilderTest extends TestCase
{
    private function row(int $id, ?int $parent, string $path, int $sort = 0, bool $active = true): array
    {
        return ['term_id' => $id, 'parent_id' => $parent, 'code' => 'c' . $id, 'path' => $path,
            'level' => substr_count($path, '/'), 'sort_order' => $sort, 'is_active' => $active];
    }

    private function builder(): TreeBuilder
    {
        return new TreeBuilder($this->createMock(TermResource::class));
    }

    /** @return list<int> */
    private function ids(array $nodes): array
    {
        return array_map(static fn (array $node): int => $node['term_id'], $nodes);
    }

    public function testNestsChildrenAndOrdersSiblingsBySortOrderThenId(): void
    {
        $rows = [
            $this->row(1, null, '1', 5),
            $this->row(2, null, '2', 1),
            $this->row(3, 1, '1/3', 0),
            $this->row(5, 1, '1/5', 0),
            $this->row(4, 1, '1/4', 0),
            $this->row(6, 3, '1/3/6', 0),
        ];
        $tree = $this->builder()->assemble($rows, [], 0, false);
        $this->assertSame([2, 1], $this->ids($tree));
        $this->assertSame([3, 4, 5], $this->ids($tree[1]['children']));
        $this->assertSame([6], $this->ids($tree[1]['children'][0]['children']));
    }

    public function testActiveOnlyDropsAnInactiveTermWithItsWholeSubtree(): void
    {
        $rows = [
            $this->row(1, null, '1'),
            $this->row(2, 1, '1/2', 0, false),
            $this->row(3, 2, '1/2/3'),
            $this->row(4, 1, '1/4'),
        ];
        $tree = $this->builder()->assemble($rows, [], 0, true);
        $this->assertSame([1], $this->ids($tree));
        $this->assertSame([4], $this->ids($tree[0]['children']));
    }

    public function testWithoutActiveOnlyInactiveTermsAreKept(): void
    {
        $rows = [$this->row(1, null, '1'), $this->row(2, 1, '1/2', 0, false), $this->row(3, 2, '1/2/3')];
        $this->assertCount(3, $this->builder()->flatten($this->builder()->assemble($rows, [], 0, false)));
    }

    public function testLabelFallsBackFromStoreViewToDefaultToCode(): void
    {
        $rows = [$this->row(1, null, '1'), $this->row(2, null, '2'), $this->row(3, null, '3')];
        $labels = [1 => [0 => 'Agro', 2 => 'Agronegocio'], 2 => [0 => 'News']];
        $tree = $this->builder()->assemble($rows, $labels, 2, false);
        $this->assertSame(['Agronegocio', 'News', 'c3'], array_column($tree, 'label'));
    }

    public function testFlattenIsDepthFirstWithTheTrailOfLabels(): void
    {
        $rows = [$this->row(1, null, '1'), $this->row(2, 1, '1/2'), $this->row(3, 2, '1/2/3'), $this->row(4, null, '4')];
        $labels = [1 => [0 => 'Agro'], 2 => [0 => 'Milho'], 3 => [0 => 'Sementes'], 4 => [0 => 'TV']];
        $flat = $this->builder()->flatten($this->builder()->assemble($rows, $labels, 0, false));
        $this->assertSame([1, 2, 3, 4], array_column($flat, 'term_id'));
        $this->assertSame(['Agro', 'Agro / Milho', 'Agro / Milho / Sementes', 'TV'], array_column($flat, 'trail'));
        $this->assertArrayNotHasKey('children', $flat[0]);
    }

    public function testAncestorIdsIncludeTheTermItself(): void
    {
        $this->assertSame([1, 4, 9], $this->builder()->ancestorIds('1/4/9'));
        $this->assertSame([], $this->builder()->ancestorIds(''));
    }

    public function testBuildReadsTheRowsOfTheWebsiteAndTheirLabels(): void
    {
        $resource = $this->createMock(TermResource::class);
        $resource->expects($this->once())->method('fetchTreeRows')->with(3)->willReturn([$this->row(7, null, '7')]);
        $resource->expects($this->once())->method('fetchLabels')->with([7])->willReturn([7 => [0 => 'Fitness']]);
        $tree = (new TreeBuilder($resource))->build(3);
        $this->assertSame('Fitness', $tree[0]['label']);
    }
}
