<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Support;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Mocked backend Action context shared by the controller tests.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait AdminContextTrait
{
    private Http&MockObject $request;
    private ManagerInterface&MockObject $messages;
    private Redirect&MockObject $redirect;
    private Context&MockObject $context;
    /** @var array<string, bool> */
    private array $granted = [];
    /** @var string[] resources asked to the ACL */
    private array $asked = [];

    /**
     * @param array<string, mixed> $params
     */
    private function setUpContext(array $params = []): void
    {
        $this->request = $this->createMock(Http::class);
        $this->request->method('getParam')->willReturnCallback(
            fn (string $name, $default = null) => $params[$name] ?? $default
        );
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(function ($resource): bool {
            $this->asked[] = $resource;
            return $this->granted[$resource] ?? false;
        });
        $this->messages = $this->createMock(ManagerInterface::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);
        $this->context->method('getAuthorization')->willReturn($authorization);
        $this->context->method('getMessageManager')->willReturn($this->messages);
        $this->context->method('getResultRedirectFactory')->willReturn($redirectFactory);
    }

    /** Asks the controller's own ACL check and returns the resources it consulted. */
    private function consultedResources(object $controller, string $grant): array
    {
        $this->granted = [$grant => true];
        $this->asked = [];
        $method = new \ReflectionMethod($controller, '_isAllowed');
        $this->assertTrue($method->invoke($controller));
        return $this->asked;
    }
}
