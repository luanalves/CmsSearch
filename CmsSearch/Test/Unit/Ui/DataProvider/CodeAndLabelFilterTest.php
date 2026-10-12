<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Ui\DataProvider\ContentType\CodeAndLabelFilter;

class CodeAndLabelFilterTest extends TestCase
{
    /** @return array{0: SearchResult, 1: \ArrayObject} */
    private function collection(): array
    {
        $wheres = new \ArrayObject();
        $select = $this->createMock(Select::class);
        $select->method('where')->willReturnCallback(function (string $sql) use ($wheres, $select) {
            $wheres->append($sql);
            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quote')->willReturnCallback(fn ($v) => "'" . $v . "'");
        $collection = $this->createMock(SearchResult::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($select);
        return [$collection, $wheres];
    }

    private function filter(string $value): Filter
    {
        $filter = $this->createMock(Filter::class);
        $filter->method('getValue')->willReturn($value);
        return $filter;
    }

    public function testMatchesCodeOrDefaultLabel(): void
    {
        [$collection, $wheres] = $this->collection();
        (new CodeAndLabelFilter())->apply($collection, $this->filter('news'));
        $this->assertSame(["(main_table.code LIKE '%news%' OR lbl.label LIKE '%news%')"], $wheres->getArrayCopy());
    }

    public function testEscapesLikeWildcards(): void
    {
        [$collection, $wheres] = $this->collection();
        (new CodeAndLabelFilter())->apply($collection, $this->filter('a_b%c'));
        $this->assertStringContainsString("'%a\\_b\\%c%'", $wheres[0]);
    }

    public function testBlankKeywordAddsNoCondition(): void
    {
        [$collection, $wheres] = $this->collection();
        (new CodeAndLabelFilter())->apply($collection, $this->filter(' % '));
        $this->assertSame([], $wheres->getArrayCopy());
    }
}
