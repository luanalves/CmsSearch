<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Classification;

use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;

/**
 * Read model of the classification tree of a website (admin tree, dropdowns, ancestor lists for the index).
 */
class TreeBuilder
{
    public function __construct(private readonly TermResource $resource)
    {
    }

    /**
     * @return list<array<string, mixed>> nested nodes (see assemble())
     */
    public function build(int $websiteId, int $storeId = 0, bool $activeOnly = false): array
    {
        $rows = $this->resource->fetchTreeRows($websiteId);
        $labels = $this->resource->fetchLabels(array_column($rows, 'term_id'));
        return $this->assemble($rows, $labels, $storeId, $activeOnly);
    }

    /**
     * @param list<array{term_id:int, parent_id:?int, code:string, path:string, level:int, sort_order:int, is_active:bool}> $rows
     * @param array<int, array<int, string>> $labels term id => store id => label
     * @return list<array{term_id:int, parent_id:?int, code:string, label:string, path:string, level:int,
     *     sort_order:int, is_active:bool, children:list<array<string, mixed>>}>
     */
    public function assemble(array $rows, array $labels, int $storeId, bool $activeOnly): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[$row['parent_id'] ?? 0][] = $row;
        }
        foreach ($byParent as &$siblings) {
            usort(
                $siblings,
                static fn (array $a, array $b): int => [$a['sort_order'], $a['term_id']] <=> [$b['sort_order'], $b['term_id']]
            );
        }
        unset($siblings);

        $build = function (int $parentKey) use (&$build, $byParent, $labels, $storeId, $activeOnly): array {
            $nodes = [];
            foreach ($byParent[$parentKey] ?? [] as $row) {
                if ($activeOnly && !$row['is_active']) {
                    continue; // its descendants are skipped with it
                }
                $nodes[] = $row + [
                    'label' => $this->label($labels[$row['term_id']] ?? [], $storeId, $row['code']),
                    'children' => $build($row['term_id']),
                ];
            }
            return $nodes;
        };
        return $build(0);
    }

    /**
     * Depth-first list; every row carries its "trail" (labels from the root joined by " / ").
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public function flatten(array $tree, string $parentTrail = ''): array
    {
        $rows = [];
        foreach ($tree as $node) {
            $children = $node['children'];
            unset($node['children']);
            $node['trail'] = $parentTrail === '' ? $node['label'] : $parentTrail . ' / ' . $node['label'];
            $rows[] = $node;
            foreach ($this->flatten($children, $node['trail']) as $child) {
                $rows[] = $child;
            }
        }
        return $rows;
    }

    /**
     * @return int[] ids from the root to the term, the term included ("1/4/9" => [1, 4, 9])
     */
    public function ancestorIds(string $path): array
    {
        if ($path === '') {
            return [];
        }
        return array_map('intval', explode('/', $path));
    }

    /**
     * @param array<int, string> $labels
     */
    private function label(array $labels, int $storeId, string $code): string
    {
        $label = trim((string) ($labels[$storeId] ?? ''));
        if ($label === '') {
            $label = trim((string) ($labels[0] ?? ''));
        }
        return $label !== '' ? $label : $code;
    }
}
