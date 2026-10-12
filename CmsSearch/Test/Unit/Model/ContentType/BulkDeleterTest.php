<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\ContentType;

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
}
