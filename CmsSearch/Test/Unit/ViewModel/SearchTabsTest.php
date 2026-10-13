<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\ResultUrl;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;
use Thedevkitchen\CmsSearch\ViewModel\SearchTabs;

class SearchTabsTest extends TestCase
{
    /** @param list<Tab> $tabs */
    private function viewModel(bool $enabled, array $tabs, string $active): SearchTabs
    {
        $state = $this->createMock(SearchState::class);
        $state->method('isEnabled')->willReturn($enabled);
        $state->method('getTabs')->willReturn($tabs);
        $state->method('getQueryText')->willReturn('milho');
        $activeTab = $tabs[0] ?? new Tab('produtos', Tab::KIND_PRODUCTS, 'Produtos', null);
        foreach ($tabs as $tab) {
            if ($tab->getCode() === $active) {
                $activeTab = $tab;
            }
        }
        $state->method('getActiveTab')->willReturn($activeTab);
        $state->method('isCmsTabActive')->willReturn($activeTab->isCms());
        $builder = $this->createMock(UrlInterface::class);
        $builder->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => 'https://s.test/catalogsearch/result/?' . http_build_query($params['_query'])
        );
        $request = $this->createMock(RequestInterface::class);
        $request->method('isSecure')->willReturn(true);
        return new SearchTabs($state, new ResultUrl($builder, $request));
    }

    /** @return list<Tab> */
    private function tabs(): array
    {
        return [
            new Tab('produtos', Tab::KIND_PRODUCTS, 'Produtos', null),
            new Tab('news', Tab::KIND_CONTENT, 'Notícias', 3),
            new Tab('help', Tab::KIND_CONTENT, 'Ajuda', 0),
        ];
    }

    public function testHiddenWhenDisabledOrWhenOnlyTheProductsTabExists(): void
    {
        $this->assertFalse($this->viewModel(false, [], 'produtos')->isVisible());
        $this->assertFalse($this->viewModel(true, [new Tab('produtos', Tab::KIND_PRODUCTS, 'Produtos', null)], 'produtos')->isVisible());
        $this->assertTrue($this->viewModel(true, $this->tabs(), 'produtos')->isVisible());
        $this->assertFalse($this->viewModel(false, $this->tabs(), 'produtos')->isVisible(), 'a disabled store view never shows the bar');
    }

    public function testTabsCarryLabelCountUrlAndSelection(): void
    {
        $viewModel = $this->viewModel(true, $this->tabs(), 'news');
        $this->assertFalse($viewModel->isProductsTabActive());
        $this->assertSame(
            [
                ['code' => 'produtos', 'label' => 'Produtos', 'count' => 74, 'url' => 'https://s.test/catalogsearch/result/?q=milho', 'active' => false, 'id' => 'cmssearch-tab-produtos'],
                ['code' => 'news', 'label' => 'Notícias', 'count' => 3, 'url' => 'https://s.test/catalogsearch/result/?q=milho&tab=news', 'active' => true, 'id' => 'cmssearch-tab-news'],
                ['code' => 'help', 'label' => 'Ajuda', 'count' => 0, 'url' => 'https://s.test/catalogsearch/result/?q=milho&tab=help', 'active' => false, 'id' => 'cmssearch-tab-help'],
            ],
            $viewModel->getTabs(74)
        );
    }

    public function testAnUnknownProductsCountStaysUnknown(): void
    {
        $tabs = $this->viewModel(true, $this->tabs(), 'produtos')->getTabs(null);
        $this->assertNull($tabs[0]['count']);
        $this->assertTrue($tabs[0]['active']);
    }

    public function testThePanelIsLabelledByTheActiveTab(): void
    {
        $viewModel = $this->viewModel(true, $this->tabs(), 'help');
        $this->assertSame('cmssearch-panel', $viewModel->getPanelId());
        $this->assertSame('cmssearch-tab-help', $viewModel->getActiveTabId());
        $this->assertSame('cmssearch-tab-news', SearchTabs::tabId('news'));
    }
}
