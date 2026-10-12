<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Ui\Component\Listing\Column\ContentTypeActions;

class ContentTypeActionsTest extends TestCase
{
    public function testEscapesTheCodeInTheConfirmMessage(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('http://x/y');
        $context = $this->createMock(ContextInterface::class);
        $column = new ContentTypeActions(
            $context,
            $this->createMock(UiComponentFactory::class),
            $url,
            new Escaper(),
            [],
            ['name' => 'actions']
        );

        $source = $column->prepareDataSource(['data' => ['items' => [['type_id' => 1, 'code' => '<b>x</b>']]]]);

        $message = (string) $source['data']['items'][0]['actions']['delete']['confirm']['message'];
        $this->assertStringNotContainsString('<b>', $message);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $message);
    }
}
