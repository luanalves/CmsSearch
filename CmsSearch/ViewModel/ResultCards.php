<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Search\QueryBuilder;
use Thedevkitchen\CmsSearch\Model\Search\ResultUrl;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;
use Thedevkitchen\CmsSearch\Model\Search\SummaryFormatter;
use Thedevkitchen\CmsSearch\Model\Search\TabResolver;

/**
 * Cards and pagination of the active CMS tab (spec 5.6): type label of the store view, title, summary shortened to
 * results/summary_length, URL and image. Values are plain text; the templates escape every one of them.
 */
class ResultCards implements ArgumentInterface
{
    public const PAGE_WINDOW = 2;
    public const EMPTY_TAB = 'tab';
    public const EMPTY_PAGE = 'page';

    public function __construct(
        private readonly SearchState $state,
        private readonly Config $config,
        private readonly SummaryFormatter $summary,
        private readonly ResultUrl $url
    ) {
    }

    public function isVisible(): bool
    {
        return $this->state->isEnabled() && $this->state->isCmsTabActive() && $this->state->getResult() !== null;
    }

    public function getPanelId(): string
    {
        return SearchTabs::PANEL_ID;
    }

    public function getActiveTabId(): string
    {
        return SearchTabs::tabId($this->state->getActiveTab()->getCode());
    }

    public function getTotal(): int
    {
        return $this->state->getResult()?->getTotal() ?? 0;
    }

    /**
     * @return list<array{title: string, url: string, image: ?string, summary: string, type_label: string}>
     */
    public function getCards(): array
    {
        $result = $this->state->getResult();
        if ($result === null) {
            return [];
        }
        $labels = [];
        foreach ($this->state->getTabs() as $tab) {
            $labels[$tab->getCode()] = $tab->getLabel();
        }
        $length = $this->config->getSummaryLength($this->state->getStoreId());
        $cards = [];
        foreach ($result->getHits() as $card) {
            $typeLabel = $card->getEntityType() === QueryBuilder::ENTITY_CATEGORY
                ? ($labels[TabResolver::CATEGORIES] ?? '')
                : ($labels[(string) $card->getContentTypeCode()] ?? '');
            $cards[] = [
                'title' => $card->getTitle(),
                'url' => $card->getUrl(),
                'image' => $card->getImage(),
                'summary' => $this->summary->format($card->getSummary(), $length),
                'type_label' => $typeLabel,
            ];
        }
        return $cards;
    }

    /** null when there are cards; EMPTY_TAB when the tab (with its filter) has no result; EMPTY_PAGE past the last page */
    public function getEmptyState(): ?string
    {
        if (($this->state->getResult()?->getHits() ?? []) !== []) {
            return null;
        }
        return $this->getTotal() > 0 ? self::EMPTY_PAGE : self::EMPTY_TAB;
    }

    public function hasFilter(): bool
    {
        return $this->state->getSelectedTerm() !== null;
    }

    public function getClearFilterUrl(): string
    {
        return $this->url->build($this->state->getQueryText(), $this->state->getActiveTab()->getCode());
    }

    public function getFirstPageUrl(): string
    {
        return $this->pageUrl(1);
    }

    /**
     * @return array{previous: ?string, next: ?string, pages: list<array{number: int, url: string, current: bool}>}
     */
    public function getPagination(): array
    {
        $size = max(1, $this->state->getPageSize());
        $last = min((int) ceil($this->getTotal() / $size), intdiv(TabResolver::MAX_RESULT_WINDOW, $size));
        if ($last <= 1) {
            return ['previous' => null, 'next' => null, 'pages' => []];
        }
        $current = $this->state->getPage();
        $center = min($current, $last);
        $pages = [];
        for ($number = max(1, $center - self::PAGE_WINDOW); $number <= min($last, $center + self::PAGE_WINDOW); $number++) {
            $pages[] = ['number' => $number, 'url' => $this->pageUrl($number), 'current' => $number === $current];
        }
        return [
            'previous' => $current > 1 ? $this->pageUrl(min($current - 1, $last)) : null,
            'next' => $current < $last ? $this->pageUrl($current + 1) : null,
            'pages' => $pages,
        ];
    }

    private function pageUrl(int $page): string
    {
        return $this->url->build(
            $this->state->getQueryText(),
            $this->state->getActiveTab()->getCode(),
            $this->state->getSelectedTerm()['code'] ?? null,
            $page
        );
    }
}
