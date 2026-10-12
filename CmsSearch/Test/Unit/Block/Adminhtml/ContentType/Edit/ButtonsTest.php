<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Block\Adminhtml\ContentType\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Block\Adminhtml\ContentType\Edit\DeleteButton;
use Thedevkitchen\CmsSearch\Block\Adminhtml\ContentType\Edit\SaveAndContinueButton;
use Thedevkitchen\CmsSearch\Block\Adminhtml\ContentType\Edit\SaveButton;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\AbstractContentType as Acl;

class ButtonsTest extends TestCase
{
    /**
     * @param list<string> $granted
     */
    private function make(string $class, int $typeId, array $granted): object
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn((string) $typeId);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://x.test/delete?a=1&b=\'2\'');
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(fn ($r): bool => in_array($r, $granted, true));
        return new $class($context, $auth);
    }

    public function testDeleteNeedsTheDeleteRightAndAnExistingType(): void
    {
        $this->assertSame([], $this->make(DeleteButton::class, 5, [Acl::RESOURCE_EDIT])->getButtonData());
        $this->assertSame([], $this->make(DeleteButton::class, 0, [Acl::RESOURCE_DELETE])->getButtonData());
        $data = $this->make(DeleteButton::class, 5, [Acl::RESOURCE_DELETE])->getButtonData();
        $this->assertStringStartsWith('deleteConfirm("', html_entity_decode($data['on_click']));
        $this->assertStringNotContainsString("'", $data['on_click']);
    }

    public function testSaveButtonsFollowCreateOrEditRight(): void
    {
        foreach ([SaveButton::class, SaveAndContinueButton::class] as $class) {
            $this->assertNotSame([], $this->make($class, 0, [Acl::RESOURCE_CREATE])->getButtonData());
            $this->assertSame([], $this->make($class, 0, [Acl::RESOURCE_EDIT])->getButtonData());
            $this->assertNotSame([], $this->make($class, 5, [Acl::RESOURCE_EDIT])->getButtonData());
            $this->assertSame([], $this->make($class, 5, [Acl::RESOURCE_CREATE])->getButtonData());
        }
    }
}
