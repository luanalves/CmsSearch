<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Cms\Model\Page;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\PageClassificationRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\Input\StrictInput;
use Thedevkitchen\CmsSearch\Model\PageClassification\FormFields;
use Thedevkitchen\CmsSearch\Model\PageClassification\PageWebsites;
use Thedevkitchen\CmsSearch\Model\PageClassification\SubmittedValues;
use Thedevkitchen\CmsSearch\Observer\SavePageClassification;

class SavePageClassificationTest extends TestCase
{
    private PageClassificationRepositoryInterface&MockObject $repository;
    private LoggerInterface&MockObject $logger;

    private function observer(): SavePageClassification
    {
        $this->repository = $this->createMock(PageClassificationRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        return new SavePageClassification(
            new SubmittedValues(new StrictInput()),
            $this->repository,
            new PageWebsites($this->createMock(StoreManagerInterface::class)),
            $this->logger
        );
    }

    private function page(int $id, array $data): Page
    {
        $page = $this->getMockBuilder(Page::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $page->method('getId')->willReturn($id);
        $page->setData($data);
        return $page;
    }

    private function event(?object $object): Observer
    {
        return new Observer(['event' => new Event(['object' => $object])]);
    }

    public function testTheSubmittedPairIsAssignedWithThePagesStoreViews(): void
    {
        $observer = $this->observer();
        $this->repository->expects($this->once())->method('assign')->with(7, 3, 10, [2, 5]);
        $observer->execute($this->event($this->page(7, [
            'store_id' => ['2', '5'], FormFields::MARKER => '1', FormFields::TYPE => '3', FormFields::TERM => '10',
        ])));
    }

    /**
     * The store views given to assign() are the ones the page save writes (Store\SaveHandler: getStores(), else
     * store_id), never anything else: a loaded "stores" value wins over a stale "store_id", an empty "stores"
     * falls back to "store_id", a scalar store_id (single select / REST) becomes a one-element list.
     */
    public function testTheStoreViewsAreExactlyTheOnesThePageSaveWrites(): void
    {
        $observer = $this->observer();
        $calls = [];
        $this->repository->expects($this->exactly(3))->method('assign')
            ->willReturnCallback(function (int $pageId, ?int $typeId, ?int $termId, array $storeIds) use (&$calls): void {
                $calls[] = $storeIds;
            });
        $fieldset = [FormFields::MARKER => '1', FormFields::TYPE => '3', FormFields::TERM => '10'];
        $observer->execute($this->event($this->page(7, ['stores' => ['5'], 'store_id' => [2]] + $fieldset)));
        $observer->execute($this->event($this->page(7, ['stores' => [], 'store_id' => ['0']] + $fieldset)));
        $observer->execute($this->event($this->page(7, ['store_id' => '3'] + $fieldset)));
        $this->assertSame([[5], [0], [3]], $calls);
    }

    public function testClearedFieldsUnlinkThroughTheRepository(): void
    {
        $observer = $this->observer();
        $this->repository->expects($this->once())->method('assign')->with(7, null, null, [2]);
        $observer->execute($this->event($this->page(7, [
            'store_id' => [2], FormFields::MARKER => '1', FormFields::TYPE => '', FormFields::TERM => '',
        ])));
    }

    public function testASaveWithoutTheFieldsetIsIgnored(): void
    {
        $observer = $this->observer();
        $this->repository->expects($this->never())->method('assign');
        $observer->execute($this->event($this->page(7, ['title' => 'Home', 'store_id' => [2]])));
        $observer->execute($this->event(new DataObject([FormFields::MARKER => '1'])));
        $observer->execute($this->event(null));
    }

    public function testAValidationErrorPropagatesSoThePageSaveIsRolledBack(): void
    {
        $observer = $this->observer();
        $error = new InputException(__('must belong to a website'));
        $this->repository->method('assign')->willThrowException($error);
        $this->logger->expects($this->never())->method('error');
        try {
            $observer->execute($this->event($this->page(7, [
                'store_id' => [2], FormFields::MARKER => '1', FormFields::TYPE => '3', FormFields::TERM => '20',
            ])));
            $this->fail('the validation error must propagate');
        } catch (InputException $e) {
            $this->assertSame($error, $e, 'the admin must see the validation message itself');
        }
    }

    public function testUnexpectedFailuresAreLoggedAndHiddenFromTheAdmin(): void
    {
        $observer = $this->observer();
        $failure = new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation in thedevkitchen_cmssearch_page_classification');
        $this->repository->method('assign')->willThrowException($failure);
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('SQLSTATE'), $this->callback(fn (array $context): bool => ($context['exception'] ?? null) === $failure));
        try {
            $observer->execute($this->event($this->page(7, [
                'store_id' => [2], FormFields::MARKER => '1', FormFields::TYPE => '3', FormFields::TERM => '10',
            ])));
            $this->fail('the failure must still roll the page save back');
        } catch (CouldNotSaveException $e) {
            $this->assertSame('Could not save the search classification of the page.', $e->getMessage());
            $this->assertNull($e->getPrevious(), 'the raw failure is logged, not chained to what the admin sees');
        }
    }

    public function testAPageWithoutIdCannotBeClassified(): void
    {
        $observer = $this->observer();
        $this->repository->expects($this->never())->method('assign');
        $this->expectException(LocalizedException::class);
        $observer->execute($this->event($this->page(0, [FormFields::MARKER => '1', FormFields::TYPE => '3', FormFields::TERM => '10'])));
    }
}
