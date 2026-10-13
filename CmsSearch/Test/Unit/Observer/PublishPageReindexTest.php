<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;
use Thedevkitchen\CmsSearch\Observer\PublishPageReindex;

class PublishPageReindexTest extends TestCase
{
    private ReindexPublisherInterface&MockObject $publisher;
    private ShadowedPageReader&MockObject $shadows;
    private LoggerInterface&MockObject $logger;
    /** @var list<string> every publish() call as "type id action" */
    private array $published = [];

    protected function setUp(): void
    {
        $this->publisher = $this->createMock(ReindexPublisherInterface::class);
        $this->shadows = $this->createMock(ShadowedPageReader::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->published = [];
    }

    private function recordPublished(): void
    {
        $this->publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action): void {
            $this->published[] = "$type $id $action";
        });
    }

    /** A real page model: current identifier and the identifier it was loaded with. */
    private function model(int $id, string $identifier, ?string $loadedAs): Page&MockObject
    {
        $page = $this->createMock(Page::class);
        $page->method('getId')->willReturn($id);
        $page->method('getIdentifier')->willReturn($identifier);
        $page->method('getOrigData')->willReturnCallback(
            static fn (?string $key = null): ?string => $key === 'identifier' ? $loadedAs : null
        );
        return $page;
    }

    /** @param array<string, mixed> $data */
    private function dispatch(string $eventName, array $data): void
    {
        $event = new Event($data);
        $event->setName($eventName);
        (new PublishPageReindex($this->publisher, $this->shadows, $this->logger))->execute(new Observer(['event' => $event]));
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

    /**
     * Page 11 (store view 2) is renamed away from `home`: the store-0 `home` page (2) it shadowed is served again in
     * store view 2, but the consumer can no longer find it from page 11's new URL key, so the producer names it.
     */
    public function testARenameAwayFromAUrlKeyAlsoAsksForTheStoreZeroPagesOfTheOldKey(): void
    {
        $this->recordPublished();
        $this->shadows->expects($this->once())->method('activeStoreZeroPagesNamed')->with(['home'], 11)->willReturn([2]);
        $this->dispatch('cms_page_save_after', ['object' => $this->model(11, 'home-old', 'home')]);
        $this->assertSame(['page 11 save', 'page 2 save'], $this->published);
    }

    public function testADeleteAlsoAsksForTheStoreZeroPagesOfItsUrlKey(): void
    {
        $this->recordPublished();
        $this->shadows->expects($this->once())->method('activeStoreZeroPagesNamed')->with(['home'], 11)->willReturn([2, 5]);
        $this->dispatch('cms_page_delete_after', ['object' => $this->model(11, 'home', 'home')]);
        $this->assertSame(['page 11 delete', 'page 2 save', 'page 5 save'], $this->published);
    }

    public function testASaveWithAnUnchangedUrlKeyAsksForNothingElse(): void
    {
        // the consumer finds the siblings of the CURRENT key itself (AffectedHosts): no query here
        $this->recordPublished();
        $this->shadows->expects($this->never())->method('activeStoreZeroPagesNamed');
        $this->dispatch('cms_page_save_after', ['object' => $this->model(11, 'home', 'home')]);
        $this->dispatch('cms_page_save_after', ['object' => $this->model(12, 'new-page', null)]); // a new page
        $this->assertSame(['page 11 save', 'page 12 save'], $this->published);
    }

    public function testAFailedSiblingLookupNeverBreaksThePageWrite(): void
    {
        $this->recordPublished();
        $this->shadows->method('activeStoreZeroPagesNamed')->willThrowException(new \RuntimeException('SQLSTATE gone'));
        $this->logger->expects($this->once())->method('error');
        $this->dispatch('cms_page_delete_after', ['object' => $this->model(11, 'home', 'home')]);
        $this->assertSame(['page 11 delete'], $this->published);
    }
}
