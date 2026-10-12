<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Controller\Adminhtml\ContentType;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\AbstractContentType;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\Delete;
use Thedevkitchen\CmsSearch\Exception\ContentTypeInUseException;
use Thedevkitchen\CmsSearch\Test\Unit\Support\AdminContextTrait;

class DeleteTest extends TestCase
{
    use AdminContextTrait;

    private function controller(array $params, ContentTypeRepositoryInterface $repository): Delete
    {
        $this->setUpContext($params);
        return new Delete($this->context, $repository);
    }

    public function testOnlyTheDeleteRightGrantsAccess(): void
    {
        $controller = $this->controller([], $this->createMock(ContentTypeRepositoryInterface::class));
        $this->assertSame(
            [AbstractContentType::RESOURCE_DELETE],
            $this->consultedResources($controller, AbstractContentType::RESOURCE_DELETE)
        );
        $this->granted = [AbstractContentType::RESOURCE_EDIT => true, AbstractContentType::RESOURCE_VIEW => true];
        $this->assertFalse((new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller));
    }

    public function testInvalidIdShowsAnErrorAndDeletesNothing(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->expects($this->never())->method('deleteById');
        $controller = $this->controller(['type_id' => 'abc'], $repository);
        $this->messages->expects($this->once())->method('addErrorMessage');
        $this->messages->expects($this->never())->method('addSuccessMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/');

        $controller->execute();
    }

    public function testDeletesAndRedirectsToTheGrid(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->expects($this->once())->method('deleteById')->with(7)->willReturn(true);
        $controller = $this->controller(['type_id' => '7'], $repository);
        $this->messages->expects($this->once())->method('addSuccessMessage');
        $this->messages->expects($this->never())->method('addErrorMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/');

        $controller->execute();
    }

    public function testTypeInUseShowsTheMessageAndGoesBackToEdit(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('deleteById')->willThrowException(
            new ContentTypeInUseException(__('cannot be deleted: 1 CMS page(s)'))
        );
        $controller = $this->controller(['type_id' => '7'], $repository);
        $this->messages->expects($this->once())->method('addErrorMessage')->with('cannot be deleted: 1 CMS page(s)');
        $this->messages->expects($this->never())->method('addSuccessMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/edit', ['type_id' => 7]);

        $controller->execute();
    }

    public function testAnyLocalizedExceptionIsReportedTheSameWay(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('deleteById')->willThrowException(new LocalizedException(__('Gone')));
        $controller = $this->controller(['type_id' => '3'], $repository);
        $this->messages->expects($this->once())->method('addErrorMessage')->with('Gone');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/edit', ['type_id' => 3]);

        $controller->execute();
    }
}
