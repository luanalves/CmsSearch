<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Support;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * ResourceConnection stub for read models: each fetchAll()/fetchCol() returns the next prepared result and the number
 * of queries is asserted exactly (no N+1). Every where() call is recorded in $this->wheres as [condition, value].
 */
trait ResourceConnectionStubTrait
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $wheres = [];

    /**
     * @param list<list<array<string, mixed>>> $fetchAll rows of each fetchAll(), in call order
     * @param list<list<mixed>> $fetchCol values of each fetchCol(), in call order
     */
    private function resourceReturning(array $fetchAll = [], array $fetchCol = []): ResourceConnection
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'columns', 'distinct', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->wheres[] = [(string) $condition, $value];
            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        if ($fetchAll === []) {
            $connection->expects($this->never())->method('fetchAll');
        } else {
            $connection->expects($this->exactly(count($fetchAll)))->method('fetchAll')
                ->willReturnOnConsecutiveCalls(...$fetchAll);
        }
        if ($fetchCol === []) {
            $connection->expects($this->never())->method('fetchCol');
        } else {
            $connection->expects($this->exactly(count($fetchCol)))->method('fetchCol')
                ->willReturnOnConsecutiveCalls(...$fetchCol);
        }
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }
}
