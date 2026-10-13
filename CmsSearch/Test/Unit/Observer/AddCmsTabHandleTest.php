<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;
use Thedevkitchen\CmsSearch\Observer\AddCmsTabHandle;

class AddCmsTabHandleTest extends TestCase
{
    private SearchState&MockObject $state;
    private ProcessorInterface&MockObject $update;
    private LayoutInterface&MockObject $layout;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->state = $this->createMock(SearchState::class);
        $this->update = $this->createMock(ProcessorInterface::class);
        $this->layout = $this->createMock(LayoutInterface::class);
        $this->layout->method('getUpdate')->willReturn($this->update);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function dispatch(string $action, mixed $layout = 'default'): void
    {
        $event = new Event(['full_action_name' => $action, 'layout' => $layout === 'default' ? $this->layout : $layout]);
        (new AddCmsTabHandle($this->state, $this->logger))->execute(new Observer(['event' => $event]));
    }

    public function testACmsTabGetsTheHandleThatReplacesTheListing(): void
    {
        $this->state->method('isEnabled')->willReturn(true);
        $this->state->method('isCmsTabActive')->willReturn(true);
        $this->update->expects($this->once())->method('addHandle')->with(AddCmsTabHandle::HANDLE);
        $this->dispatch(AddCmsTabHandle::ACTION);
    }

    public function testTheProductsTabKeepsTheNativeLayout(): void
    {
        $this->state->method('isEnabled')->willReturn(true);
        $this->state->method('isCmsTabActive')->willReturn(false);
        $this->update->expects($this->never())->method('addHandle');
        $this->dispatch(AddCmsTabHandle::ACTION);
    }

    public function testOtherPagesNeverComputeTheSearchState(): void
    {
        $this->state->expects($this->never())->method('isEnabled');
        $this->state->expects($this->never())->method('isCmsTabActive');
        $this->update->expects($this->never())->method('addHandle');
        $this->dispatch('cms_index_index');
    }

    public function testADisabledModuleKeepsTheNativeLayout(): void
    {
        $this->state->method('isEnabled')->willReturn(false);
        $this->state->method('isCmsTabActive')->willReturn(true);
        $this->update->expects($this->never())->method('addHandle');
        $this->dispatch(AddCmsTabHandle::ACTION);
    }

    public function testAnErrorIsLoggedAndNeverBreaksThePage(): void
    {
        $this->state->method('isEnabled')->willThrowException(new \RuntimeException('boom secret-query'));
        $this->logger->expects($this->once())->method('error')->with($this->callback(
            static fn (string $message): bool => str_contains($message, 'RuntimeException')
                && !str_contains($message, 'secret-query')
        ));
        $this->update->expects($this->never())->method('addHandle');
        $this->dispatch(AddCmsTabHandle::ACTION);
    }

    public function testAnUnwritableLogNeverBreaksThePage(): void
    {
        $this->state->method('isEnabled')->willThrowException(new \RuntimeException('boom'));
        $this->logger->method('error')->willThrowException(new \UnexpectedValueException('log file not writable'));
        $this->update->expects($this->never())->method('addHandle');
        $this->dispatch(AddCmsTabHandle::ACTION);
    }

    public function testAnEventWithoutLayoutIsIgnored(): void
    {
        $this->state->expects($this->never())->method('isEnabled');
        $this->dispatch(AddCmsTabHandle::ACTION, null);
    }
}
