<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterface;
use Thedevkitchen\CmsSearch\Model\Search\ContentTypeTabs;

class ContentTypeTabsTest extends TestCase
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $filters = [];
    /** @var list<string> */
    private array $sortFields = [];
    private ContentTypeRepositoryInterface&MockObject $repository;

    /** @param array<int, string> $labels */
    private function type(string $code, bool $active, array $labels): ContentTypeInterface&MockObject
    {
        $type = $this->createMock(ContentTypeInterface::class);
        $type->method('getCode')->willReturn($code);
        $type->method('getIsActive')->willReturn($active);
        $type->method('getLabel')->willReturnCallback(static fn (int $storeId): string => $labels[$storeId] ?? $labels[0]);
        return $type;
    }

    /** @param list<ContentTypeInterface> $types */
    private function tabs(array $types): ContentTypeTabs
    {
        $results = $this->createMock(ContentTypeSearchResultsInterface::class);
        $results->method('getItems')->willReturn($types);
        $this->repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $this->repository->method('getList')->willReturn($results);

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function (string $field, $value) use (&$builder) {
            $this->filters[] = [$field, $value];
            return $builder;
        });
        $builder->method('addSortOrder')->willReturnCallback(function (SortOrder $order) use (&$builder) {
            $this->sortFields[] = $order->getField() . ' ' . $order->getDirection();
            return $builder;
        });
        $builder->method('create')->willReturn($this->createMock(SearchCriteriaInterface::class));
        $factory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $factory->method('create')->willReturn($builder);

        $field = '';
        $sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $sortOrderBuilder->method('setField')->willReturnCallback(function (string $name) use (&$field, &$sortOrderBuilder) {
            $field = $name;
            return $sortOrderBuilder;
        });
        $sortOrderBuilder->method('setAscendingDirection')->willReturnSelf();
        $sortOrderBuilder->method('create')->willReturnCallback(
            static function () use (&$field): SortOrder {
                return new SortOrder([SortOrder::FIELD => $field, SortOrder::DIRECTION => SortOrder::SORT_ASC]);
            }
        );
        return new ContentTypeTabs($this->repository, $factory, $sortOrderBuilder);
    }

    public function testActiveTypesComeInTabOrderWithTheStoreViewLabel(): void
    {
        $tabs = $this->tabs([
            $this->type('news', true, [0 => 'News', 2 => 'Noticias']),
            $this->type('help', true, [0 => 'Help']),
        ]);
        $this->assertSame(
            [['code' => 'news', 'label' => 'Noticias'], ['code' => 'help', 'label' => 'Help']],
            $tabs->forStore(2)
        );
        $this->assertSame([[ContentTypeInterface::IS_ACTIVE, 1]], $this->filters);
        $this->assertSame([ContentTypeInterface::SORT_ORDER . ' ASC', ContentTypeInterface::TYPE_ID . ' ASC'], $this->sortFields);
    }

    public function testTheListIsReadOncePerStoreView(): void
    {
        $tabs = $this->tabs([$this->type('news', true, [0 => 'News', 3 => 'Novidades'])]);
        $this->repository->expects($this->exactly(2))->method('getList');
        $tabs->forStore(2);
        $tabs->forStore(2);
        $this->assertSame([['code' => 'news', 'label' => 'Novidades']], $tabs->forStore(3));
    }

    public function testAnInactiveTypeIsNeverATab(): void
    {
        $tabs = $this->tabs([$this->type('news', true, [0 => 'News']), $this->type('off', false, [0 => 'Off'])]);
        $this->assertSame([['code' => 'news', 'label' => 'News']], $tabs->forStore(2));
    }
}
