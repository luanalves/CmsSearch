<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Config\Backend\BoundedInteger;
use Thedevkitchen\CmsSearch\Model\Config\Backend\PageSize;
use Thedevkitchen\CmsSearch\Model\Config\Backend\SummaryLength;

class BoundedIntegerTest extends TestCase
{
    /** @param class-string<BoundedInteger> $class */
    private function model(string $class, string $value): BoundedInteger
    {
        $model = (new ObjectManager($this))->getObject($class);
        $model->setValue($value);
        return $model;
    }

    /** @return array<string, array{0: class-string<BoundedInteger>, 1: string, 2: string}> */
    public static function valid(): array
    {
        return [
            'page size 1' => [PageSize::class, '1', '1'],
            'page size 60' => [PageSize::class, '60', '60'],
            'page size trimmed' => [PageSize::class, ' 12 ', '12'],
            'page size leading zero' => [PageSize::class, '012', '12'],
            'summary 50' => [SummaryLength::class, '50', '50'],
            'summary 300' => [SummaryLength::class, '300', '300'],
        ];
    }

    /** @param class-string<BoundedInteger> $class */
    #[DataProvider('valid')]
    public function testWholeNumbersInsideTheBoundsAreStoredNormalised(string $class, string $input, string $stored): void
    {
        $model = $this->model($class, $input);
        $model->beforeSave();
        $this->assertSame($stored, $model->getValue());
    }

    /** @return array<string, array{0: class-string<BoundedInteger>, 1: string, 2: string}> */
    public static function invalid(): array
    {
        return [
            'page size 0' => [PageSize::class, '0', 'between 1 and 60'],
            'page size 61' => [PageSize::class, '61', 'between 1 and 60'],
            'page size text' => [PageSize::class, 'abc', 'between 1 and 60'],
            'page size empty' => [PageSize::class, '', 'between 1 and 60'],
            'page size decimal' => [PageSize::class, '1.5', 'between 1 and 60'],
            'summary 49' => [SummaryLength::class, '49', 'between 50 and 300'],
            'summary 301' => [SummaryLength::class, '301', 'between 50 and 300'],
        ];
    }

    /** @param class-string<BoundedInteger> $class */
    #[DataProvider('invalid')]
    public function testAnythingElseIsRejectedOnSave(string $class, string $input, string $message): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
        $this->model($class, $input)->beforeSave();
    }
}
