<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Config\Backend\MaxAttempts;

class MaxAttemptsTest extends TestCase
{
    private function model(string $value): MaxAttempts
    {
        $model = (new ObjectManager($this))->getObject(MaxAttempts::class);
        $model->setValue($value);
        return $model;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function valid(): array
    {
        return [
            'one' => ['1', '1'],
            'three' => ['3', '3'],
            'ten' => ['10', '10'],
            'trimmed' => [' 7 ', '7'],
            'leading zero' => ['05', '5'],
        ];
    }

    #[DataProvider('valid')]
    public function testAcceptsWholeNumbersFromOneToTen(string $input, string $stored): void
    {
        $model = $this->model($input);
        $model->beforeSave();
        $this->assertSame($stored, $model->getValue());
    }

    /** @return array<string, array{0: string}> */
    public static function invalid(): array
    {
        return [
            'zero' => ['0'],
            'eleven' => ['11'],
            'negative' => ['-1'],
            'text' => ['abc'],
            'decimal' => ['2.5'],
            'empty' => [''],
            'exponent' => ['1e1'],
        ];
    }

    #[DataProvider('invalid')]
    public function testRejectsAnythingElse(string $input): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('between 1 and 10');
        $this->model($input)->beforeSave();
    }
}
