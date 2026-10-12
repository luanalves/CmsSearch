<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\ContentType;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Exception\ContentTypeInUseException;
use Thedevkitchen\CmsSearch\Model\ContentType\BulkDeleter;

class BulkDeleterTest extends TestCase
{
    public function testDeletesFreeTypesAndReportsBlockedOnes(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('deleteById')->willReturnCallback(
            function (int $id): bool {
                if ($id === 2) {
                    throw new ContentTypeInUseException(__('Type 2 is in use'));
                }
                return true;
            }
        );

        $result = (new BulkDeleter($repository))->delete(['1', 2, '3']);

        $this->assertSame(2, $result['deleted']);
        $this->assertSame(['Type 2 is in use'], $result['blocked']);
    }

    public function testReturnsZeroForAnEmptySelection(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->expects($this->never())->method('deleteById');

        $this->assertSame(['deleted' => 0, 'blocked' => []], (new BulkDeleter($repository))->delete([]));
    }

    public function testDeletesEachDistinctIdOnlyOnce(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $seen = [];
        $repository->expects($this->exactly(2))->method('deleteById')->willReturnCallback(
            function (int $id) use (&$seen): bool {
                $seen[] = $id;
                return true;
            }
        );

        $result = (new BulkDeleter($repository))->delete([2, '2', 3]);

        $this->assertSame([2, 3], $seen);
        $this->assertSame(2, $result['deleted']);
    }

    public function testOtherExceptionsPropagate(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('deleteById')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(\RuntimeException::class);
        (new BulkDeleter($repository))->delete([1]);
    }

    public function testAbsorbsLocalizedExceptionsPerItemAndKeepsGoing(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('deleteById')->willReturnCallback(
            function (int $id): bool {
                if ($id === 1) {
                    throw new NoSuchEntityException(__('Type 1 is gone'));
                }
                if ($id === 2) {
                    throw new CouldNotDeleteException(__('Type 2 failed'));
                }
                return true;
            }
        );

        $result = (new BulkDeleter($repository))->delete([1, 2, 3]);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(['Type 1 is gone', 'Type 2 failed'], $result['blocked']);
    }
}
