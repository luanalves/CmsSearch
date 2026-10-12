<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterfaceFactory;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Exception\ContentTypeInUseException;
use Thedevkitchen\CmsSearch\Model\ContentType;
use Thedevkitchen\CmsSearch\Model\ContentType\DeleteGuard;
use Thedevkitchen\CmsSearch\Model\ContentTypeFactory;
use Thedevkitchen\CmsSearch\Model\ContentTypeRepository;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType as ContentTypeResource;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\Collection;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\CollectionFactory;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ContentTypeBuilder;

class ContentTypeRepositoryTest extends TestCase
{
    private ContentTypeResource&MockObject $resource;
    private ContentTypeFactory&MockObject $factory;
    private DeleteGuard&MockObject $deleteGuard;
    private ReindexPublisherInterface&MockObject $publisher;
    private CollectionFactory&MockObject $collectionFactory;
    private CollectionProcessorInterface&MockObject $collectionProcessor;
    private ContentTypeSearchResultsInterfaceFactory&MockObject $searchResultsFactory;
    private ContentTypeRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ContentTypeResource::class);
        $this->factory = $this->createMock(ContentTypeFactory::class);
        $this->deleteGuard = $this->createMock(DeleteGuard::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);
        $this->searchResultsFactory = $this->createMock(ContentTypeSearchResultsInterfaceFactory::class);
        $this->publisher = $this->createMock(ReindexPublisherInterface::class);
        $this->repository = new ContentTypeRepository(
            $this->resource,
            $this->factory,
            $this->collectionFactory,
            $this->collectionProcessor,
            $this->searchResultsFactory,
            $this->deleteGuard,
            $this->publisher
        );
    }

    private function type(?int $id, string $code, array $labels = [0 => 'News']): ContentType
    {
        return ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), $id, $code, $labels);
    }

    public function testSaveStoresAValidNewType(): void
    {
        $type = $this->type(null, 'news');
        $this->resource->method('codeExists')->with('news', null)->willReturn(false);
        $this->resource->expects($this->once())->method('save')->with($type);

        $this->assertSame($type, $this->repository->save($type));
    }

    public function testSaveRejectsInvalidCode(): void
    {
        $this->resource->expects($this->never())->method('save');
        $this->expectException(InputException::class);
        $this->repository->save($this->type(null, 'Not Valid!'));
    }

    public function testSaveRejectsDuplicateCode(): void
    {
        $this->resource->method('codeExists')->with('news', null)->willReturn(true);
        $this->resource->expects($this->never())->method('save');
        $this->expectException(AlreadyExistsException::class);
        $this->repository->save($this->type(null, 'news'));
    }

    public function testSaveRejectsChangingTheCodeOfAnExistingType(): void
    {
        $this->resource->method('getCodeById')->with(5)->willReturn('news');
        $this->resource->expects($this->never())->method('save');
        $this->expectException(InputException::class);
        $this->repository->save($this->type(5, 'noticias'));
    }

    public function testSaveRequiresTheDefaultLabel(): void
    {
        $this->resource->method('codeExists')->willReturn(false);
        $this->resource->expects($this->never())->method('save');
        $this->expectException(InputException::class);
        $this->repository->save($this->type(null, 'news', [2 => 'Noticias']));
    }

    public function testSaveAllowsAnExistingTypeKeepingItsCode(): void
    {
        $type = $this->type(5, 'news');
        $this->resource->method('getCodeById')->with(5)->willReturn('news');
        $this->resource->method('codeExists')->with('news', 5)->willReturn(false);
        $this->resource->expects($this->once())->method('save')->with($type);

        $this->repository->save($type);
    }

    public function testDeleteIsBlockedWhenTheGuardThrows(): void
    {
        $type = $this->type(7, 'tv');
        $this->deleteGuard->method('assertDeletable')->with($type)
            ->willThrowException(new ContentTypeInUseException(__('in use')));
        $this->resource->expects($this->never())->method('delete');

        $this->expectException(ContentTypeInUseException::class);
        $this->repository->delete($type);
    }

    public function testDeleteRemovesAFreeType(): void
    {
        $type = $this->type(7, 'tv');
        $this->resource->expects($this->once())->method('delete')->with($type);

        $this->assertTrue($this->repository->delete($type));
    }

    public function testGetByIdThrowsWhenTheTypeDoesNotExist(): void
    {
        $this->factory->method('create')->willReturn($this->type(null, 'x'));
        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(99);
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $loaded = $this->type(7, 'tv');
        $this->factory->method('create')->willReturn($loaded);
        $this->resource->expects($this->once())->method('load')->willReturnCallback(
            function (ContentType $model, $id) use ($loaded) {
                $this->assertSame($loaded, $model);
                $this->assertSame(7, $id);
                return $this->resource;
            }
        );
        $this->resource->expects($this->once())->method('delete')->with($loaded);

        $this->assertTrue($this->repository->deleteById(7));
    }

    public function testSaveDoesNotRewrapAlreadyExists(): void
    {
        $this->resource->method('codeExists')->willReturn(false);
        $this->resource->method('save')->willThrowException(new AlreadyExistsException(__('race')));
        $this->expectException(AlreadyExistsException::class);
        $this->repository->save($this->type(null, 'news'));
    }

    public function testSaveWrapsOtherFailures(): void
    {
        $this->resource->method('codeExists')->willReturn(false);
        $this->resource->method('save')->willThrowException(new \RuntimeException('db'));
        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($this->type(null, 'news'));
    }

    public function testDeleteWrapsFailures(): void
    {
        $this->resource->method('delete')->willThrowException(new \RuntimeException('db'));
        $this->expectException(CouldNotDeleteException::class);
        $this->repository->delete($this->type(7, 'tv'));
    }

    public function testGetByCodeReturnsTheType(): void
    {
        $loaded = $this->type(7, 'tv');
        $this->factory->method('create')->willReturn($loaded);
        $this->resource->expects($this->once())->method('load')->with($loaded, 'tv', 'code');
        $this->assertSame($loaded, $this->repository->getByCode('tv'));
    }

    public function testGetByCodeThrowsWhenMissing(): void
    {
        $this->factory->method('create')->willReturn($this->type(null, 'x'));
        $this->expectException(NoSuchEntityException::class);
        $this->repository->getByCode('missing');
    }

    public function testGetListProcessesCriteriaAndKeepsCollectionOrder(): void
    {
        $first = $this->type(2, 'b');
        $second = $this->type(1, 'a');
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn([$first, $second]);
        $collection->method('getSize')->willReturn(2);
        $collection->expects($this->never())->method('getAllIds');
        $this->collectionFactory->method('create')->willReturn($collection);
        $this->collectionProcessor->expects($this->once())->method('process')->with($criteria, $collection);

        $results = $this->createMock(ContentTypeSearchResultsInterface::class);
        $results->expects($this->once())->method('setSearchCriteria')->with($criteria);
        $results->expects($this->once())->method('setItems')->with([$first, $second]);
        $results->expects($this->once())->method('setTotalCount')->with(2);
        $this->searchResultsFactory->method('create')->willReturn($results);

        $this->assertSame($results, $this->repository->getList($criteria));
    }

    /** @return array<string, array{0: int, 1: array<int, string>}> */
    public static function invalidBounds(): array
    {
        return [
            'negative sort order' => [-1, [0 => 'News']],
            'sort order too big' => [65536, [0 => 'News']],
            'default label too long' => [0, [0 => str_repeat('a', 256)]],
            'store label too long' => [0, [0 => 'News', 2 => str_repeat('b', 256)]],
        ];
    }

    /** @param array<int, string> $labels */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBounds')]
    public function testSaveRejectsOutOfBoundsValues(int $sortOrder, array $labels): void
    {
        $this->resource->method('codeExists')->willReturn(false);
        $this->resource->expects($this->never())->method('save');
        $this->expectException(InputException::class);
        $this->repository->save($this->type(null, 'news', $labels)->setSortOrder($sortOrder));
    }

    public function testSaveAcceptsTheBoundaryValues(): void
    {
        $type = $this->type(null, 'news', [0 => str_repeat('a', 255)])->setSortOrder(65535);
        $this->resource->method('codeExists')->willReturn(false);
        $this->resource->expects($this->once())->method('save')->with($type);

        $this->repository->save($type);
    }

    public function testSavePublishesTheStoredTypeAfterTheWrite(): void
    {
        $type = $this->type(null, 'news');
        $calls = [];
        $this->resource->method('codeExists')->willReturn(false);
        $this->resource->method('save')->willReturnCallback(function (ContentType $saved) use (&$calls) {
            $calls[] = 'save';
            $saved->setTypeId(12);
            return $this->resource;
        });
        $this->publisher->expects($this->once())->method('publish')->with('content_type', 12, 'save')
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'publish';
            });
        $this->repository->save($type);
        $this->assertSame(['save', 'publish'], $calls);
    }

    public function testAnInvalidTypePublishesNothing(): void
    {
        $this->publisher->expects($this->never())->method('publish');
        $this->expectException(InputException::class);
        $this->repository->save($this->type(null, 'Not Valid!'));
    }

    public function testAFailedWritePublishesNothing(): void
    {
        $this->resource->method('codeExists')->willReturn(false);
        $this->resource->method('save')->willThrowException(new \RuntimeException('db'));
        $this->publisher->expects($this->never())->method('publish');
        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($this->type(null, 'news'));
    }

    public function testDeletePublishesTheDeletedTypeId(): void
    {
        $type = $this->type(7, 'tv');
        $this->resource->expects($this->once())->method('delete')->with($type);
        $this->publisher->expects($this->once())->method('publish')->with('content_type', 7, 'delete');
        $this->repository->delete($type);
    }

    public function testABlockedDeletePublishesNothing(): void
    {
        $type = $this->type(7, 'tv');
        $this->deleteGuard->method('assertDeletable')->willThrowException(new ContentTypeInUseException(__('in use')));
        $this->publisher->expects($this->never())->method('publish');
        $this->expectException(ContentTypeInUseException::class);
        $this->repository->delete($type);
    }
}
