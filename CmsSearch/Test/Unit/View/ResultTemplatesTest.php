<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\View;

use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\ZendEscaper;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\ViewModel\ClassificationFilter;
use Thedevkitchen\CmsSearch\ViewModel\ResultCards;

/**
 * Renders the real cards, pagination and filter templates with stubbed view models and the real Escaper: attribute
 * contract (relied on by the curl and Cypress suites), escaping of every dynamic value, empty and hidden states.
 */
class ResultTemplatesTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../view/frontend/templates/result/';
    private const HOSTILE = '"><script>alert(1)</script><img src=x onerror=alert(2)>';

    private function render(string $template, object $viewModel, string $children = ''): string
    {
        $block = $this->createMock(Template::class);
        $block->method('getData')->with('view_model')->willReturn($viewModel);
        $block->method('getChildHtml')->willReturn($children);
        $escaper = new Escaper();
        (new \ReflectionProperty(Escaper::class, 'translateInline'))->setValue($escaper, $this->createMock(InlineInterface::class));
        (new \ReflectionProperty(Escaper::class, 'escaper'))->setValue($escaper, new ZendEscaper());
        ob_start();
        try {
            (function () use ($template, $block, $escaper): void {
                include self::TEMPLATES . $template;
            })();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** @param list<array<string, mixed>> $cards */
    private function cardsModel(array $cards, ?string $empty = null, bool $visible = true, bool $filter = false, int $total = 7): ResultCards
    {
        $model = $this->createMock(ResultCards::class);
        $model->method('isVisible')->willReturn($visible);
        $model->method('getPanelId')->willReturn('cmssearch-panel');
        $model->method('getActiveTabId')->willReturn('cmssearch-tab-news');
        $model->method('getTotal')->willReturn($total);
        $model->method('getCards')->willReturn($cards);
        $model->method('getEmptyState')->willReturn($empty);
        $model->method('hasFilter')->willReturn($filter);
        $model->method('getClearFilterUrl')->willReturn('https://s.test/r/?q=a&tab=news');
        $model->method('getFirstPageUrl')->willReturn('https://s.test/r/?q=a&tab=news');
        return $model;
    }

    /** @return array<string, mixed> */
    private function card(string $title = 'Alpha', string $url = 'https://w.test/alpha', ?string $image = 'https://w.test/a.jpg', string $summary = 'Sum', string $type = 'Noticias'): array
    {
        return ['title' => $title, 'url' => $url, 'image' => $image, 'summary' => $summary, 'type_label' => $type];
    }

    public function testCardsKeepTheAttributeContract(): void
    {
        $html = $this->render('cards.phtml', $this->cardsModel([$this->card(), $this->card('Beta', 'https://w.test/b', null, '')]), '<i>child</i>');
        $this->assertStringContainsString(
            '<div id="cmssearch-panel" class="cmssearch-results" role="tabpanel" aria-labelledby="cmssearch-tab-news" data-cmssearch-results data-total="7">',
            $html
        );
        $this->assertSame(2, substr_count($html, 'data-cmssearch-card'));
        $this->assertSame(2, substr_count($html, '<h2 class="cmssearch-card__title">'));
        $this->assertStringContainsString('<span class="cmssearch-card__type" data-cmssearch-type>Noticias</span>', $html);
        $this->assertStringContainsString('<a href="https://w.test/alpha">Alpha</a>', $html);
        $this->assertSame(1, substr_count($html, '<img '), 'a card without image prints none');
        $this->assertStringContainsString('src="https://w.test/a.jpg" alt=""', $html);
        $this->assertSame(1, substr_count($html, 'cmssearch-card__summary'), 'an empty summary prints no paragraph');
        $this->assertStringContainsString('<i>child</i>', $html, 'the pagination child is printed inside the panel');
        $this->assertStringNotContainsString('data-cmssearch-empty', $html);
        $this->assertStringContainsString('data-cmssearch-amount', $html);
    }

    public function testEveryDynamicValueOfACardIsEscaped(): void
    {
        $h = self::HOSTILE;
        $html = $this->render('cards.phtml', $this->cardsModel([$this->card($h, 'https://w.test/x' . $h, 'https://w.test/i.jpg' . $h, $h, $h)]));
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('onerror=alert(2)>', $html);
        $this->assertSame(1, substr_count($html, '<img '), 'only the card image');
        $this->assertStringNotContainsString('"><script', $html, 'no attribute breakout');
        $this->assertStringNotContainsString('"><img', $html, 'no attribute breakout');
    }

    public function testAScriptSchemeUrlNeverBecomesALink(): void
    {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html;base64,PHNjcmlwdD4='] as $bad) {
            $html = $this->render('cards.phtml', $this->cardsModel([$this->card('T', $bad, $bad)]));
            $this->assertStringNotContainsString($bad, $html);
            $this->assertDoesNotMatchRegularExpression('/(href|src)="(javascript|data):/i', $html);
        }
    }

    public function testACardWithoutUrlIsShownWithoutLink(): void
    {
        $html = $this->render('cards.phtml', $this->cardsModel([$this->card('Plain', '')]));
        $this->assertStringContainsString('<h2 class="cmssearch-card__title">Plain</h2>', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testTheEmptyTabStateOffersToClearTheFilterOnlyWhenFiltered(): void
    {
        $filtered = $this->render('cards.phtml', $this->cardsModel([], ResultCards::EMPTY_TAB, true, true, 0));
        $this->assertStringContainsString('data-cmssearch-empty="tab"', $filtered);
        $this->assertStringContainsString('data-cmssearch-clear', $filtered);
        $this->assertStringNotContainsString('data-cmssearch-card', $filtered);
        $this->assertStringNotContainsString('data-cmssearch-amount', $filtered);
        $plain = $this->render('cards.phtml', $this->cardsModel([], ResultCards::EMPTY_TAB, true, false, 0));
        $this->assertStringContainsString('data-cmssearch-empty="tab"', $plain);
        $this->assertStringNotContainsString('data-cmssearch-clear', $plain);
    }

    public function testThePastTheLastPageStateLinksToTheFirstPage(): void
    {
        $html = $this->render('cards.phtml', $this->cardsModel([], ResultCards::EMPTY_PAGE));
        $this->assertStringContainsString('data-cmssearch-empty="page"', $html);
        $this->assertStringContainsString('<a href="https://s.test/r/?q=a&amp;tab=news">', $html);
        $this->assertStringNotContainsString('data-cmssearch-empty="tab"', $html);
    }

    public function testNothingIsPrintedWhenTheCardsAreNotVisible(): void
    {
        $this->assertSame('', trim($this->render('cards.phtml', $this->cardsModel([$this->card()], null, false))));
    }

    /** @return array{previous: ?string, next: ?string, pages: list<array{number: int, url: string, current: bool}>} */
    private function pagination(): array
    {
        return [
            'previous' => 'https://s.test/r/?q=a&tab=news',
            'next' => 'https://s.test/r/?q=a&tab=news&p=3',
            'pages' => [
                ['number' => 1, 'url' => 'https://s.test/r/?q=a&tab=news', 'current' => false],
                ['number' => 2, 'url' => 'https://s.test/r/?q=a&tab=news&p=2', 'current' => true],
                ['number' => 3, 'url' => 'https://s.test/r/?q=a&tab=news&p=3', 'current' => false],
            ],
        ];
    }

    private function paginationModel(array $pagination): ResultCards
    {
        $model = $this->createMock(ResultCards::class);
        $model->method('getPagination')->willReturn($pagination);
        return $model;
    }

    public function testThePaginationKeepsTheAttributeContract(): void
    {
        $html = $this->render('pagination.phtml', $this->paginationModel($this->pagination()));
        $this->assertStringContainsString('<nav class="cmssearch-pagination" aria-label="Result&#x20;pages" data-cmssearch-pagination>', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertStringContainsString('<span class="cmssearch-pagination__current" aria-current="page" data-cmssearch-page="2">2</span>', $html);
        $this->assertStringContainsString('rel="prev" data-cmssearch-page="previous"', $html);
        $this->assertStringContainsString('rel="next" data-cmssearch-page="next"', $html);
        $this->assertStringContainsString('href="https://s.test/r/?q=a&amp;tab=news&amp;p=3" aria-label="Page&#x20;3" data-cmssearch-page="3">3</a>', $html);
        $this->assertSame(1, substr_count($html, 'data-cmssearch-page="2"'), 'the current page is not a link');
    }

    public function testFirstAndLastPagesOmitThePreviousAndNextLinks(): void
    {
        $first = $this->pagination();
        $first['previous'] = null;
        $this->assertStringNotContainsString('data-cmssearch-page="previous"', $this->render('pagination.phtml', $this->paginationModel($first)));
        $last = $this->pagination();
        $last['next'] = null;
        $html = $this->render('pagination.phtml', $this->paginationModel($last));
        $this->assertStringNotContainsString('data-cmssearch-page="next"', $html);
        $this->assertStringContainsString('data-cmssearch-page="previous"', $html);
    }

    public function testNoPaginationNavigationForASinglePage(): void
    {
        $this->assertSame('', trim($this->render('pagination.phtml', $this->paginationModel(['previous' => null, 'next' => null, 'pages' => []]))));
    }

    public function testPaginationUrlsAreEscaped(): void
    {
        $pagination = $this->pagination();
        $pagination['previous'] = 'https://s.test/r/?q=' . self::HOSTILE;
        $pagination['pages'][0]['url'] = 'https://s.test/r/?q=' . self::HOSTILE;
        $pagination['next'] = 'javascript:alert(1)';
        $html = $this->render('pagination.phtml', $this->paginationModel($pagination));
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
    }

    /** @return list<array<string, mixed>> */
    private function nodes(): array
    {
        return [
            ['code' => 'agro', 'label' => 'Agro', 'count' => 3, 'url' => 'https://s.test/r/?q=a&tab=news&cls=agro', 'selected' => false, 'children' => [
                ['code' => 'milho', 'label' => 'Milho', 'count' => 2, 'url' => 'https://s.test/r/?q=a&tab=news&cls=milho', 'selected' => true, 'children' => []],
            ]],
            ['code' => 'pecas', 'label' => 'Pecas', 'count' => 1, 'url' => 'https://s.test/r/?q=a&tab=news&cls=pecas', 'selected' => false, 'children' => []],
        ];
    }

    /** @param list<array<string, mixed>> $nodes */
    private function filterModel(array $nodes, ?string $clear = null, bool $visible = true): ClassificationFilter
    {
        $model = $this->createMock(ClassificationFilter::class);
        $model->method('isVisible')->willReturn($visible);
        $model->method('getNodes')->willReturn($nodes);
        $model->method('getClearUrl')->willReturn($clear);
        return $model;
    }

    public function testTheFilterRendersTheTreeWithCountsSelectionAndClearLink(): void
    {
        $html = $this->render('classification-filter.phtml', $this->filterModel($this->nodes(), 'https://s.test/r/?q=a&tab=news'));
        $this->assertStringContainsString('data-cmssearch-filter', $html);
        $this->assertSame(2, substr_count($html, '<ul class="cmssearch-filter__list">'), 'a nested list for the children of agro');
        $this->assertStringContainsString('data-cmssearch-term="agro" data-count="3">', $html);
        $this->assertStringContainsString('data-cmssearch-term="milho" data-count="2">', $html);
        $this->assertStringContainsString('<span class="cmssearch-filter__count">(3)</span>', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
        $this->assertMatchesRegularExpression('/class="cmssearch-filter__link is-selected" href="[^"]*cls=milho" aria-current="true"/', $html);
        $this->assertSame(1, substr_count($html, 'is-selected'));
        $this->assertStringContainsString('href="https://s.test/r/?q=a&amp;tab=news" data-cmssearch-clear>', $html);
        $this->assertGreaterThan(strpos($html, 'agro'), strpos($html, 'milho'), 'the child follows its parent');
        $this->assertLessThan(strpos($html, 'pecas'), strpos($html, 'milho'));
    }

    public function testNoClearLinkWithoutASelection(): void
    {
        $html = $this->render('classification-filter.phtml', $this->filterModel($this->nodes(), null));
        $this->assertStringNotContainsString('data-cmssearch-clear', $html);
    }

    public function testASelectionWithoutTermsStillOffersTheClearLink(): void
    {
        $html = $this->render('classification-filter.phtml', $this->filterModel([], 'https://s.test/r/?q=a&tab=news'));
        $this->assertStringContainsString('data-cmssearch-clear', $html);
        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringNotContainsString('cmssearch-filter__list', $html);
    }

    public function testNothingIsPrintedWhenTheFilterIsNotVisible(): void
    {
        $this->assertSame('', trim($this->render('classification-filter.phtml', $this->filterModel($this->nodes(), null, false))));
    }

    public function testEveryDynamicValueOfTheFilterIsEscaped(): void
    {
        $h = self::HOSTILE;
        $nodes = [['code' => $h, 'label' => $h, 'count' => 1, 'url' => 'https://s.test/r/?cls=' . $h, 'selected' => true, 'children' => [
            ['code' => $h, 'label' => $h, 'count' => 1, 'url' => 'javascript:alert(3)', 'selected' => false, 'children' => []],
        ]]];
        $html = $this->render('classification-filter.phtml', $this->filterModel($nodes, 'https://s.test/r/?q=' . $h));
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('"><script', $html, 'no attribute breakout');
        $this->assertStringNotContainsString('"><img', $html, 'no attribute breakout');
    }
}
