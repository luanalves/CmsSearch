<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Search\Data\CmsResult;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\ResultUrl;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;
use Thedevkitchen\CmsSearch\ViewModel\ClassificationFilter;

class ClassificationFilterTest extends TestCase
{
    private const BASE = 'https://s.test/catalogsearch/result/?';

    /** @return list<array<string, mixed>> */
    private function tree(): array
    {
        return [
            ['term_id' => 1, 'code' => 'agro', 'label' => 'Agro', 'children' => [
                ['term_id' => 4, 'code' => 'milho', 'label' => 'Milho', 'children' => []],
                ['term_id' => 5, 'code' => 'soja', 'label' => 'Soja', 'children' => []],
            ]],
            ['term_id' => 2, 'code' => 'pecas', 'label' => 'Pecas', 'children' => []],
        ];
    }

    /**
     * @param array<int, int> $counts
     * @param array{term_id: int, code: string, label: string}|null $term
     */
    private function filter(string $kind, array $counts, ?array $term = null): ClassificationFilter
    {
        $state = $this->createMock(SearchState::class);
        $state->method('isEnabled')->willReturn(true);
        $state->method('getActiveTab')->willReturn(new Tab($kind === Tab::KIND_CONTENT ? 'news' : 'categorias', $kind, 'X', 1));
        $state->method('getResult')->willReturn(new CmsResult([], 0, false, 0, [], $counts));
        $state->method('getSelectedTerm')->willReturn($term);
        $state->method('getTermTree')->willReturn($this->tree()); // even a (never expected) tree on another kind must not show the filter (D4)
        $state->method('getQueryText')->willReturn('milho');
        $builder = $this->createMock(UrlInterface::class);
        $builder->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => self::BASE . http_build_query($params['_query'])
        );
        $request = $this->createMock(RequestInterface::class);
        $request->method('isSecure')->willReturn(true);
        return new ClassificationFilter($state, new ResultUrl($builder, $request));
    }

    public function testOnlyContentTabsHaveTheFilter(): void
    {
        $this->assertFalse($this->filter(Tab::KIND_CATEGORIES, [1 => 2])->isVisible());
        $this->assertTrue($this->filter(Tab::KIND_CONTENT, [1 => 2])->isVisible());
    }

    public function testTermsWithResultsAreNestedWithCountsAndFilterLinks(): void
    {
        $this->assertSame(
            [
                ['code' => 'agro', 'label' => 'Agro', 'count' => 2, 'url' => self::BASE . 'q=milho&tab=news&cls=agro', 'selected' => false, 'children' => [
                    ['code' => 'milho', 'label' => 'Milho', 'count' => 1, 'url' => self::BASE . 'q=milho&tab=news&cls=milho', 'selected' => false, 'children' => []],
                ]],
                ['code' => 'pecas', 'label' => 'Pecas', 'count' => 1, 'url' => self::BASE . 'q=milho&tab=news&cls=pecas', 'selected' => false, 'children' => []],
            ],
            $this->filter(Tab::KIND_CONTENT, [1 => 2, 4 => 1, 2 => 1])->getNodes()
        );
    }

    public function testTheSelectedTermIsMarkedAndCanBeCleared(): void
    {
        $filter = $this->filter(Tab::KIND_CONTENT, [1 => 2, 4 => 1], ['term_id' => 4, 'code' => 'milho', 'label' => 'Milho']);
        $this->assertTrue($filter->getNodes()[0]['children'][0]['selected']);
        $this->assertFalse($filter->getNodes()[0]['selected']);
        $this->assertSame(self::BASE . 'q=milho&tab=news', $filter->getClearUrl());
        $this->assertNull($this->filter(Tab::KIND_CONTENT, [1 => 2])->getClearUrl());
    }

    public function testCountsOutsideTheWebsiteTreeAreIgnoredButASelectionStaysClearable(): void
    {
        $filter = $this->filter(Tab::KIND_CONTENT, [99 => 5]);
        $this->assertSame([], $filter->getNodes());
        $this->assertFalse($filter->isVisible());
        $selected = $this->filter(Tab::KIND_CONTENT, [99 => 5], ['term_id' => 2, 'code' => 'pecas', 'label' => 'Pecas']);
        $this->assertTrue($selected->isVisible());
    }
}
