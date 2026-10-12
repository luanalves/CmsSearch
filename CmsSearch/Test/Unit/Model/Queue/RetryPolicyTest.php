<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Queue\RetryPolicy;

class RetryPolicyTest extends TestCase
{
    private function policy(int $max): RetryPolicy
    {
        $config = $this->createMock(Config::class);
        $config->method('getMaxAttempts')->willReturn($max);
        return new RetryPolicy($config);
    }

    /** @return array<string, array{0: int, 1: int, 2: bool}> */
    public static function cases(): array
    {
        return [
            'first try of 3 failed' => [3, 0, true],
            'second try of 3 failed' => [3, 1, true],
            'third try of 3 failed' => [3, 2, false],
            'beyond the cap' => [3, 3, false],
            'cap of 1' => [1, 0, false],
            'ninth try of 10 failed' => [10, 8, true],
            'tenth try of 10 failed' => [10, 9, false],
        ];
    }

    #[DataProvider('cases')]
    public function testShouldRetry(int $max, int $attempt, bool $expected): void
    {
        $this->assertSame($expected, $this->policy($max)->shouldRetry($attempt));
    }

    public function testTheTotalNumberOfTriesEqualsTheCap(): void
    {
        foreach (range(1, 10) as $max) {
            $policy = $this->policy($max);
            $tries = 0;
            $attempt = 0;
            do {
                $tries++;
                $retry = $policy->shouldRetry($attempt);
                $attempt++;
            } while ($retry && $tries < 50);
            $this->assertSame($max, $tries, "cap $max");
        }
    }
}
