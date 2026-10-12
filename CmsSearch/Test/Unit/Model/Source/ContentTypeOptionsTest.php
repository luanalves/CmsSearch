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
        $builder->expects($this->exactly(2))->method('addSortOrder')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);

        $sortOrder = $this->createMock(SortOrder::class);
        $sortBuilder = $this->createMock(SortOrderBuilder::class);
        $sortBuilder->method('setField')->willReturnSelf();
        $sortBuilder->method('setAscendingDirection')->willReturnSelf();
        $sortBuilder->method('create')->willReturn($sortOrder);

        $results = $this->createMock(ContentTypeSearchResultsInterface::class);
        $results->method('getItems')->willReturn([
            $this->type(4, 'news', [0 => 'News', 2 => 'Noticias']),
            $this->type(9, 'tv', [0 => 'TV']),
        ]);
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->expects($this->once())->method('getList')->with($criteria)->willReturn($results);

        $options = (new ContentTypeOptions($repository, $builder, $sortBuilder))->toOptionArray();

        $this->assertSame([
            ['value' => 4, 'label' => 'News'],
            ['value' => 9, 'label' => 'TV'],
        ], $options);
    }
}
