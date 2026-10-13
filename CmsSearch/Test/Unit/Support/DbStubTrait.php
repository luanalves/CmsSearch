<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Support;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * ResourceConnection stub for the Plan 6 read/write models: fetchAll()/fetchCol()/fetchPairs() return the prepared
 * results in call order and their number is asserted exactly (no N+1). where() calls, join conditions and table names
 * are recorded; quoteInto() substitutes the value so composed OR conditions can be asserted. $this->connection is the
 * adapter mock, for write expectations (delete, insertMultiple, insertOnDuplicate, transactions).
 */
trait DbStubTrait
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $wheres = [];
    /** @var list<string> */
    private array $joins = [];
    /** @var list<string> */
    private array $orders = [];
    /** @var list<string> */
    private array $tables = [];
    /** @var list<int|null> limit() arguments, in call order */
    private array $limits = [];
    /** number of distinct() calls */
    private int $distinctCalls = 0;
    private AdapterInterface&MockObject $connection;

    /**
     * @param list<list<array<string, mixed>>> $fetchAll rows of each fetchAll(), in call order
     * @param list<list<mixed>> $fetchCol values of each fetchCol(), in call order
     * @param list<array<int|string, mixed>> $fetchPairs pairs of each fetchPairs(), in call order
     */
    private function db(array $fetchAll = [], array $fetchCol = [], array $fetchPairs = []): ResourceConnection
    {
        $select = $this->createMock(Select::class);
        foreach (['columns', 'group', 'union'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('distinct')->willReturnCallback(function () use ($select) {
            $this->distinctCalls++;
            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($count = null) use ($select) {
            $this->limits[] = $count === null ? null : (int) $count;
            return $select;
        });
        $select->method('order')->willReturnCallback(function ($spec) use ($select) {
            $this->orders[] = is_array($spec) ? implode(',', $spec) : (string) $spec;
            return $select;
        });
        $select->method('from')->willReturnCallback(function ($name) use ($select) {
            $this->tables[] = is_array($name) ? (string) reset($name) : (string) $name;
            return $select;
        });
        foreach (['join', 'joinInner', 'joinLeft'] as $method) {
            $select->method($method)->willReturnCallback(function ($name, $condition) use ($select) {
                $this->tables[] = is_array($name) ? (string) reset($name) : (string) $name;
                $this->joins[] = (string) $condition;
                return $select;
            });
        }
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->wheres[] = [(string) $condition, $value];
            return $select;
        });
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value): string => str_replace(
                '?',
                is_array($value) ? implode(',', $value) : "'" . $value . "'",
                $text
            )
        );
        foreach (['fetchAll' => $fetchAll, 'fetchCol' => $fetchCol, 'fetchPairs' => $fetchPairs] as $method => $results) {
            if ($results === []) {
                $this->connection->expects($this->never())->method($method);
            } else {
                $this->connection->expects($this->exactly(count($results)))->method($method)
                    ->willReturnOnConsecutiveCalls(...$results);
            }
        }
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    /**
     * @return list<mixed> values of the recorded where() calls whose condition is exactly $condition
     */
    private function whereValues(string $condition): array
    {
        $values = [];
        foreach ($this->wheres as [$recorded, $value]) {
            if ($recorded === $condition) {
                $values[] = $value;
            }
        }
        return $values;
    }
}
