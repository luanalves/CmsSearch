<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Config;

class ConfigTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: int}> */
    public static function values(): array
    {
        return [
            'default 3' => ['3', 3],
            'lower bound' => ['1', 1],
            'upper bound' => ['10', 10],
            'spaces trimmed' => [' 7 ', 7],
            'leading zero' => ['05', 5],
            'integer value' => [4, 4],
            'zero' => ['0', 3],
            'above the bound' => ['11', 3],
            'negative' => ['-1', 3],
            'text' => ['abc', 3],
            'decimal' => ['2.5', 3],
            'empty' => ['', 3],
            'missing' => [null, 3],
            'three digits' => ['100', 3],
        ];
    }

    #[DataProvider('values')]
    public function testMaxAttemptsFallsBackToThreeForUnusableValues(mixed $stored, int $expected): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);
        $this->assertSame($expected, (new Config($scopeConfig))->getMaxAttempts());
    }

    public function testReadsTheGlobalPath(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())->method('getValue')
            ->with('thedevkitchen_cmssearch/queue/max_attempts')->willReturn('2');
        $this->assertSame(2, (new Config($scopeConfig))->getMaxAttempts());
    }
}
