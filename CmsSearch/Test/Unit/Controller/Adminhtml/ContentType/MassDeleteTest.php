<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Controller\Adminhtml\ContentType;

use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\AbstractContentType;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\MassDelete;
use Thedevkitchen\CmsSearch\Exception\ContentTypeInUseException;
use Thedevkitchen\CmsSearch\Model\ContentType\BulkDeleter;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\Collection;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\CollectionFactory;
use Thedevkitchen\CmsSearch\Test\Unit\Support\AdminContextTrait;

class MassDeleteTest extends TestCase
{
    use AdminContextTrait;

    /** @param array<int|string> $ids */
    private function controller(array $ids, ContentTypeRepositoryInterface $repository): MassDelete
    {
        $this->setUpContext();
        $collection = $this->createMock(Collection::class);
        $collection->method('getAllIds')->willReturn($ids);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $filter = $this->createMock(Filter::class);
        $filter->method('getCollection')->with($collection)->willReturn($collection);
        return new MassDelete($this->context, $filter, $factory, new BulkDeleter($repository));
    }

    public function testOnlyTheDeleteRightGrantsAccess(): void
    {
        $controller = $this->controller([], $this->createMock(ContentTypeRepositoryInterface::class));
        $this->assertSame(
            [AbstractContentType::RESOURCE_DELETE],
            $this->consultedResources($controller, AbstractContentType::RESOURCE_DELETE)
        );
        $this->granted = [AbstractContentType::RESOURCE_EDIT => true];
        $this->assertFalse((new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller));
    }

    public function testReportsTheDeletedCountAndOneErrorPerBlockedType(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $deleted = [];
        $repository->method('deleteById')->willReturnCallback(
            function (int $id) use (&$deleted): bool {
                if ($id === 2) {
                    throw new ContentTypeInUseException(__('Type 2 cannot be deleted'));
                }
                if ($id === 4) {
                    throw new ContentTypeInUseException(__('Type 4 cannot be deleted'));
                }
                $deleted[] = $id;
                return true;
            }
        );
        $controller = $this->controller(['1', '2', '3', '4'], $repository);
        $this->messages->expects($this->once())->method('addSuccessMessage')
            ->with($this->callback(fn ($m): bool => str_contains((string) $m, '2 record(s)')));
        $errors = [];
        $this->messages->expects($this->exactly(2))->method('addErrorMessage')
            ->willReturnCallback(function ($message) use (&$errors) {
                $errors[] = (string) $message;
                return $this->messages;
            });
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/');

        $controller->execute();

        $this->assertSame([1, 3], $deleted);
        $this->assertSame(['Type 2 cannot be deleted', 'Type 4 cannot be deleted'], $errors);
    }

    public function testNoSuccessMessageWhenEverythingIsBlocked(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('deleteById')->willThrowException(new ContentTypeInUseException(__('blocked')));
        $controller = $this->controller([5], $repository);
        $this->messages->expects($this->never())->method('addSuccessMessage');
        $this->messages->expects($this->once())->method('addErrorMessage')->with('blocked');

        $controller->execute();
    }
}
