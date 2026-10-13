<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\ViewModel;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\ResultUrl;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;

/**
 * Hierarchical classification filter of a content tab (D4: never on Products or Categories). A term counts the
 * documents of its whole branch (classification_ancestor_ids); terms without result in this tab are hidden; the
 * selected term can be cleared.
 */
class ClassificationFilter implements ArgumentInterface, ResetAfterRequestInterface
{
    /** @var list<array<string, mixed>>|null */
    private ?array $nodes = null;

    public function __construct(
        private readonly SearchState $state,
        private readonly ResultUrl $url
    ) {
    }

    public function _resetState(): void
    {
        $this->nodes = null;
    }

    public function isVisible(): bool
    {
        return $this->state->isEnabled()
            && $this->state->getActiveTab()->getKind() === Tab::KIND_CONTENT
            && $this->state->getResult() !== null
            && ($this->getNodes() !== [] || $this->state->getSelectedTerm() !== null);
    }

    /**
     * @return list<array{code: string, label: string, count: int, url: string, selected: bool, children: list<array<string, mixed>>}>
     */
    public function getNodes(): array
    {
        return $this->nodes ??= $this->build(
            $this->state->getTermTree(),
            $this->state->getResult()?->getTermCounts() ?? [],
            $this->state->getSelectedTerm()['term_id'] ?? null
        );
    }

    public function getClearUrl(): ?string
    {
        if ($this->state->getSelectedTerm() === null) {
            return null;
        }
        return $this->url->build($this->state->getQueryText(), $this->state->getActiveTab()->getCode());
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @param array<int, int> $counts
     * @return list<array<string, mixed>>
     */
    private function build(array $tree, array $counts, ?int $selected): array
    {
        $nodes = [];
        foreach ($tree as $term) {
            $count = $counts[(int) $term['term_id']] ?? 0;
            if ($count < 1) {
                continue;
            }
            $nodes[] = [
                'code' => (string) $term['code'],
                'label' => (string) $term['label'],
                'count' => $count,
                'url' => $this->url->build($this->state->getQueryText(), $this->state->getActiveTab()->getCode(), (string) $term['code']),
                'selected' => (int) $term['term_id'] === $selected,
                'children' => $this->build($term['children'] ?? [], $counts, $selected),
            ];
        }
        return $nodes;
    }
}
