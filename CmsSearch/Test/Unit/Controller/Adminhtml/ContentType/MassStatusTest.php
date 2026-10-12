<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Controller\Adminhtml\ContentType;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\AbstractContentType;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\MassStatus;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\Collection;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\CollectionFactory;
use Thedevkitchen\CmsSearch\Test\Unit\Support\AdminContextTrait;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ContentTypeBuilder;

class MassStatusTest extends TestCase
{
    use AdminContextTrait;

    /** @param array<int|string> $ids */
    private function controller(array $ids, string $status, ContentTypeRepositoryInterface $repository): MassStatus
    {
        $this->setUpContext(['status' => $status]);
        $collection = $this->createMock(Collection::class);
        $collection->method('getAllIds')->willReturn($ids);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $filter = $this->createMock(Filter::class);
        $filter->method('getCollection')->with($collection)->willReturn($collection);
        return new MassStatus($this->context, $filter, $factory, $repository);
    }

    public function testOnlyTheEditRightGrantsAccess(): void
    {
        $controller = $this->controller([], '1', $this->createMock(ContentTypeRepositoryInterface::class));
        $this->assertSame(
            [AbstractContentType::RESOURCE_EDIT],
            $this->consultedResources($controller, AbstractContentType::RESOURCE_EDIT)
        );
        $this->granted = [AbstractContentType::RESOURCE_CREATE => true, AbstractContentType::RESOURCE_DELETE => true];
        $this->assertFalse((new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function statuses(): array
    {
        return ['disable' => ['0', false], 'enable' => ['1', true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function testAppliesTheRequestedStatusToEachTypeAndReportsTheCount(string $status, bool $expected): void
    {
        $types = [
            1 => ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 1, 'a')->setIsActive(!$expected),
            2 => ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 2, 'b')->setIsActive(!$expected),
        ];
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(fn (int $id) => $types[$id]);
        $saved = [];
        $repository->expects($this->exactly(2))->method('save')->willReturnCallback(
            function ($type) use (&$saved) {
                $saved[] = [$type->getTypeId(), $type->getIsActive()];
                return $type;
            }
        );
        $controller = $this->controller(['1', '2'], $status, $repository);
        $this->messages->expects($this->once())->method('addSuccessMessage')
            ->with($this->callback(fn ($m): bool => str_contains((string) $m, '2 record(s)')));
        $this->messages->expects($this->never())->method('addErrorMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/');

        $controller->execute();

        $this->assertSame([[1, $expected], [2, $expected]], $saved);
    }

    public function testReportsAnErrorPerFailedTypeAndCountsOnlyTheUpdatedOnes(): void
    {
        $good = ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 1, 'a');
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(
            fn (int $id) => $id === 1 ? $good : throw new NoSuchEntityException(__('Type %1 is gone', $id))
        );
        $repository->expects($this->once())->method('save')->willReturnArgument(0);
        $controller = $this->controller([1, 2, 3], '0', $repository);
        $this->messages->expects($this->once())->method('addSuccessMessage')
            ->with($this->callback(fn ($m): bool => str_contains((string) $m, '1 record(s)')));
        $this->messages->expects($this->exactly(2))->method('addErrorMessage');

        $controller->execute();
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidStatuses(): array
    {
        return ['missing' => [null], 'empty' => [''], 'two' => ['2'], 'text' => ['abc'], 'negative' => ['-1']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStatuses')]
    public function testRejectsAnyStatusOtherThanZeroOrOneWithoutChangingAnything(mixed $status): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->expects($this->never())->method('getById');
        $repository->expects($this->never())->method('save');
        $this->setUpContext(['status' => $status]);
        $controller = new MassStatus(
            $this->context,
            $this->createMock(Filter::class),
            $this->createMock(CollectionFactory::class),
            $repository
        );
        $this->messages->expects($this->once())->method('addErrorMessage');
        $this->messages->expects($this->never())->method('addSuccessMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/');

        $controller->execute();
    }
}
