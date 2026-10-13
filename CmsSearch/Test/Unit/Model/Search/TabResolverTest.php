<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\TabResolver;

class TabResolverTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: string}> */
    public static function tabs(): array
    {
        return [
            'content type code' => ['noticias', 'noticias'],
            'digits and underscore' => ['news_2', 'news_2'],
            'products' => ['produtos', 'produtos'],
            'empty' => ['', TabResolver::PRODUCTS],
            'accent' => ['notícias', TabResolver::PRODUCTS],
            'space' => ['a b', TabResolver::PRODUCTS],
            'too long' => [str_repeat('a', 65), TabResolver::PRODUCTS],
            'array' => [['news'], TabResolver::PRODUCTS],
            'missing' => [null, TabResolver::PRODUCTS],
            'integer' => [5, TabResolver::PRODUCTS],
            'path' => ['../etc', TabResolver::PRODUCTS],
            'trailing newline' => ["news\n", TabResolver::PRODUCTS],
            'nul byte' => ["news\0", TabResolver::PRODUCTS],
            'leading nul' => ["\0news", TabResolver::PRODUCTS],
            'percent encoded nul' => ['news%00', TabResolver::PRODUCTS],
            'uppercase' => ['News', TabResolver::PRODUCTS],
            'fullwidth letters' => ['ｎｅｗｓ', TabResolver::PRODUCTS],
            'zero width space' => ["news\u{200B}", TabResolver::PRODUCTS],
            'combining accent' => ["news\u{0301}", TabResolver::PRODUCTS],
            'sql looking' => ["x' OR '1'='1", TabResolver::PRODUCTS],
            'markup' => ['<script>', TabResolver::PRODUCTS],
            'invalid utf8' => ["\xff\xfe", TabResolver::PRODUCTS],
            'exactly 64' => [str_repeat('a', 64), 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
            'bool' => [true, TabResolver::PRODUCTS],
            'float' => [1.5, TabResolver::PRODUCTS],
        ];
    }

    #[DataProvider('tabs')]
    public function testTheTabParameterIsACodeOrTheProductsTab(mixed $raw, string $expected): void
    {
        $this->assertSame($expected, (new TabResolver())->readTab($raw));
    }

    /** @return array<string, array{0: mixed, 1: ?string}> */
    public static function termCodes(): array
    {
        return [
            'code' => ['agro', 'agro'],
            'empty' => ['', null],
            'uppercase' => ['Agro', null],
            'array' => [['agro'], null],
            'missing' => [null, null],
            'trailing newline' => ["agro\n", null],
            'nul byte' => ["agro\0", null],
            'percent encoded nul' => ['agro%00', null],
            'too long' => [str_repeat('a', 65), null],
            'exactly 64' => [str_repeat('a', 64), str_repeat('a', 64)],
            'space' => ['a b', null],
            'accent' => ['ágro', null],
            'path' => ['../x', null],
            'integer' => [7, null],
        ];
    }

    #[DataProvider('termCodes')]
    public function testTheTermParameterIsACodeOrNothing(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, (new TabResolver())->readTermCode($raw));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function pages(): array
    {
        return [
            'first' => ['1', 1],
            'twenty-five' => ['25', 25],
            'four digits' => ['9999', 9999],
            'zero' => ['0', 1],
            'negative' => ['-1', 1],
            'five digits' => ['10000', 1],
            'text' => ['abc', 1],
            'decimal' => ['1.5', 1],
            'array' => [['2'], 1],
            'missing' => [null, 1],
            'leading zero' => ['02', 1],
            'trailing newline' => ["2\n", 1],
            'nul byte' => ["2\0", 1],
            'percent encoded nul' => ['2%00', 1],
            'leading space' => [' 2', 1],
            'plus sign' => ['+2', 1],
            'exponent' => ['1e3', 1],
            'hex' => ['0x10', 1],
            'fullwidth digit' => ['２', 1],
            'huge' => ['99999999999999999999', 1],
            'empty' => ['', 1],
            'integer type' => [2, 1],
            'sql looking' => ['1; DROP TABLE x', 1],
        ];
    }

    #[DataProvider('pages')]
    public function testThePageParameterIsAPositiveNumberOrOne(mixed $raw, int $expected): void
    {
        $this->assertSame($expected, (new TabResolver())->readPage($raw));
    }

    /** @return list<Tab> */
    private function available(): array
    {
        return [
            new Tab('produtos', Tab::KIND_PRODUCTS, 'Produtos', null),
            new Tab('news', Tab::KIND_CONTENT, 'News', 3),
        ];
    }

    public function testAnAvailableTabIsSelected(): void
    {
        $this->assertSame('news', (new TabResolver())->resolve('news', $this->available())->getCode());
    }

    public function testTheProductsTabIsFoundWhateverItsPosition(): void
    {
        $tabs = [
            new Tab('news', Tab::KIND_CONTENT, 'News', 3),
            new Tab('produtos', Tab::KIND_PRODUCTS, 'Produtos', null),
        ];
        $this->assertSame('produtos', (new TabResolver())->resolve('help', $tabs)->getCode());
        $this->assertSame('news', (new TabResolver())->resolve('news', $tabs)->getCode());
    }

    public function testAnUnavailableTabFallsBackToProducts(): void
    {
        $this->assertSame('produtos', (new TabResolver())->resolve('help', $this->available())->getCode());
        $this->assertSame('produtos', (new TabResolver())->resolve('categorias', $this->available())->getCode());
    }

    public function testATabListWithoutProductsIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TabResolver())->resolve('news', [new Tab('news', Tab::KIND_CONTENT, 'News', 3)]);
    }

    /** @return array<string, array{0: int, 1: int, 2: int}> */
    public static function clamps(): array
    {
        return [
            'first page' => [1, 12, 1],
            'last page of the window' => [833, 12, 833],
            'past the window' => [834, 12, 833],
            'big pages' => [500, 60, 166],
            'zero' => [0, 12, 1],
            'negative' => [-5, 12, 1],
            'huge' => [PHP_INT_MAX, 12, 833],
            'page size zero' => [3, 0, 3],
            'page size negative' => [3, -4, 3],
            'page size one' => [20000, 1, 10000],
            'page size above window' => [5, 20000, 1],
            'exact division' => [1000, 10, 1000],
            'past exact division' => [1001, 10, 1000],
        ];
    }

    #[DataProvider('clamps')]
    public function testThePageStaysInsideTheResultWindow(int $page, int $pageSize, int $expected): void
    {
        $this->assertSame($expected, (new TabResolver())->clampPage($page, $pageSize));
    }

    public function testTheFixedTabCodesAreReserved(): void
    {
        $resolver = new TabResolver();
        $this->assertTrue($resolver->isReserved('produtos'));
        $this->assertTrue($resolver->isReserved('categorias'));
        $this->assertFalse($resolver->isReserved('news'));
    }
}
