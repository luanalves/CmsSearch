<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Ui\DataProvider\Page\Form\Modifier;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\PageClassificationRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\PageClassification;
use Thedevkitchen\CmsSearch\Model\PageClassification\FormFields;
use Thedevkitchen\CmsSearch\Model\PageClassification\PageWebsites;
use Thedevkitchen\CmsSearch\Model\Source\ClassificationTermOptions;
use Thedevkitchen\CmsSearch\Model\Source\PageContentTypeOptions;
use Thedevkitchen\CmsSearch\Ui\DataProvider\Page\Form\Modifier\SearchFieldset;

class SearchFieldsetTest extends TestCase
{
    private PageRepositoryInterface&MockObject $pages;
    private PageClassificationRepositoryInterface&MockObject $links;
    private PageContentTypeOptions&MockObject $typeOptions;
    private ClassificationTermOptions&MockObject $termOptions;
    private LoggerInterface&MockObject $logger;

    private function modifier(string $pageIdParam): SearchFieldset
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('page_id')->willReturn($pageIdParam);
        $this->pages = $this->createMock(PageRepositoryInterface::class);
        $this->links = $this->createMock(PageClassificationRepositoryInterface::class);
        $this->typeOptions = $this->createMock(PageContentTypeOptions::class);
        $this->termOptions = $this->createMock(ClassificationTermOptions::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $websites = [];
        foreach ([2, 3] as $id) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $websites[] = $website;
        }
        $stores = [];
        foreach ([2 => 2, 5 => 3] as $storeId => $websiteId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getStores')->willReturn($stores);

        return new SearchFieldset(
            $request,
            $this->pages,
            $this->links,
            new PageWebsites($storeManager),
            $this->typeOptions,
            $this->termOptions,
            $this->logger
        );
    }

    private function page(array $storeIds): Page
    {
        $page = $this->getMockBuilder(Page::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $page->setData('store_id', $storeIds);
        return $page;
    }

    private function config(array $meta, ?string $child = null): array
    {
        $node = $meta[FormFields::FIELDSET];
        if ($child !== null) {
            $node = $node['children'][$child];
        }
        return $node['arguments']['data']['config'];
    }

    public function testAnExistingPageGetsTheTermsOfItsWebsitesAndKeepsItsCurrentValues(): void
    {
        $modifier = $this->modifier('7');
        $this->pages->method('getById')->with(7)->willReturn($this->page([5]));
        $this->links->method('getByPageId')->with(7)->willReturn(new PageClassification(7, 3, 10));
        $this->typeOptions->expects($this->once())->method('toOptionArrayWithCurrent')->with(3)
            ->willReturn([['value' => 3, 'label' => 'News']]);
        $this->termOptions->expects($this->once())->method('forWebsites')->with([3], 10)
            ->willReturn([['value' => 10, 'label' => 'Fitness']]);

        $meta = $modifier->modifyMeta(['general' => ['keep' => true]]);

        $this->assertSame(['keep' => true], $meta['general']);
        $this->assertSame('fieldset', $this->config($meta)['componentType']);
        $this->assertSame('Search', (string) $this->config($meta)['label']);
        $type = $this->config($meta, FormFields::TYPE);
        $this->assertSame(['select', FormFields::TYPE, [['value' => 3, 'label' => 'News']]],
            [$type['formElement'], $type['dataScope'], $type['options']]);
        $term = $this->config($meta, FormFields::TERM);
        $this->assertSame(['select', FormFields::TERM, [['value' => 10, 'label' => 'Fitness']]],
            [$term['formElement'], $term['dataScope'], $term['options']]);
        $marker = $this->config($meta, FormFields::MARKER);
        $this->assertSame([false, '1', FormFields::MARKER], [$marker['visible'], $marker['default'], $marker['dataScope']]);
    }

    public function testANewPageGetsTheTermsOfEveryWebsite(): void
    {
        $modifier = $this->modifier('');
        $this->pages->expects($this->never())->method('getById');
        $this->links->expects($this->never())->method('getByPageId');
        $this->typeOptions->expects($this->once())->method('toOptionArrayWithCurrent')->with(null)->willReturn([]);
        $this->termOptions->expects($this->once())->method('forWebsites')->with([2, 3], null)->willReturn([]);
        $modifier->modifyMeta([]);
    }

    public function testAPageWithoutLinkHasNoCurrentValues(): void
    {
        $modifier = $this->modifier('7');
        $this->pages->method('getById')->willReturn($this->page([2]));
        $this->links->method('getByPageId')->willThrowException(new NoSuchEntityException());
        $this->typeOptions->expects($this->once())->method('toOptionArrayWithCurrent')->with(null)->willReturn([]);
        $this->termOptions->expects($this->once())->method('forWebsites')->with([2], null)->willReturn([]);
        $modifier->modifyMeta([]);
    }

    public function testAPageInAllStoreViewsGetsEveryWebsite(): void
    {
        $modifier = $this->modifier('7');
        $this->pages->method('getById')->willReturn($this->page([0]));
        $this->links->method('getByPageId')->willThrowException(new NoSuchEntityException());
        $this->typeOptions->method('toOptionArrayWithCurrent')->willReturn([]);
        $this->termOptions->expects($this->once())->method('forWebsites')->with([2, 3], null)->willReturn([]);
        $modifier->modifyMeta([]);
    }

    public function testDataIsLeftUntouched(): void
    {
        $data = [7 => ['title' => 'Home']];
        $this->assertSame($data, $this->modifier('7')->modifyData($data));
    }

    /**
     * The fieldset is an add-on: if its options or the current link cannot be loaded, the native Page form still
     * opens, without the fieldset (so a save cannot post an empty pair over a link that could not be shown).
     */
    public function testAFailureWhileBuildingTheFieldsetLeavesTheNativeFormIntact(): void
    {
        foreach (['options', 'link', 'page'] as $failing) {
            $modifier = $this->modifier('7');
            $failure = new \RuntimeException("SQLSTATE[HY000]: $failing");
            $this->pages->method('getById')->willReturnCallback(
                fn () => $failing === 'page' ? throw $failure : $this->page([2])
            );
            $this->links->method('getByPageId')->willReturnCallback(
                fn () => $failing === 'link' ? throw $failure : new PageClassification(7, 3, 10)
            );
            $this->typeOptions->method('toOptionArrayWithCurrent')->willReturn([]);
            $this->termOptions->method('forWebsites')->willReturnCallback(
                fn () => $failing === 'options' ? throw $failure : []
            );
            $this->logger->expects($this->once())->method('error')
                ->with($this->anything(), $this->callback(fn (array $context): bool => ($context['exception'] ?? null) === $failure));

            $meta = ['general' => ['keep' => true]];
            $this->assertSame($meta, $modifier->modifyMeta($meta), "failure in $failing");
        }
    }
}
