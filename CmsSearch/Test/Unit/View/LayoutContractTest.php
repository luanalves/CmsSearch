<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Block\Result\Tabs;
use Thedevkitchen\CmsSearch\Observer\AddCmsTabHandle;
use Thedevkitchen\CmsSearch\ViewModel\ClassificationFilter;
use Thedevkitchen\CmsSearch\ViewModel\ResultCards;
use Thedevkitchen\CmsSearch\ViewModel\SearchTabs;

/**
 * Static contract of the search result layouts (spec 5.6: layout + handle, no controller plugin).
 */
class LayoutContractTest extends TestCase
{
    private function layout(string $handle): \SimpleXMLElement
    {
        $path = dirname(__DIR__, 3) . '/view/frontend/layout/' . $handle . '.xml';
        $this->assertFileExists($path);
        $xml = simplexml_load_file($path);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }

    private function viewModel(\SimpleXMLElement $block): string
    {
        return ltrim((string) ($block->xpath('arguments/argument[@name="view_model"]')[0] ?? ''), '\\');
    }

    public function testTheBaseLayoutAddsTheTabBarAndWrapsTheNativeResultWithoutRemovingAnything(): void
    {
        $xml = $this->layout('catalogsearch_result_index');
        $tabs = $xml->xpath('//referenceContainer[@name="content"]/block[@name="thedevkitchen.cmssearch.tabs"]');
        $this->assertCount(1, $tabs);
        $this->assertSame(Tabs::class, ltrim((string) $tabs[0]['class'], '\\'));
        $this->assertSame('-', (string) $tabs[0]['before']);
        $this->assertSame(SearchTabs::class, $this->viewModel($tabs[0]));
        $panel = $xml->xpath('//referenceContainer[@name="content"]/block[@name="thedevkitchen.cmssearch.products.panel"]');
        $this->assertCount(1, $panel);
        $this->assertSame(SearchTabs::class, $this->viewModel($panel[0]));
        $move = $xml->xpath('//move[@element="search.result"]');
        $this->assertCount(1, $move);
        $this->assertSame('thedevkitchen.cmssearch.products.panel', (string) $move[0]['destination']);
        $this->assertSame([], $xml->xpath('//*[@remove="true"]'));
    }

    public function testTheCmsHandleReplacesTheListingAndTheLayeredNavigation(): void
    {
        $xml = $this->layout(AddCmsTabHandle::HANDLE);
        foreach (['search.result', 'thedevkitchen.cmssearch.products.panel', 'catalogsearch.leftnav', 'search_result_impression'] as $name) {
            $this->assertCount(1, $xml->xpath('//referenceBlock[@name="' . $name . '"][@remove="true"]'), $name);
        }
        $cards = $xml->xpath('//referenceContainer[@name="content"]/block[@name="thedevkitchen.cmssearch.cards"]');
        $this->assertCount(1, $cards);
        $this->assertSame(ResultCards::class, $this->viewModel($cards[0]));
        $pagination = $cards[0]->xpath('block[@name="thedevkitchen.cmssearch.pagination"]');
        $this->assertCount(1, $pagination);
        $this->assertSame('pagination', (string) $pagination[0]['as']);
        $this->assertSame(ResultCards::class, $this->viewModel($pagination[0]));
        $filter = $xml->xpath('//referenceContainer[@name="sidebar.main"]/block[@name="thedevkitchen.cmssearch.filter"]');
        $this->assertCount(1, $filter);
        $this->assertSame(ClassificationFilter::class, $this->viewModel($filter[0]));
    }

    public function testEveryTemplateAndViewModelExistsAndNothingIsUncacheable(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['catalogsearch_result_index', AddCmsTabHandle::HANDLE] as $handle) {
            $xml = $this->layout($handle);
            $this->assertSame([], $xml->xpath('//block[@cacheable="false"]'), "$handle must stay cacheable");
            foreach ($xml->xpath('//block[@template]') as $block) {
                [$module, $file] = explode('::', (string) $block['template']);
                $this->assertSame('Thedevkitchen_CmsSearch', $module);
                $this->assertFileExists($root . '/view/frontend/templates/' . $file);
                $this->assertTrue(class_exists($this->viewModel($block)), (string) $block['name']);
            }
        }
    }

    public function testTheHandleFileIsTheOneTheObserverAdds(): void
    {
        $this->assertSame('thedevkitchen_cmssearch_tab', AddCmsTabHandle::HANDLE);
        $this->assertSame('catalogsearch_result_index', AddCmsTabHandle::ACTION);
        $this->assertFileExists(dirname(__DIR__, 3) . '/view/frontend/layout/' . AddCmsTabHandle::HANDLE . '.xml');
    }
}
