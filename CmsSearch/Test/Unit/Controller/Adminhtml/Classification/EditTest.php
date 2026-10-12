<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Controller\Adminhtml\Classification;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\AbstractClassification;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\Edit;
use Thedevkitchen\CmsSearch\Test\Unit\Support\AdminContextTrait;

class EditTest extends TestCase
{
    use AdminContextTrait;

    private function controller(array $params, ?ClassificationTermRepositoryInterface $repository = null): Edit
    {
        $this->setUpContext($params);
        $title = $this->createMock(Title::class);
        $config = $this->createMock(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($config);
        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(2);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);
        return new Edit(
            $this->context,
            $pageFactory,
            $repository ?? $this->createMock(ClassificationTermRepositoryInterface::class),
            $storeManager
        );
    }

    public function testTheViewRightOpensTheForm(): void
    {
        $controller = $this->controller([]);
        $this->assertSame(
            [AbstractClassification::RESOURCE_VIEW],
            $this->consultedResources($controller, AbstractClassification::RESOURCE_VIEW)
        );
    }

    public function testAMissingTermGoesBackToTheTree(): void
    {
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->method('getById')->with(7)->willThrowException(new NoSuchEntityException(__('gone')));
        $controller = $this->controller(['term_id' => '7'], $repository);
        $this->messages->expects($this->once())->method('addErrorMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/index');
        $this->assertSame($this->redirect, $controller->execute());
    }

    public function testANewTermNeedsAnExistingWebsite(): void
    {
        $controller = $this->controller(['website_id' => '9']);
        $this->messages->expects($this->once())->method('addErrorMessage');
        $this->assertSame($this->redirect, $controller->execute());
    }

    public function testANewTermOfAnExistingWebsiteRendersThePage(): void
    {
        $controller = $this->controller(['website_id' => '2']);
        $this->messages->expects($this->never())->method('addErrorMessage');
        $this->assertInstanceOf(Page::class, $controller->execute());
    }
}
