<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Source;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterface;
use Thedevkitchen\CmsSearch\Model\ContentType;
use Thedevkitchen\CmsSearch\Model\Source\ContentTypeOptions;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ContentTypeBuilder;

class ContentTypeOptionsTest extends TestCase
{
    private function type(int $id, string $code, array $labels): ContentType
    {
        $type = ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), $id, $code, $labels);
        return $type->setIsActive(true);
    }

    public function testListsOnlyActiveTypesUsingTheDefaultLabel(): void
    {
        $criteria = $this->createMock(SearchCriteria::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->expects($this->once())->method('addFilter')->with('is_active', 1)->willReturnSelf();
        $added = [];
        $builder->expects($this->exactly(2))->method('addSortOrder')->willReturnCallback(
            function (SortOrder $order) use (&$added, $builder) {
                $added[] = $order;
                return $builder;
            }
        );
        $builder->method('create')->willReturn($criteria);

        $fields = [];
        $created = [];
        $sortBuilder = $this->createMock(SortOrderBuilder::class);
        $sortBuilder->method('setField')->willReturnCallback(
            function (string $field) use (&$fields, $sortBuilder) {
                $fields[] = $field;
                return $sortBuilder;
            }
        );
        $sortBuilder->expects($this->exactly(2))->method('setAscendingDirection')->willReturnSelf();
        $sortBuilder->method('create')->willReturnCallback(
            function () use (&$created) {
                return $created[] = $this->createMock(SortOrder::class);
            }
        );

        $results = $this->createMock(ContentTypeSearchResultsInterface::class);
        $results->method('getItems')->willReturn([
            $this->type(4, 'news', [0 => 'News', 2 => 'Noticias']),
            $this->type(9, 'tv', [0 => 'TV']),
        ]);
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->expects($this->once())->method('getList')->with($criteria)->willReturn($results);

        $options = (new ContentTypeOptions($repository, $builder, $sortBuilder))->toOptionArray();

        $this->assertSame(['sort_order', 'type_id'], $fields);
        $this->assertCount(2, $created);
        $this->assertSame($created, $added);
        $this->assertSame([
            ['value' => 4, 'label' => 'News'],
            ['value' => 9, 'label' => 'TV'],
        ], $options);
    }
}
