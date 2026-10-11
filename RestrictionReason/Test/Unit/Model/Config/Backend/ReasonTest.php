<?php

declare(strict_types=1);

namespace Thedevkitchen\RestrictionReason\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\RestrictionReason\Model\Config\Backend\Reason;
use Thedevkitchen\RestrictionReason\Model\Config\Paths;

class ReasonTest extends TestCase
{
    private ScopeConfigInterface&MockObject $config;
    private Reason $model;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ScopeConfigInterface::class);
        $this->model = (new ObjectManager($this))->getObject(Reason::class, ['config' => $this->config]);
    }

    private function maxLengthIs(mixed $value): void
    {
        $this->config->method('getValue')->with(Paths::MAX_LENGTH)->willReturn($value);
    }

    public function testEmptyValueIsKept(): void
    {
        $this->maxLengthIs('1000');
        $this->model->setValue('');
        $this->model->beforeSave();
        $this->assertSame('', $this->model->getValue());
    }

    public function testTrimsWhitespace(): void
    {
        $this->maxLengthIs('1000');
        $this->model->setValue("  Closed for stocktake \n");
        $this->model->beforeSave();
        $this->assertSame('Closed for stocktake', $this->model->getValue());
    }

    public function testStripsTagsAndScriptContent(): void
    {
        $this->maxLengthIs('1000');
        $this->model->setValue('<b>Closed</b><script>alert(1)</script> for stocktake');
        $this->model->beforeSave();
        $this->assertSame('Closed for stocktake', $this->model->getValue());
    }

    public function testAcceptsExactlyTheLimit(): void
    {
        $this->maxLengthIs('10');
        $this->model->setValue(str_repeat('a', 10));
        $this->model->beforeSave();
        $this->assertSame(str_repeat('a', 10), $this->model->getValue());
    }

    public function testRejectsOverTheLimit(): void
    {
        $this->maxLengthIs('10');
        $this->model->setValue(str_repeat('a', 11));
        $this->expectException(LocalizedException::class);
        $this->model->beforeSave();
    }

    public function testLimitCountsCharactersNotBytes(): void
    {
        $this->maxLengthIs('10');
        $this->model->setValue(str_repeat('ç', 10));
        $this->model->beforeSave();
        $this->assertSame(str_repeat('ç', 10), $this->model->getValue());
    }

    public function testFallsBackTo1000WhenLimitIsMissing(): void
    {
        $this->maxLengthIs(null);
        $this->model->setValue(str_repeat('a', 1000));
        $this->model->beforeSave();
        $this->assertSame(1000, mb_strlen($this->model->getValue()));

        $this->model->setValue(str_repeat('a', 1001));
        $this->expectException(LocalizedException::class);
        $this->model->beforeSave();
    }

    public function testFallsBackTo1000WhenLimitIsInvalid(): void
    {
        $this->maxLengthIs('abc');
        $this->model->setValue(str_repeat('a', 1001));
        $this->expectException(LocalizedException::class);
        $this->model->beforeSave();
    }

    /**
     * @dataProvider nonPositiveLimits
     */
    public function testFallsBackTo1000WhenLimitIsNotPositive(string $limit): void
    {
        $this->maxLengthIs($limit);
        $this->model->setValue(str_repeat('a', 1000));
        $this->model->beforeSave();
        $this->assertSame(1000, mb_strlen($this->model->getValue()));

        $this->model->setValue(str_repeat('a', 1001));
        $this->expectException(LocalizedException::class);
        $this->model->beforeSave();
    }

    public static function nonPositiveLimits(): array
    {
        return ['zero' => ['0'], 'negative' => ['-5']];
    }
}
