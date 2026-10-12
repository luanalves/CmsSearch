<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Ui\DataProvider\ContentType;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\Save;
use Thedevkitchen\CmsSearch\Model\ContentType\FormDataMapper;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ContentTypeBuilder;
use Thedevkitchen\CmsSearch\Ui\DataProvider\ContentType\FormDataProvider;

class FormDataProviderTest extends TestCase
{
    private ContentTypeRepositoryInterface&MockObject $repository;
    private DataPersistorInterface&MockObject $persistor;

    private function provider(int $requestedId): FormDataProvider
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn((string) $requestedId);
        $this->repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $this->persistor = $this->createMock(DataPersistorInterface::class);
        return new FormDataProvider(
            'ds',
            'type_id',
            'type_id',
            $this->repository,
            new FormDataMapper(),
            $request,
            $this->persistor
        );
    }

    public function testMetaLocksTheCodeOnlyForExistingTypes(): void
    {
        $this->assertSame([], $this->provider(0)->getMeta());
        $meta = $this->provider(5)->getMeta();
        $this->assertTrue($meta['general']['children']['code']['arguments']['data']['config']['disabled']);
    }

    public function testNewTypeWithoutPersistedDataHasNoData(): void
    {
        $provider = $this->provider(0);
        $this->persistor->method('get')->willReturn(null);
        $this->assertSame([], $provider->getData());
    }

    public function testMissingTypeYieldsNoData(): void
    {
        $provider = $this->provider(7);
        $this->persistor->method('get')->willReturn(null);
        $this->repository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->assertSame([], $provider->getData());
    }

    public function testLoadsTheRequestedType(): void
    {
        $provider = $this->provider(5);
        $this->persistor->method('get')->willReturn(null);
        $this->repository->method('getById')->with(5)->willReturn(
            ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 5, 'news')
        );
        $data = $provider->getData();
        $this->assertSame('news', $data[5]['code']);
    }

    public function testPersistedDataForTheSameTypeIsUsedAndCleared(): void
    {
        $provider = $this->provider(5);
        $persisted = ['type_id' => '5', 'default_label' => 'typed'];
        $this->persistor->method('get')->with(Save::PERSISTOR_KEY)->willReturn($persisted);
        $this->persistor->expects($this->once())->method('clear')->with(Save::PERSISTOR_KEY);
        $this->repository->expects($this->never())->method('getById');
        $this->assertSame([5 => $persisted], $provider->getData());
    }

    public function testPersistedDataForANewTypeIsUsedOnTheNewForm(): void
    {
        $provider = $this->provider(0);
        $persisted = ['code' => 'Bad Code'];
        $this->persistor->method('get')->willReturn($persisted);
        $this->persistor->expects($this->once())->method('clear');
        $this->assertSame(['' => $persisted], $provider->getData());
    }

    public function testPersistedDataForAnotherTypeIsIgnoredAndKept(): void
    {
        $provider = $this->provider(6);
        $this->persistor->method('get')->willReturn(['type_id' => '5', 'default_label' => 'typed']);
        $this->persistor->expects($this->never())->method('clear');
        $this->repository->method('getById')->with(6)->willReturn(
            ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 6, 'other')
        );
        $this->assertSame('other', $provider->getData()[6]['code']);
    }
}
