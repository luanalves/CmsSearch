<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Controller\Adminhtml\ContentType;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterfaceFactory;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\AbstractContentType;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\Save;
use Thedevkitchen\CmsSearch\Model\ContentType\FormDataMapper;

class SaveTest extends TestCase
{
    private Http&MockObject $request;
    private AuthorizationInterface&MockObject $authorization;
    private ManagerInterface&MockObject $messages;
    private Redirect&MockObject $redirect;
    private ContentTypeRepositoryInterface&MockObject $repository;
    private ContentTypeInterfaceFactory&MockObject $factory;
    private DataPersistorInterface&MockObject $persistor;
    private LoggerInterface&MockObject $logger;
    /** @var array<string, bool> */
    private array $granted = [];

    protected function setUp(): void
    {
        $this->request = $this->createMock(Http::class);
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->authorization->method('isAllowed')->willReturnCallback(
            fn ($resource): bool => $this->granted[$resource] ?? false
        );
        $this->messages = $this->createMock(ManagerInterface::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getAuthorization')->willReturn($this->authorization);
        $context->method('getMessageManager')->willReturn($this->messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        $this->repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $this->factory = $this->createMock(ContentTypeInterfaceFactory::class);
        $this->persistor = $this->createMock(DataPersistorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->controller = new Save(
            $context,
            $this->repository,
            $this->factory,
            new FormDataMapper(),
            $this->persistor,
            $this->logger
        );
    }

    private Save $controller;

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     */
    private function givenRequest(array $post, array $query = []): void
    {
        $this->request->method('getPostValue')->willReturn($post);
        $this->request->method('getParam')->willReturnCallback(
            fn (string $name, $default = null) => $query[$name] ?? $default
        );
    }

    private function isAllowed(): bool
    {
        $method = new \ReflectionMethod($this->controller, '_isAllowed');
        return $method->invoke($this->controller);
    }

    public function testNewTypeNeedsTheCreateRight(): void
    {
        $this->givenRequest(['code' => 'news']);

        $this->granted = [AbstractContentType::RESOURCE_CREATE => true];
        $this->assertTrue($this->isAllowed());

        $this->granted = [AbstractContentType::RESOURCE_EDIT => true];
        $this->assertFalse($this->isAllowed());
    }

    public function testIdInThePostBodyNeedsTheEditRight(): void
    {
        $this->givenRequest(['type_id' => '5']);

        $this->granted = [AbstractContentType::RESOURCE_CREATE => true];
        $this->assertFalse($this->isAllowed());

        $this->granted = [AbstractContentType::RESOURCE_EDIT => true];
        $this->assertTrue($this->isAllowed());
    }

    public function testMismatchedBodyAndQueryIdStillNeedsTheEditRight(): void
    {
        $this->givenRequest(['type_id' => '5'], ['type_id' => '0']);

        $this->granted = [AbstractContentType::RESOURCE_CREATE => true];
        $this->assertFalse($this->isAllowed());
    }

    public function testCreateOnlyUserCannotOverwriteAnExistingTypeViaExecute(): void
    {
        $this->givenRequest(['type_id' => '5', 'default_label' => 'Hacked']);
        $this->granted = [AbstractContentType::RESOURCE_CREATE => true];
        $this->repository->expects($this->never())->method('getById');
        $this->repository->expects($this->never())->method('save');
        $this->messages->expects($this->once())->method('addErrorMessage');

        $this->controller->execute();
    }

    public function testPostedCodeIsIgnoredForAnExistingType(): void
    {
        $this->givenRequest(['type_id' => '5', 'code' => 'hacked', 'default_label' => 'News', 'is_active' => '1']);
        $this->granted = [AbstractContentType::RESOURCE_EDIT => true];
        $type = $this->createMock(ContentTypeInterface::class);
        $type->method('getTypeId')->willReturn(5);
        $type->method('setSortOrder')->willReturnSelf();
        $type->method('setIsActive')->willReturnSelf();
        $type->method('setLabels')->willReturnSelf();
        $type->expects($this->never())->method('setCode');
        $this->repository->method('getById')->with(5)->willReturn($type);
        $this->repository->expects($this->once())->method('save')->willReturn($type);
        $this->factory->expects($this->never())->method('create');

        $this->controller->execute();
    }

    public function testCreatesANewTypeWithTheTrimmedCode(): void
    {
        $this->givenRequest(['code' => ' news ', 'default_label' => 'News']);
        $this->granted = [AbstractContentType::RESOURCE_CREATE => true];
        $type = $this->createMock(ContentTypeInterface::class);
        $type->method('getTypeId')->willReturn(9);
        $type->method('setSortOrder')->willReturnSelf();
        $type->method('setIsActive')->willReturnSelf();
        $type->method('setLabels')->willReturnSelf();
        $type->expects($this->once())->method('setCode')->with('news');
        $this->factory->method('create')->willReturn($type);
        $this->repository->method('save')->willReturn($type);
        $this->persistor->expects($this->once())->method('clear');
        $this->messages->expects($this->once())->method('addSuccessMessage');

        $this->controller->execute();
    }

    public function testLocalizedExceptionKeepsTheFormDataAndShowsItsMessage(): void
    {
        $post = ['code' => 'Bad Code', 'default_label' => 'x'];
        $this->givenRequest($post);
        $this->granted = [AbstractContentType::RESOURCE_CREATE => true];
        $type = $this->createMock(ContentTypeInterface::class);
        $type->method('setSortOrder')->willReturnSelf();
        $type->method('setIsActive')->willReturnSelf();
        $type->method('setLabels')->willReturnSelf();
        $this->factory->method('create')->willReturn($type);
        $this->repository->method('save')->willThrowException(new LocalizedException(__('Invalid code')));
        $this->messages->expects($this->once())->method('addErrorMessage')->with('Invalid code');
        $this->persistor->expects($this->once())->method('set')
            ->with(Save::PERSISTOR_KEY, $post);
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/new');

        $this->controller->execute();
    }

    public function testUnexpectedExceptionIsLoggedHiddenAndKeepsTheFormData(): void
    {
        $post = ['type_id' => '5', 'default_label' => 'x'];
        $this->givenRequest($post);
        $this->granted = [AbstractContentType::RESOURCE_EDIT => true];
        $this->repository->method('getById')->willThrowException(new \RuntimeException('SQLSTATE secret'));
        $this->logger->expects($this->once())->method('error');
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with($this->callback(fn ($m): bool => !str_contains((string) $m, 'SQLSTATE')));
        $this->persistor->expects($this->once())->method('set')->with(Save::PERSISTOR_KEY, $post);
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/edit', ['type_id' => 5]);

        $this->controller->execute();
    }
}
