<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Block\Result;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Block\Result\Tabs;
use Thedevkitchen\CmsSearch\Model\Search\ProductCount;

class TabsTest extends TestCase
{
    public function testTheProductsCountIsAskedWithThePageLayout(): void
    {
        $layout = $this->createMock(LayoutInterface::class);
        $count = $this->createMock(ProductCount::class);
        $count->expects($this->once())->method('count')->with($layout, true)->willReturn(74);
        /** @var Tabs $block */
        $block = (new ObjectManager($this))->getObject(Tabs::class, ['productCount' => $count]);
        $block->setLayout($layout);
        $this->assertSame(74, $block->getProductCount(true));
    }
}
