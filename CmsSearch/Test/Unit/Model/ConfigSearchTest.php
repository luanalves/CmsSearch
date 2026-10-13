<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Config;

class ConfigSearchTest extends TestCase
{
    /** @var array<string, mixed> "path|scope|scope id" => stored value */
    private array $values = [];

    private function config(): Config
    {
        $key = static fn (string $path, string $scope, $scopeId): string => $path . '|' . $scope . '|' . (string) $scopeId;
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path, string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeId = null) => $this->values[$key($path, $scope, $scopeId)] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn (string $path, string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeId = null): bool => (bool) ($this->values[$key($path, $scope, $scopeId)] ?? false)
        );
        return new Config($scopeConfig);
    }

    public function testTheModuleSwitchIsReadPerStoreView(): void
    {
        $this->values[Config::XML_PATH_ENABLED . '|store|2'] = '1';
        $this->values[Config::XML_PATH_ENABLED . '|store|5'] = '0';
        $config = $this->config();
        $this->assertTrue($config->isEnabled(2));
        $this->assertFalse($config->isEnabled(5));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function pageSizes(): array
    {
        return [
            'default' => ['12', 12],
            'lower bound' => ['1', 1],
            'upper bound' => ['60', 60],
            'trimmed' => [' 7 ', 7],
            'integer' => [5, 5],
            'zero' => ['0', 12],
            'above the bound' => ['61', 12],
            'text' => ['abc', 12],
            'decimal' => ['2.5', 12],
            'missing' => [null, 12],
        ];
    }

    #[DataProvider('pageSizes')]
    public function testThePageSizeIsBoundedPerStoreView(mixed $stored, int $expected): void
    {
        $this->values[Config::XML_PATH_PAGE_SIZE . '|store|3'] = $stored;
        $this->assertSame($expected, $this->config()->getPageSize(3));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function summaryLengths(): array
    {
        return [
            'default' => ['200', 200],
            'lower bound' => ['50', 50],
            'upper bound' => ['1000', 1000],
            'below the bound' => ['49', 200],
            'above the bound' => ['1001', 200],
        ];
    }

    #[DataProvider('summaryLengths')]
    public function testTheSummaryLengthIsBoundedPerStoreView(mixed $stored, int $expected): void
    {
        $this->values[Config::XML_PATH_SUMMARY_LENGTH . '|store|4'] = $stored;
        $this->assertSame($expected, $this->config()->getSummaryLength(4));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function timeouts(): array
    {
        return [
            'default' => ['1500', 1500],
            'lower bound' => ['1', 1],
            'upper bound' => ['10000', 10000],
            'zero' => ['0', 1500],
            'above the bound' => ['10001', 1500],
        ];
    }

    #[DataProvider('timeouts')]
    public function testTheSearchTimeoutIsAGlobalBoundedSetting(mixed $stored, int $expected): void
    {
        $this->values[Config::XML_PATH_SEARCH_TIMEOUT_MS . '|default|'] = $stored;
        $this->assertSame($expected, $this->config()->getSearchTimeoutMs());
    }

    public function testConfiguredTabLabelsAreTrimmedAndBounded(): void
    {
        $this->values[Config::XML_PATH_PRODUCTS_LABEL . '|store|2'] = '  Produtos  ';
        $this->values[Config::XML_PATH_CATEGORIES_LABEL . '|store|2'] = str_repeat('é', 70);
        $config = $this->config();
        $this->assertSame('Produtos', $config->getProductsLabel(2));
        $this->assertSame(str_repeat('é', Config::MAX_LABEL_LENGTH), $config->getCategoriesLabel(2));
    }

    public function testEmptyTabLabelsFallBackToTheTranslatableNames(): void
    {
        $this->values[Config::XML_PATH_PRODUCTS_LABEL . '|store|2'] = '   ';
        $config = $this->config();
        $this->assertSame('Products', $config->getProductsLabel(2));
        $this->assertSame('Categories', $config->getCategoriesLabel(2));
    }
}
