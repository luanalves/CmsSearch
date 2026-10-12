<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Input;

use Magento\Framework\Exception\InputException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Input\StrictInput;

class StrictInputTest extends TestCase
{
    public static function validIds(): array
    {
        return ['int' => [7, 7], 'string' => ['42', 42], 'max' => ['4294967295', 4294967295], 'empty' => ['', null], 'null' => [null, null]];
    }

    #[DataProvider('validIds')]
    public function testOptionalIdAccepts(mixed $value, ?int $expected): void
    {
        $this->assertSame($expected, (new StrictInput())->optionalId($value));
    }

    public static function invalidIds(): array
    {
        return [
            'zero' => ['0'], 'zero int' => [0], 'negative' => ['-1'], 'negative int' => [-3], 'leading zero' => ['07'],
            'decimal' => ['1.5'], 'text' => ['abc'], 'digits and text' => ['5abc'], 'too big' => ['4294967296'],
            'array' => [[1]], 'bool' => [true], 'space' => [' 5'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function testOptionalIdRejects(mixed $value): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Please correct the data sent.');
        (new StrictInput())->optionalId($value);
    }

    public static function validSortOrders(): array
    {
        return ['empty' => ['', 0], 'null' => [null, 0], 'string' => ['12', 12], 'int' => [12, 12]];
    }

    #[DataProvider('validSortOrders')]
    public function testSortOrderAccepts(mixed $value, int $expected): void
    {
        $this->assertSame($expected, (new StrictInput())->sortOrder($value));
    }

    public static function invalidSortOrders(): array
    {
        return ['negative' => ['-1'], 'negative int' => [-1], 'text' => ['a'], 'decimal' => ['1.0'], 'array' => [[]]];
    }

    #[DataProvider('invalidSortOrders')]
    public function testSortOrderRejects(mixed $value): void
    {
        $this->expectException(InputException::class);
        (new StrictInput())->sortOrder($value);
    }

    public static function validFlags(): array
    {
        return ['0' => [0, false], '1' => [1, true], "'0'" => ['0', false], "'1'" => ['1', true]];
    }

    #[DataProvider('validFlags')]
    public function testFlagAccepts(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, (new StrictInput())->flag($value));
    }

    public static function invalidFlags(): array
    {
        return ['true string' => ['true'], 'yes' => ['yes'], 'two' => [2], 'empty' => [''], 'null' => [null], 'bool' => [true]];
    }

    #[DataProvider('invalidFlags')]
    public function testFlagRejects(mixed $value): void
    {
        $this->expectException(InputException::class);
        (new StrictInput())->flag($value);
    }
}
