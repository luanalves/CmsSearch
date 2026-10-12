<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Source;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;
use Thedevkitchen\CmsSearch\Model\Source\ClassificationTermOptions;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ClassificationTermBuilder;

class ClassificationTermOptionsTest extends TestCase
{
    private function row(int $id, ?int $parent, string $path, bool $active = true): array
    {
        return ['term_id' => $id, 'parent_id' => $parent, 'code' => 'c' . $id, 'path' => $path,
            'level' => substr_count($path, '/'), 'sort_order' => 0, 'is_active' => $active];
    }

    private function options(?ClassificationTermRepositoryInterface $repository = null): ClassificationTermOptions
    {
        $rows = [
            2 => [$this->row(1, null, '1'), $this->row(4, 1, '1/4'), $this->row(6, 1, '1/6', false)],
            3 => [$this->row(7, null, '7')],
        ];
        $labels = [1 => [0 => 'Agro'], 4 => [0 => 'Milho'], 6 => [0 => 'Soja'], 7 => [0 => 'Fitness']];
        $resource = $this->createMock(TermResource::class);
        $resource->method('fetchTreeRows')->willReturnCallback(fn (int $websiteId) => $rows[$websiteId] ?? []);
        $resource->method('fetchLabels')->willReturnCallback(
            fn (array $ids) => array_intersect_key($labels, array_flip($ids))
        );
        $websites = [];
        foreach ([2 => 'Website 1', 3 => 'Website 2'] as $id => $name) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $website->method('getName')->willReturn($name);
            $websites[] = $website;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        return new ClassificationTermOptions(
            new TreeBuilder($resource),
            $storeManager,
            $repository ?? $this->createMock(ClassificationTermRepositoryInterface::class)
        );
    }

    public function testOneWebsiteListsItsActiveTermsAsTrails(): void
    {
        $this->assertSame(
            [['value' => 1, 'label' => 'Agro'], ['value' => 4, 'label' => 'Agro / Milho']],
            $this->options()->forWebsites([2], null)
        );
    }

    public function testSeveralWebsitesArePrefixedWithTheWebsiteName(): void
    {
        $this->assertSame(
            ['Website 1 / Agro', 'Website 1 / Agro / Milho', 'Website 2 / Fitness'],
            array_column($this->options()->forWebsites([3, 2], null), 'label')
        );
    }

    public function testAnInactiveCurrentTermIsKeptAndMarked(): void
    {
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->method('getById')->with(6)->willReturn(
            ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), 6, 2, 'c6', 1, [0 => 'Soja'])
        );
        $options = $this->options($repository)->forWebsites([2], 6);
        $this->assertSame(['value' => 6, 'label' => 'Agro / Soja (inactive)'], end($options));
    }

    public function testACurrentTermOfAnotherWebsiteIsKeptAndMarked(): void
    {
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->method('getById')->with(7)->willReturn(
            ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), 7, 3, 'c7', null, [0 => 'Fitness'])
        );
        $options = $this->options($repository)->forWebsites([2], 7);
        $this->assertSame(['value' => 7, 'label' => 'Fitness (other website)'], end($options));
    }

    public function testAnOfferedOrDeletedCurrentTermAddsNothing(): void
    {
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->assertCount(2, $this->options($repository)->forWebsites([2], 4));
        $this->assertCount(2, $this->options($repository)->forWebsites([2], 99));
    }
}
