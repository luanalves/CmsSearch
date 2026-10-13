<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogSearch\Block\Result;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Model\Search\ProductCount;

class ProductCountTest extends TestCase
{
    private LayerResolver&MockObject $layers;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->layers = $this->createMock(LayerResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function layout(Result|false $block): LayoutInterface&MockObject
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->with(ProductCount::RESULT_BLOCK)->willReturn($block);
        return $layout;
    }

    private function countFor(LayoutInterface $layout, bool $productsTab): ?int
    {
        return (new ProductCount($this->layers, $this->logger))->count($layout, $productsTab);
    }

    public function testTheProductsTabReadsTheNativeCountAfterTheListingRendered(): void
    {
        $calls = [];
        $block = $this->createMock(Result::class);
        $block->method('getData')->with('result_count')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'count';
            return 74;
        });
        $block->expects($this->never())->method('getResultCount');
        $layout = $this->layout($block);
        $layout->expects($this->once())->method('renderElement')->with(ProductCount::RESULT_BLOCK)
            ->willReturnCallback(function () use (&$calls): string {
                $calls[] = 'render';
                return '<div class="search results"></div>';
            });
        $this->layers->expects($this->never())->method('get');
        $this->assertSame(74, $this->countFor($layout, true));
        $this->assertSame(['render', 'count'], $calls);
    }

    public function testTheNativeCounterIsAskedWhenTheListingDidNotStoreIt(): void
    {
        $block = $this->createMock(Result::class);
        $block->method('getData')->willReturn(null);
        $block->method('getResultCount')->willReturn(5);
        $this->assertSame(5, $this->countFor($this->layout($block), true));
    }

    public function testWithoutTheNativeResultBlockTheCountIsUnknown(): void
    {
        $layout = $this->layout(false);
        $layout->expects($this->never())->method('renderElement');
        $this->assertNull($this->countFor($layout, true));
    }

    public function testAnotherBlockUnderTheNativeNameIsNeverRendered(): void
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturn($this->createMock(Template::class));
        $layout->expects($this->never())->method('renderElement');
        $this->assertNull($this->countFor($layout, true));
    }

    public function testACmsTabCountsThroughTheSearchLayer(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getSize')->willReturn(42);
        $layer = $this->createMock(Layer::class);
        $layer->method('getProductCollection')->willReturn($collection);
        $this->layers->method('get')->willReturn($layer);
        $layout = $this->createMock(LayoutInterface::class);
        $layout->expects($this->never())->method('renderElement');
        $this->assertSame(42, $this->countFor($layout, false));
    }

    public function testFailuresAreLoggedAndHideTheNumber(): void
    {
        $this->layers->method('get')->willThrowException(new \RuntimeException('search engine down'));
        $this->logger->expects($this->once())->method('warning');
        $this->assertNull($this->countFor($this->createMock(LayoutInterface::class), false));
    }

    // ---- additions to the brief's tests ----

    public function testAStoredZeroIsTheCount(): void
    {
        $block = $this->createMock(Result::class);
        $block->method('getData')->willReturn(0);
        $block->expects($this->never())->method('getResultCount');
        $this->assertSame(0, $this->countFor($this->layout($block), true));
    }

    public function testAFailingRenderHidesTheNumber(): void
    {
        $block = $this->createMock(Result::class);
        $layout = $this->layout($block);
        $layout->method('renderElement')->willThrowException(new \RuntimeException('template error'));
        $this->logger->expects($this->once())->method('warning')->with($this->callback(
            static fn (string $message): bool => str_contains($message, 'RuntimeException') && !str_contains($message, 'template error')
        ));
        $this->assertNull($this->countFor($layout, true));
    }

    public function testABrokenLogNeverBreaksThePage(): void
    {
        $this->layers->method('get')->willThrowException(new \RuntimeException('search engine down'));
        $this->logger->method('warning')->willThrowException(new \UnexpectedValueException('log file not writable'));
        $this->assertNull($this->countFor($this->createMock(LayoutInterface::class), false));
    }

    public function testTheCountSourceIsReplaceableThroughDi(): void
    {
        // Plan decision 2 is verified on the real page in Task 10; its stated fallback (search_query.num_results)
        // must be possible as a di.xml preference for this class without touching its consumers.
        $class = new \ReflectionClass(ProductCount::class);
        $this->assertFalse($class->isFinal());
        $this->assertTrue($class->getMethod('count')->isPublic());
        $this->assertFalse($class->getMethod('count')->isFinal());
    }

    public function testTheNativeListingIsRenderedOnceHoweverOftenTheCountIsAsked(): void
    {
        $block = $this->createMock(Result::class);
        $block->method('getData')->with('result_count')->willReturn(74);
        $layout = $this->layout($block);
        $layout->expects($this->once())->method('renderElement')->with(ProductCount::RESULT_BLOCK)->willReturn('<div></div>');
        $counter = new ProductCount($this->layers, $this->logger);
        $this->assertSame([74, 74], [$counter->count($layout, true), $counter->count($layout, true)]);
        $counter->_resetState();
        $other = $this->layout($block); // next request: another layout is counted again (its own single render)
        $other->expects($this->once())->method('renderElement')->willReturn('<div></div>');
        $this->assertSame(74, $counter->count($other, true));
    }

    public function testEachTabKindHasItsOwnMemoWithinOneLayout(): void
    {
        $block = $this->createMock(Result::class);
        $block->method('getData')->with('result_count')->willReturn(74);
        $layout = $this->layout($block);
        $layout->method('renderElement')->willReturn('<div></div>');
        $collection = $this->createMock(Collection::class);
        $collection->method('getSize')->willReturn(42);
        $layer = $this->createMock(Layer::class);
        $layer->method('getProductCollection')->willReturn($collection);
        $this->layers->method('get')->willReturn($layer);
        $counter = new ProductCount($this->layers, $this->logger);
        $this->assertSame(74, $counter->count($layout, true));
        $this->assertSame(42, $counter->count($layout, false), 'the CMS tab must not reuse the Products tab count');
        $this->assertSame(74, $counter->count($layout, true));
    }
}
