<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterfaceFactory;
use Thedevkitchen\CmsSearch\Exception\ContentTypeInUseException;
use Thedevkitchen\CmsSearch\Model\ContentType;
use Thedevkitchen\CmsSearch\Model\ContentType\DeleteGuard;
use Thedevkitchen\CmsSearch\Model\ContentTypeFactory;
use Thedevkitchen\CmsSearch\Model\ContentTypeRepository;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType as ContentTypeResource;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\CollectionFactory;

class ContentTypeRepositoryTest extends TestCase
{
    private ContentTypeResource&MockObject $resource;
    private ContentTypeFactory&MockObject $factory;
    private DeleteGuard&MockObject $deleteGuard;
    private ContentTypeRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ContentTypeResource::class);
        $this->factory = $this->createMock(ContentTypeFactory::class);
        $this->deleteGuard = $this->createMock(DeleteGuard::class);
        $this->repository = new ContentTypeRepository(
            $this->resource,
            $this->factory,
            $this->createMock(CollectionFactory::class),
            $this->createMock(CollectionProcessorInterface::class),
            $this->createMock(ContentTypeSearchResultsInterfaceFactory::class),
            $this->deleteGuard
        );
    }

    private function type(?int $id, string $code, array $labels = [0 => 'News']): ContentType
    {
        // A resource instance keeps AbstractModel::_init() from touching the ObjectManager.
        $resource = $this->createMock(ContentTypeResource::class);
        $resource->method('getIdFieldName')->willReturn('type_id');
        $type = new ContentType(
            $this->createMock(\Magento\Framework\Model\Context::class),
            $this->createMock(\Magento\Framework\Registry::class),
            $resource
        );
        if ($id !== null) {
            $type->setTypeId($id);
        }
        return $type->setCode($code)->setLabels($labels);
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
        $this->resource->method('load')->willReturnCallback(
            function (ContentType $model, $id) use ($loaded) {
                $this->assertSame($loaded, $model);
                $this->assertSame(7, $id);
                return $this->resource;
            }
        );
        $this->resource->expects($this->once())->method('delete')->with($loaded);

        $this->assertTrue($this->repository->deleteById(7));
    }
}
