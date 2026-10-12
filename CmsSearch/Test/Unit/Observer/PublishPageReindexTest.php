<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Observer\PublishPageReindex;

class PublishPageReindexTest extends TestCase
{
    private ReindexPublisherInterface&MockObject $publisher;

    protected function setUp(): void
    {
        $this->publisher = $this->createMock(ReindexPublisherInterface::class);
    }

    /** @param array<string, mixed> $data */
    private function dispatch(string $eventName, array $data): void
    {
        $event = new Event($data);
        $event->setName($eventName);
        (new PublishPageReindex($this->publisher))->execute(new Observer(['event' => $event]));
    }

    private function page(?int $id): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('getId')->willReturn($id);
        return $page;
    }

    public function testASaveAsksForAPageSave(): void
    {
        $this->publisher->expects($this->once())->method('publish')->with('page', 7, 'save');
        $this->dispatch('cms_page_save_after', ['object' => $this->page(7)]);
    }

    public function testADeleteAsksForAPageDelete(): void
    {
        $this->publisher->expects($this->once())->method('publish')->with('page', 7, 'delete');
        $this->dispatch('cms_page_delete_after', ['object' => $this->page(7)]);
    }

    public function testThePageIsAlsoFoundAsDataObject(): void
    {
        $this->publisher->expects($this->once())->method('publish')->with('page', 9, 'save');
        $this->dispatch('cms_page_save_after', ['data_object' => $this->page(9)]);
    }

    public function testSomethingThatIsNotAPageIsIgnored(): void
    {
        $this->publisher->expects($this->never())->method('publish');
        $this->dispatch('cms_page_save_after', ['object' => new DataObject(['id' => 7])]);
    }

    public function testAPageWithoutIdIsIgnored(): void
    {
        $this->publisher->expects($this->never())->method('publish');
        $this->dispatch('cms_page_save_after', ['object' => $this->page(null)]);
    }
}
