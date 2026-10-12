<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Model\PageClassification;
use Thedevkitchen\CmsSearch\Model\PageClassification\AssignmentValidator;
use Thedevkitchen\CmsSearch\Model\PageClassificationRepository;
use Thedevkitchen\CmsSearch\Model\ResourceModel\PageClassification as LinkResource;

class PageClassificationRepositoryTest extends TestCase
{
    private LinkResource&MockObject $resource;
    private AssignmentValidator&MockObject $validator;
    private LoggerInterface&MockObject $logger;
    private PageClassificationRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(LinkResource::class);
        $this->validator = $this->createMock(AssignmentValidator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->repository = new PageClassificationRepository($this->resource, $this->validator, $this->logger);
    }

    public function testGetByPageId(): void
    {
        $this->resource->method('fetchRow')->with(7)->willReturn(['type_id' => 3, 'term_id' => 10]);
        $link = $this->repository->getByPageId(7);
        $this->assertSame([7, 3, 10], [$link->getPageId(), $link->getTypeId(), $link->getTermId()]);
    }

    public function testAPageWithoutLinkIsNotFound(): void
    {
        $this->resource->method('fetchRow')->willReturn(null);
        $this->expectException(NoSuchEntityException::class);
        $this->repository->getByPageId(7);
    }

    public function testAssignValidatesAgainstTheCurrentLinkThenReplaces(): void
    {
        $this->resource->method('fetchRow')->with(7)->willReturn(['type_id' => 3, 'term_id' => 10]);
        $this->validator->expects($this->once())->method('validate')->with(
            4,
            11,
            [2, 5],
            $this->callback(fn ($current): bool => $current instanceof PageClassification
                && $current->getTypeId() === 3 && $current->getTermId() === 10)
        );
        $this->resource->expects($this->once())->method('replace')->with(7, 4, 11);
        $this->resource->expects($this->never())->method('remove');
        $this->repository->assign(7, 4, 11, ['2', 5]);
    }

    public function testClearingBothRemovesTheLink(): void
    {
        $this->resource->method('fetchRow')->willReturn(null);
        $this->validator->expects($this->once())->method('validate')->with(null, null, [2], null);
        $this->resource->expects($this->once())->method('remove')->with(7);
        $this->resource->expects($this->never())->method('replace');
        $this->repository->assign(7, null, null, [2]);
    }

    public function testAnInvalidAssignmentWritesNothing(): void
    {
        $this->validator->method('validate')->willThrowException(new InputException(__('Select both')));
        $this->resource->expects($this->never())->method('replace');
        $this->resource->expects($this->never())->method('remove');
        $this->expectException(InputException::class);
        $this->repository->assign(7, 3, null, [2]);
    }

    public function testAnInvalidPageIdIsRejected(): void
    {
        $this->validator->expects($this->never())->method('validate');
        $this->expectException(InputException::class);
        $this->repository->assign(0, 3, 10, [2]);
    }

    public function testDatabaseErrorsAreLoggedAndHidden(): void
    {
        $this->resource->method('replace')->willThrowException(new \RuntimeException('SQLSTATE[23000] secret'));
        $this->logger->expects($this->once())->method('error');
        try {
            $this->repository->assign(7, 3, 10, [2]);
            $this->fail('expected CouldNotSaveException');
        } catch (CouldNotSaveException $exception) {
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }
    }

    public function testUnassignRemovesWithoutValidation(): void
    {
        $this->validator->expects($this->never())->method('validate');
        $this->resource->expects($this->once())->method('remove')->with(7);
        $this->repository->unassign(7);
    }
}
