<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Search\Data\Card;
use Thedevkitchen\CmsSearch\Model\Search\Data\CmsResult;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\ResultUrl;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;
use Thedevkitchen\CmsSearch\Model\Search\SummaryFormatter;
use Thedevkitchen\CmsSearch\ViewModel\ResultCards;

class ResultCardsTest extends TestCase
{
    private const BASE = 'https://s.test/catalogsearch/result/?';

    /**
     * @param list<Card> $hits
     * @param array{term_id: int, code: string, label: string}|null $term
     */
    private function cards(?CmsResult $result, string $active = 'news', ?array $term = null, int $page = 1, int $pageSize = 2, bool $enabled = true): ResultCards
    {
        $tabs = [
            new Tab('produtos', Tab::KIND_PRODUCTS, 'Produtos', null),
            new Tab('categorias', Tab::KIND_CATEGORIES, 'Categorias', 1),
            new Tab('news', Tab::KIND_CONTENT, 'Noticias', 3),
        ];
        $activeTab = $tabs[0];
        foreach ($tabs as $tab) {
            if ($tab->getCode() === $active) {
                $activeTab = $tab;
            }
        }
        $state = $this->createMock(SearchState::class);
        $state->method('isEnabled')->willReturn($enabled);
        $state->method('getTabs')->willReturn($tabs);
        $state->method('getActiveTab')->willReturn($activeTab);
        $state->method('isCmsTabActive')->willReturn($activeTab->isCms());
        $state->method('getResult')->willReturn($result);
        $state->method('getSelectedTerm')->willReturn($term);
        $state->method('getQueryText')->willReturn('milho');
        $state->method('getStoreId')->willReturn(2);
        $state->method('getPage')->willReturn($page);
        $state->method('getPageSize')->willReturn($pageSize);
        $config = $this->createMock(Config::class);
        $config->method('getSummaryLength')->with(2)->willReturn(20);
        $builder = $this->createMock(UrlInterface::class);
        $builder->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => self::BASE . http_build_query($params['_query'])
        );
        $request = $this->createMock(RequestInterface::class);
        $request->method('isSecure')->willReturn(true);
        return new ResultCards($state, $config, new SummaryFormatter(), new ResultUrl($builder, $request));
    }

    /** @param list<Card> $hits */
    private function answer(int $total, array $hits = []): CmsResult
    {
        return new CmsResult(['news' => 3], 1, true, $total, $hits, []);
    }

    public function testOnlyShownOnACmsTabThatHasAnAnswer(): void
    {
        $this->assertFalse($this->cards($this->answer(3), 'produtos')->isVisible());
        $this->assertFalse($this->cards(null)->isVisible());
        $this->assertFalse($this->cards($this->answer(3), 'news', null, 1, 2, false)->isVisible());
        $this->assertTrue($this->cards($this->answer(3))->isVisible());
        $this->assertSame(['cmssearch-panel', 'cmssearch-tab-news'], [$this->cards($this->answer(3))->getPanelId(), $this->cards($this->answer(3))->getActiveTabId()]);
    }

    public function testCardsCarryTheTypeLabelAndAShortenedSummary(): void
    {
        $cards = $this->cards($this->answer(2, [
            new Card('page', '<i>x</i> Alpha', 'https://w1.test/alpha', 'https://w1.test/media/a.jpg', 'The quick brown fox jumps over', 'news'),
            new Card('category', 'Sementes', 'https://w1.test/sementes.html', null, 'Short', null),
        ]))->getCards();
        $this->assertSame(
            [
                ['title' => '<i>x</i> Alpha', 'url' => 'https://w1.test/alpha', 'image' => 'https://w1.test/media/a.jpg', 'summary' => 'The quick brown fox…', 'type_label' => 'Noticias'],
                ['title' => 'Sementes', 'url' => 'https://w1.test/sementes.html', 'image' => null, 'summary' => 'Short', 'type_label' => 'Categorias'],
            ],
            $cards
        );
    }

    public function testAnEmptyTabOffersToClearTheFilter(): void
    {
        $cards = $this->cards($this->answer(0), 'news', ['term_id' => 1, 'code' => 'agro', 'label' => 'Agro']);
        $this->assertSame(ResultCards::EMPTY_TAB, $cards->getEmptyState());
        $this->assertTrue($cards->hasFilter());
        $this->assertSame(self::BASE . 'q=milho&tab=news', $cards->getClearFilterUrl());
        $this->assertSame(0, $cards->getTotal());
    }

    public function testAPagePastTheLastOneHasItsOwnEmptyState(): void
    {
        $cards = $this->cards($this->answer(5), 'news', null, 9);
        $this->assertSame(ResultCards::EMPTY_PAGE, $cards->getEmptyState());
        $this->assertSame(self::BASE . 'q=milho&tab=news', $cards->getFirstPageUrl());
        $this->assertNull($this->cards($this->answer(1, [new Card('page', 'A', '', null, '', 'news')]))->getEmptyState());
    }

    /** @return array<string, array{0: int, 1: list<int>, 2: ?int, 3: ?int, 4: ?int}> */
    public static function pages(): array
    {
        return [
            'first page' => [1, [1, 2, 3], null, 2, 1],
            'middle page' => [7, [5, 6, 7, 8, 9], 6, 8, 7],
            'last page' => [13, [11, 12, 13], 12, null, 13],
            'past the last page' => [20, [11, 12, 13], 13, null, null],
        ];
    }

    /** @param list<int> $numbers */
    #[DataProvider('pages')]
    public function testThePaginationShowsAWindowAroundTheCurrentPage(int $page, array $numbers, ?int $previous, ?int $next, ?int $current): void
    {
        $pagination = $this->cards($this->answer(25), 'news', null, $page)->getPagination();
        $this->assertSame($numbers, array_column($pagination['pages'], 'number'));
        $currentPages = array_values(array_filter($pagination['pages'], static fn (array $p): bool => $p['current']));
        $this->assertSame($current, $currentPages[0]['number'] ?? null);
        $url = static fn (?int $n): ?string => $n === null ? null : self::BASE . 'q=milho&tab=news' . ($n > 1 ? '&p=' . $n : '');
        $this->assertSame($url($previous), $pagination['previous']);
        $this->assertSame($url($next), $pagination['next']);
    }

    public function testThePaginationNeverGoesPastTheResultWindow(): void
    {
        // 100000 hits, 12 per page: the window of 10000 documents ends at page 833
        $pagination = $this->cards($this->answer(100000), 'news', null, 833, 12)->getPagination();
        $this->assertSame([831, 832, 833], array_column($pagination['pages'], 'number'));
        $this->assertNull($pagination['next']);
        $before = $this->cards($this->answer(100000), 'news', null, 832, 12)->getPagination();
        $this->assertSame([830, 831, 832, 833], array_column($before['pages'], 'number'));
        $this->assertSame(self::BASE . 'q=milho&tab=news&p=833', $before['next']);
    }

    public function testASingleResultOnAnotherPageIsAPageStateNotAnEmptyTab(): void
    {
        $this->assertSame(ResultCards::EMPTY_PAGE, $this->cards($this->answer(1), 'news', null, 2)->getEmptyState());
    }

    public function testASinglePageHasNoPagination(): void
    {
        $this->assertSame(['previous' => null, 'next' => null, 'pages' => []], $this->cards($this->answer(2), 'news', null, 1, 12)->getPagination());
    }

    public function testPageLinksKeepTheFilter(): void
    {
        $pages = $this->cards($this->answer(5), 'news', ['term_id' => 1, 'code' => 'agro', 'label' => 'Agro'])->getPagination()['pages'];
        $this->assertSame(self::BASE . 'q=milho&tab=news&cls=agro', $pages[0]['url']);
        $this->assertSame(self::BASE . 'q=milho&tab=news&cls=agro&p=2', $pages[1]['url']);
    }
}
