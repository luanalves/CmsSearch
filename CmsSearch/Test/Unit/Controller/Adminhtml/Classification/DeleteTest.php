<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Controller\Adminhtml\Classification;

use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\AbstractClassification;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\Delete;
use Thedevkitchen\CmsSearch\Exception\ClassificationTermInUseException;
use Thedevkitchen\CmsSearch\Model\Input\StrictInput;
use Thedevkitchen\CmsSearch\Test\Unit\Support\AdminContextTrait;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ClassificationTermBuilder;

class DeleteTest extends TestCase
{
    use AdminContextTrait;

    private function controller(array $params, ClassificationTermRepositoryInterface $repository, ?LoggerInterface $logger = null): Delete
    {
        $this->setUpContext($params);
        $this->granted = [AbstractClassification::RESOURCE_MANAGE => true];
        return new Delete($this->context, $repository, new StrictInput(), $logger ?? $this->createMock(LoggerInterface::class));
    }

    public function testOnlyTheManageRightGrantsAccess(): void
    {
        $controller = $this->controller([], $this->createMock(ClassificationTermRepositoryInterface::class));
        $this->assertSame(
            [AbstractClassification::RESOURCE_MANAGE],
            $this->consultedResources($controller, AbstractClassification::RESOURCE_MANAGE)
        );
    }

    public function testWithoutTheManageRightNothingIsDeleted(): void
    {
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->expects($this->never())->method('delete');
        $controller = $this->controller(['term_id' => '7'], $repository);
        $this->granted = [];
        $this->messages->expects($this->once())->method('addErrorMessage');
        $controller->execute();
    }

    public function testInvalidIdDeletesNothing(): void
    {
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->expects($this->never())->method('delete');
        $controller = $this->controller(['term_id' => '7abc'], $repository);
        $this->messages->expects($this->once())->method('addErrorMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/index');
        $controller->execute();
    }

    public function testDeletesAndReturnsToTheTreeOfTheTermsWebsite(): void
    {
        $term = ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), 7, 3, 'tv');
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->method('getById')->with(7)->willReturn($term);
        $repository->expects($this->once())->method('delete')->with($term)->willReturn(true);
        $controller = $this->controller(['term_id' => '7'], $repository);
        $this->messages->expects($this->once())->method('addSuccessMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/index', ['website_id' => 3]);
        $controller->execute();
    }

    public function testATermInUseShowsTheReasonAndStaysOnTheForm(): void
    {
        $term = ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), 7, 3, 'tv');
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->method('getById')->willReturn($term);
        $repository->method('delete')->willThrowException(new ClassificationTermInUseException(__('it has 2 child term(s)')));
        $controller = $this->controller(['term_id' => '7'], $repository);
        $this->messages->expects($this->once())->method('addErrorMessage')->with('it has 2 child term(s)');
        $this->messages->expects($this->never())->method('addSuccessMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/edit', ['term_id' => 7]);
        $controller->execute();
    }

    public function testAMissingTermGoesBackToTheTree(): void
    {
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));
        $repository->expects($this->never())->method('delete');
        $controller = $this->controller(['term_id' => '7'], $repository);
        $this->messages->expects($this->once())->method('addErrorMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/index');
        $controller->execute();
    }

    public function testUnexpectedErrorsAreLoggedAndHidden(): void
    {
        $term = ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), 7, 3, 'tv');
        $repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $repository->method('getById')->willReturn($term);
        $repository->method('delete')->willThrowException(new \RuntimeException('SQLSTATE secret'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $controller = $this->controller(['term_id' => '7'], $repository, $logger);
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with($this->callback(fn ($message): bool => !str_contains((string) $message, 'SQLSTATE')));
        $controller->execute();
    }
}
