<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\Classification\UsageCounter;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;
use Thedevkitchen\CmsSearch\ViewModel\Adminhtml\ClassificationTree;

class ClassificationTreeTest extends TestCase
{
    private function viewModel(string $requestedWebsite, bool $canManage = true, ?TermResource $resource = null, ?UsageCounter $counter = null): ClassificationTree
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('website_id')->willReturn($requestedWebsite);
        $websites = [];
        foreach ([3 => 'Website 2', 2 => 'Website 1'] as $id => $name) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $website->method('getName')->willReturn($name);
            $websites[] = $website;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params = []): string => $route . '?' . http_build_query($params)
        );
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with('Thedevkitchen_CmsSearch::classification_manage')->willReturn($canManage);
        return new ClassificationTree(
            $request,
            $storeManager,
            new TreeBuilder($resource ?? $this->createMock(TermResource::class)),
            $counter ?? $this->createMock(UsageCounter::class),
            $url,
            $authorization
        );
    }

    public function testWebsitesAreSortedById(): void
    {
        $this->assertSame(
            [['id' => 2, 'name' => 'Website 1'], ['id' => 3, 'name' => 'Website 2']],
            $this->viewModel('')->getWebsites()
        );
    }

    public function testAnUnknownOrMissingWebsiteFallsBackToTheFirstOne(): void
    {
        $this->assertSame(3, $this->viewModel('3')->getSelectedWebsiteId());
        $this->assertSame(2, $this->viewModel('99')->getSelectedWebsiteId());
        $this->assertSame(2, $this->viewModel('abc')->getSelectedWebsiteId());
        $this->assertSame(2, $this->viewModel('')->getSelectedWebsiteId());
    }

    public function testRowsAreTheFlattenedTreeOfTheSelectedWebsiteWithPageCounts(): void
    {
        $resource = $this->createMock(TermResource::class);
        $resource->expects($this->once())->method('fetchTreeRows')->with(3)->willReturn([
            ['term_id' => 1, 'parent_id' => null, 'code' => 'agro', 'path' => '1', 'level' => 0, 'sort_order' => 0, 'is_active' => true],
            ['term_id' => 4, 'parent_id' => 1, 'code' => 'milho', 'path' => '1/4', 'level' => 1, 'sort_order' => 0, 'is_active' => false],
        ]);
        $resource->method('fetchLabels')->willReturn([1 => [0 => 'Agro'], 4 => [0 => 'Milho']]);
        $counter = $this->createMock(UsageCounter::class);
        $counter->expects($this->once())->method('countPagesByTerm')->with([1, 4])->willReturn([1 => 0, 4 => 3]);

        $rows = $this->viewModel('3', true, $resource, $counter)->getRows();

        $this->assertSame([1, 4], array_column($rows, 'term_id'));
        $this->assertSame(['Agro', 'Agro / Milho'], array_column($rows, 'trail'));
        $this->assertSame([0, 3], array_column($rows, 'pages'));
        $this->assertSame([true, false], array_column($rows, 'is_active'));
    }

    public function testUrlsCarryTheWebsiteAndTheParent(): void
    {
        $viewModel = $this->viewModel('3');
        $this->assertSame('thedevkitchen_cmssearch/classification/index?website_id=2', $viewModel->getSwitchUrl(2));
        $this->assertSame('thedevkitchen_cmssearch/classification/edit?term_id=9', $viewModel->getEditUrl(9));
        $this->assertSame('thedevkitchen_cmssearch/classification/new?website_id=3', $viewModel->getNewUrl(null));
        $this->assertSame('thedevkitchen_cmssearch/classification/new?website_id=3&parent_id=9', $viewModel->getNewUrl(9));
    }

    public function testManageRightComesFromTheAcl(): void
    {
        $this->assertTrue($this->viewModel('', true)->canManage());
        $this->assertFalse($this->viewModel('', false)->canManage());
    }
}
