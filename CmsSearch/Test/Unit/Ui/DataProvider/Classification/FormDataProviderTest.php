<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Ui\DataProvider\Classification;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\Save;
use Thedevkitchen\CmsSearch\Model\Classification\FormDataMapper;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\ContentType\FormDataMapper as LabelsMapper;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ClassificationTermBuilder;
use Thedevkitchen\CmsSearch\Ui\DataProvider\Classification\FormDataProvider;

class FormDataProviderTest extends TestCase
{
    private ClassificationTermRepositoryInterface&MockObject $repository;
    private DataPersistorInterface&MockObject $persistor;

    private function provider(array $params): FormDataProvider
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $params[$name] ?? null);
        $this->repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $this->persistor = $this->createMock(DataPersistorInterface::class);

        $resource = $this->createMock(TermResource::class);
        $resource->method('fetchTreeRows')->with(2)->willReturn([
            ['term_id' => 1, 'parent_id' => null, 'code' => 'agro', 'path' => '1', 'level' => 0, 'sort_order' => 0, 'is_active' => true],
            ['term_id' => 4, 'parent_id' => 1, 'code' => 'milho', 'path' => '1/4', 'level' => 1, 'sort_order' => 0, 'is_active' => true],
            ['term_id' => 9, 'parent_id' => 4, 'code' => 'sementes', 'path' => '1/4/9', 'level' => 2, 'sort_order' => 0, 'is_active' => false],
            ['term_id' => 7, 'parent_id' => null, 'code' => 'tv', 'path' => '7', 'level' => 0, 'sort_order' => 1, 'is_active' => true],
        ]);
        $resource->method('fetchLabels')->willReturn([1 => [0 => 'Agro'], 4 => [0 => 'Milho'], 9 => [0 => 'Sementes'], 7 => [0 => 'TV']]);

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(2);
        $website->method('getName')->willReturn('Website 1');
        $stores = [];
        foreach ([2 => [2, 'Electronics'], 3 => [2, 'Gaming'], 5 => [3, 'Appliances']] as $id => [$websiteId, $name]) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $store->method('getName')->willReturn($name);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);
        $storeManager->method('getStores')->willReturn($stores);

        return new FormDataProvider(
            'ds',
            'term_id',
            'term_id',
            $this->repository,
            new FormDataMapper(new LabelsMapper()),
            new TreeBuilder($resource),
            $storeManager,
            $request,
            $this->persistor
        );
    }

    private function config(array $meta, string ...$path): array
    {
        $node = $meta['general'];
        foreach ($path as $child) {
            $node = $node['children'][$child];
        }
        return $node['arguments']['data']['config'];
    }

    public function testEditingLocksTheCodeAndExcludesTheOwnSubtreeFromTheParents(): void
    {
        $provider = $this->provider(['term_id' => '4']);
        $this->repository->method('getById')->with(4)->willReturn(
            ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), 4, 2, 'milho', 1)->setData('path', '1/4')
        );
        $meta = $provider->getMeta();
        $this->assertTrue($this->config($meta, 'code')['disabled']);
        $this->assertSame(
            [['value' => '', 'label' => '(Root)'], ['value' => 1, 'label' => 'Agro'], ['value' => 7, 'label' => 'TV']],
            array_map(fn (array $o): array => ['value' => $o['value'], 'label' => (string) $o['label']], $this->config($meta, 'parent_id')['options'])
        );
    }

    public function testStoreLabelOptionsAreTheStoreViewsOfTheTermsWebsite(): void
    {
        $meta = $this->provider(['website_id' => '2'])->getMeta();
        $this->assertSame(
            [['value' => 2, 'label' => 'Electronics'], ['value' => 3, 'label' => 'Gaming']],
            $this->config($meta, 'store_labels', 'record', 'store_id')['options']
        );
        $this->assertArrayNotHasKey('code', $meta['general']['children'] ?? []); // not locked for a new term
        $this->assertCount(5, $this->config($meta, 'parent_id')['options']); // root + 4 terms, inactive ones included
    }

    public function testANewTermIsPrefilledWithWebsiteAndParent(): void
    {
        $provider = $this->provider(['website_id' => '2', 'parent_id' => '4']);
        $this->persistor->method('get')->willReturn(null);
        $this->assertSame(
            ['' => ['website_id' => '2', 'website_name' => 'Website 1', 'parent_id' => '4', 'is_active' => '1', 'sort_order' => '0']],
            $provider->getData()
        );
    }

    public function testAParentOutsideTheWebsiteIsNotPrefilled(): void
    {
        $provider = $this->provider(['website_id' => '2', 'parent_id' => '99']);
        $this->persistor->method('get')->willReturn(null);
        $this->assertSame('', $provider->getData()['']['parent_id']);
    }

    public function testAnUnknownWebsiteYieldsNoData(): void
    {
        $provider = $this->provider(['website_id' => '9']);
        $this->persistor->method('get')->willReturn(null);
        $this->assertSame([], $provider->getData());
    }

    public function testLoadsTheRequestedTerm(): void
    {
        $provider = $this->provider(['term_id' => '4']);
        $this->persistor->method('get')->willReturn(null);
        $this->repository->method('getById')->with(4)->willReturn(
            ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), 4, 2, 'milho', 1)
        );
        $data = $provider->getData();
        $this->assertSame('milho', $data[4]['code']);
        $this->assertSame('Website 1', $data[4]['website_name']);
    }

    public function testAMissingTermYieldsNoData(): void
    {
        $provider = $this->provider(['term_id' => '4']);
        $this->persistor->method('get')->willReturn(null);
        $this->repository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->assertSame([], $provider->getData());
    }

    public function testPersistedDataOfTheSameTermIsUsedOnceAndCleared(): void
    {
        $provider = $this->provider(['term_id' => '4']);
        $persisted = ['term_id' => '4', 'default_label' => 'typed'];
        $this->persistor->method('get')->with(Save::PERSISTOR_KEY)->willReturn($persisted);
        $this->persistor->expects($this->once())->method('clear')->with(Save::PERSISTOR_KEY);
        $this->repository->expects($this->never())->method('getById');
        $this->assertSame([4 => $persisted], $provider->getData());
    }

    public function testANonScalarPersistedTermIdDoesNotBreakTheForm(): void
    {
        $provider = $this->provider(['term_id' => '4']);
        $this->persistor->method('get')->with(Save::PERSISTOR_KEY)->willReturn(['term_id' => ['x'], 'default_label' => 'typed']);
        $this->repository->expects($this->once())->method('getById')->with(4)->willReturn(
            ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), 4, 2, 'milho', 1)
        );
        $this->assertArrayHasKey(4, $provider->getData());
    }

    public function testNonScalarRequestParametersAreTreatedAsAbsent(): void
    {
        $provider = $this->provider(['term_id' => ['1'], 'website_id' => ['2'], 'parent_id' => ['1']]);
        $this->repository->expects($this->never())->method('getById');
        $this->assertSame([], $provider->getData());
        $this->assertArrayHasKey('general', $provider->getMeta());
    }
}
