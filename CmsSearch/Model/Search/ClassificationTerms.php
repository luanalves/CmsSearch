<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;

/**
 * The classification tree a store view may filter by (D6: its website's tree; only effectively active terms, an
 * inactive ancestor hides its branch), with store view labels. Built once per website + store view and request
 * (two queries: rows and labels).
 */
class ClassificationTerms implements ResetAfterRequestInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $trees = [];

    public function __construct(private readonly TreeBuilder $treeBuilder)
    {
    }

    public function _resetState(): void
    {
        $this->trees = [];
    }

    /**
     * @return list<array{term_id: int, code: string, label: string, children: list<array<string, mixed>>}>
     */
    public function tree(int $websiteId, int $storeId): array
    {
        $key = $websiteId . '|' . $storeId;
        return $this->trees[$key] ??= $this->strip($this->treeBuilder->build($websiteId, $storeId, true));
    }

    /**
     * @return array{term_id: int, code: string, label: string}|null
     */
    public function findByCode(int $websiteId, int $storeId, string $code): ?array
    {
        $stack = $this->tree($websiteId, $storeId);
        while ($stack !== []) {
            $node = array_shift($stack);
            if ($node['code'] === $code) {
                return ['term_id' => $node['term_id'], 'code' => $node['code'], 'label' => $node['label']];
            }
            array_push($stack, ...$node['children']);
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array{term_id: int, code: string, label: string, children: list<array<string, mixed>>}>
     */
    private function strip(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $out[] = [
                'term_id' => (int) $node['term_id'],
                'code' => (string) $node['code'],
                'label' => (string) $node['label'],
                'children' => $this->strip(is_array($node['children'] ?? null) ? $node['children'] : []),
            ];
        }
        return $out;
    }
}
