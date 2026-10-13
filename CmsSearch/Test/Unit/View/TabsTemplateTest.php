<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\View;

use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\ZendEscaper;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Block\Result\Tabs;
use Thedevkitchen\CmsSearch\ViewModel\SearchTabs;

/**
 * Renders the real templates with stubbed view model and block: attribute contract of the tab bar (relied on by the
 * curl suite and by the keyboard module), escaping of every dynamic value, hidden and empty states.
 */
class TabsTemplateTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../view/frontend/templates/result/';

    /** @param list<array<string, mixed>> $tabs */
    private function viewModel(bool $visible, array $tabs, string $activeId = 'cmssearch-tab-produtos'): SearchTabs
    {
        $viewModel = $this->createMock(SearchTabs::class);
        $viewModel->method('isVisible')->willReturn($visible);
        $viewModel->method('isProductsTabActive')->willReturn(true);
        $viewModel->method('getTabs')->willReturn($tabs);
        $viewModel->method('getPanelId')->willReturn('cmssearch-panel');
        $viewModel->method('getActiveTabId')->willReturn($activeId);
        return $viewModel;
    }

    private function tab(string $code, string $label, ?int $count, bool $active, string $url = 'https://s.test/r/?q=a&tab=x'): array
    {
        return ['code' => $code, 'label' => $label, 'count' => $count, 'url' => $url, 'active' => $active, 'id' => 'cmssearch-tab-' . $code];
    }

    private function render(string $template, SearchTabs $viewModel, string $children = ''): string
    {
        $block = $this->createMock($template === 'tabs.phtml' ? Tabs::class : Template::class);
        $block->method('getData')->with('view_model')->willReturn($viewModel);
        if ($block instanceof Tabs) {
            $block->method('getProductCount')->willReturn(74);
        }
        $block->method('getChildHtml')->willReturn($children);
        $escaper = new Escaper();
        // the real escaper reads the inline translator through the ObjectManager, which a unit test does not have
        $property = new \ReflectionProperty(Escaper::class, 'translateInline');
        $property->setValue($escaper, $this->createMock(InlineInterface::class));
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

    public function testTheTabBarKeepsTheAttributeContract(): void
    {
        $html = $this->render('tabs.phtml', $this->viewModel(true, [
            $this->tab('produtos', 'Produtos', 74, true, 'https://s.test/r/?q=a'),
            $this->tab('news', 'Notícias', 0, false),
        ]));
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('data-cmssearch-tablist', $html);
        $this->assertStringContainsString(
            '<a href="https://s.test/r/?q=a" id="cmssearch-tab-produtos" class="cmssearch-tab cmssearch-tab--active" role="tab" aria-selected="true" aria-controls="cmssearch-panel" data-cmssearch-tab="produtos" data-count="74">',
            $html
        );
        $this->assertStringContainsString(
            '<a href="https://s.test/r/?q=a&amp;tab=x" id="cmssearch-tab-news" class="cmssearch-tab" role="tab" aria-selected="false" data-cmssearch-tab="news" data-count="0">',
            $html
        );
        $this->assertSame(1, substr_count($html, 'aria-controls='), 'only the active tab points to the panel');
        $this->assertStringContainsString('<span class="cmssearch-tab__count">(0)</span>', $html);
        $this->assertStringContainsString('{"[data-cmssearch-tablist]": {"Thedevkitchen_CmsSearch/js/tabs": {}}}', $html);
    }

    public function testAnUnknownCountPrintsNoNumber(): void
    {
        $html = $this->render('tabs.phtml', $this->viewModel(true, [
            $this->tab('produtos', 'Produtos', null, true),
            $this->tab('news', 'Notícias', 3, false),
        ]));
        $this->assertSame(1, substr_count($html, 'data-count='));
        $this->assertSame(1, substr_count($html, 'cmssearch-tab__count'));
        $this->assertStringContainsString('(3)', $html);
    }

    public function testEveryDynamicValueIsEscaped(): void
    {
        $html = $this->render('tabs.phtml', $this->viewModel(true, [
            $this->tab('produtos', 'Produtos', 1, true),
            $this->tab('x"><i>', 'A <b>bold</b> & "quoted"', 2, false, 'https://s.test/r/?q="><script>alert(1)</script>'),
        ], 'cmssearch-tab-"><u>'));
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('A &lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;', $html);
        $this->assertSame(1, substr_count($html, '<script'), 'only the init script');
    }

    public function testNothingIsPrintedWhenTheBarIsHidden(): void
    {
        $this->assertSame('', trim($this->render('tabs.phtml', $this->viewModel(false, []))));
    }

    public function testThePanelWrapsTheNativeResultAndIsLabelledByTheActiveTab(): void
    {
        $html = $this->render('products-panel.phtml', $this->viewModel(true, [], 'cmssearch-tab-produtos'), '<p>native</p>');
        $this->assertStringContainsString(
            '<div id="cmssearch-panel" class="cmssearch-panel" role="tabpanel" aria-labelledby="cmssearch-tab-produtos">',
            $html
        );
        $this->assertStringContainsString('<p>native</p>', $html);
    }

    public function testWithoutTheBarTheNativeResultIsPrintedUnchanged(): void
    {
        $this->assertSame('<p>native</p>', $this->render('products-panel.phtml', $this->viewModel(false, []), '<p>native</p>'));
    }

    public function testThePanelIdIsEscaped(): void
    {
        $viewModel = $this->createMock(SearchTabs::class);
        $viewModel->method('isVisible')->willReturn(true);
        $viewModel->method('getPanelId')->willReturn('p"><x>');
        $viewModel->method('getActiveTabId')->willReturn('t"><y>');
        $html = $this->render('products-panel.phtml', $viewModel, 'kid');
        $this->assertStringNotContainsString('<x>', $html);
        $this->assertStringNotContainsString('<y>', $html);
    }
}
