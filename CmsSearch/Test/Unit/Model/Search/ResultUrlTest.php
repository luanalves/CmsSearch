<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Search\ResultUrl;

class ResultUrlTest extends TestCase
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $calls = [];

    private function url(bool $secure = true): ResultUrl
    {
        $builder = $this->createMock(UrlInterface::class);
        $builder->method('getUrl')->willReturnCallback(function (string $route, array $params): string {
            $this->calls[] = [$route, $params];
            return 'https://s.test/catalogsearch/result/?' . http_build_query($params['_query']);
        });
        $request = $this->createMock(RequestInterface::class);
        $request->method('isSecure')->willReturn($secure);
        return new ResultUrl($builder, $request);
    }

    public function testTheProductsTabKeepsTheNativeCacheableUrl(): void
    {
        $this->assertSame('https://s.test/catalogsearch/result/?q=milho', $this->url()->build('milho', 'produtos', 'agro', 3));
        $this->assertSame('https://s.test/catalogsearch/result/?q=milho', $this->url()->build('milho'));
        $this->assertSame(['catalogsearch/result', ['_query' => ['q' => 'milho'], '_secure' => true]], $this->calls[0]);
    }

    public function testCmsTabUrlsCarryTabTermAndPageInThisOrder(): void
    {
        $this->assertSame(
            'https://s.test/catalogsearch/result/?q=milho+verde&tab=news&cls=agro&p=3',
            $this->url()->build('milho verde', 'news', 'agro', 3)
        );
        $this->assertSame('https://s.test/catalogsearch/result/?q=milho&tab=news', $this->url()->build('milho', 'news', null, 1));
    }

    public function testTheSecureFlagFollowsTheRequest(): void
    {
        $this->url(false)->build('milho', 'news');
        $this->assertFalse($this->calls[0][1]['_secure']);
    }
}
