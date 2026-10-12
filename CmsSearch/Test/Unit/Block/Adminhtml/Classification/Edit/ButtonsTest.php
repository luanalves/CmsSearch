<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Block\Adminhtml\Classification\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Block\Adminhtml\Classification\Edit\DeleteButton;
use Thedevkitchen\CmsSearch\Block\Adminhtml\Classification\Edit\SaveAndContinueButton;
use Thedevkitchen\CmsSearch\Block\Adminhtml\Classification\Edit\SaveButton;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\AbstractClassification as Acl;

class ButtonsTest extends TestCase
{
    /** @param list<string> $granted */
    private function make(string $class, int $termId, array $granted): object
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $name === 'term_id' ? (string) $termId : null);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://x.test/delete?a=1&b=\'2\'');
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(fn ($resource): bool => in_array($resource, $granted, true));
        return new $class($context, $auth);
    }

    public function testDeleteNeedsTheManageRightAndAnExistingTerm(): void
    {
        $this->assertSame([], $this->make(DeleteButton::class, 5, [Acl::RESOURCE_VIEW])->getButtonData());
        $this->assertSame([], $this->make(DeleteButton::class, 0, [Acl::RESOURCE_MANAGE])->getButtonData());
        $data = $this->make(DeleteButton::class, 5, [Acl::RESOURCE_MANAGE])->getButtonData();
        $this->assertStringStartsWith('deleteConfirm("', html_entity_decode($data['on_click']));
        $this->assertStringNotContainsString("'", $data['on_click']);
    }

    public function testSaveButtonsNeedTheManageRight(): void
    {
        foreach ([SaveButton::class, SaveAndContinueButton::class] as $class) {
            $this->assertSame([], $this->make($class, 5, [Acl::RESOURCE_VIEW])->getButtonData());
            $this->assertNotSame([], $this->make($class, 5, [Acl::RESOURCE_MANAGE])->getButtonData());
            $this->assertNotSame([], $this->make($class, 0, [Acl::RESOURCE_MANAGE])->getButtonData());
        }
    }
}
