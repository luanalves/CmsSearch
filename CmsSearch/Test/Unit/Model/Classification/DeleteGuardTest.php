<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Classification;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Exception\ClassificationTermInUseException;
use Thedevkitchen\CmsSearch\Model\Classification\DeleteGuard;
use Thedevkitchen\CmsSearch\Model\Classification\UsageCounter;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ClassificationTermBuilder;

class DeleteGuardTest extends TestCase
{
    private UsageCounter&MockObject $counter;

    protected function setUp(): void
    {
        $this->counter = $this->createMock(UsageCounter::class);
    }

    private function term(?int $id): \Thedevkitchen\CmsSearch\Model\ClassificationTerm
    {
        return ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), $id, 2, 'agro', null, [0 => 'Agro']);
    }

    public function testATermWithChildrenIsBlockedBeforeCountingPages(): void
    {
        $this->counter->method('countChildren')->with(4)->willReturn(2);
        $this->counter->expects($this->never())->method('countPages');
        try {
            (new DeleteGuard($this->counter))->assertDeletable($this->term(4));
            $this->fail('expected ClassificationTermInUseException');
        } catch (ClassificationTermInUseException $exception) {
            $this->assertStringContainsString('"Agro"', $exception->getMessage());
            $this->assertStringContainsString('2 child term(s)', $exception->getMessage());
        }
    }

    public function testATermWithLinkedPagesIsBlockedWithCountAndList(): void
    {
        $this->counter->method('countChildren')->willReturn(0);
        $this->counter->method('countPages')->with(4)->willReturn(7);
        $this->counter->method('firstPages')->with(4, 5)->willReturn([
            ['page_id' => '1', 'title' => 'Home', 'identifier' => 'home'],
            ['page_id' => '2', 'title' => '#2', 'identifier' => ''],
        ]);
        try {
            (new DeleteGuard($this->counter))->assertDeletable($this->term(4));
            $this->fail('expected ClassificationTermInUseException');
        } catch (ClassificationTermInUseException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('7 CMS page(s)', $message);
            $this->assertStringContainsString('Home [home], #2', $message);
            $this->assertStringContainsString('and 5 more', $message);
        }
    }

    public function testAFreeTermPasses(): void
    {
        $this->counter->expects($this->once())->method('countChildren')->with(4)->willReturn(0);
        $this->counter->expects($this->once())->method('countPages')->with(4)->willReturn(0);
        (new DeleteGuard($this->counter))->assertDeletable($this->term(4));
    }

    public function testANewTermIsNotQueried(): void
    {
        $this->counter->expects($this->never())->method('countChildren');
        $this->counter->expects($this->never())->method('countPages');
        (new DeleteGuard($this->counter))->assertDeletable($this->term(null));
    }
}
