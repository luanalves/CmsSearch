<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\ContentType;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Exception\ContentTypeInUseException;
use Thedevkitchen\CmsSearch\Model\ContentType;
use Thedevkitchen\CmsSearch\Model\ContentType\DeleteGuard;
use Thedevkitchen\CmsSearch\Model\ContentType\UsageCounter;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ContentTypeBuilder;

class DeleteGuardTest extends TestCase
{
    private UsageCounter&MockObject $usageCounter;
    private DeleteGuard $guard;

    protected function setUp(): void
    {
        $this->usageCounter = $this->createMock(UsageCounter::class);
        $this->guard = new DeleteGuard($this->usageCounter);
    }

    private function type(?int $id, string $code): ContentType
    {
        return ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), $id, $code);
    }

    public function testAllowsATypeWithoutPages(): void
    {
        $this->usageCounter->method('count')->with(4)->willReturn(0);
        $this->usageCounter->expects($this->never())->method('firstPages');

        $this->guard->assertDeletable($this->type(4, 'news'));
        $this->addToAssertionCount(1);
    }

    public function testBlocksATypeWithPagesAndListsThem(): void
    {
        $this->usageCounter->method('count')->with(4)->willReturn(2);
        $this->usageCounter->method('firstPages')->with(4, 5)->willReturn([
            ['page_id' => '1', 'title' => 'Home', 'identifier' => 'home'],
            ['page_id' => '2', 'title' => 'About', 'identifier' => 'about-us'],
        ]);

        try {
            $this->guard->assertDeletable($this->type(4, 'news'));
            $this->fail('Expected ContentTypeInUseException');
        } catch (ContentTypeInUseException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('"News"', $message);
            $this->assertStringContainsString('2 CMS page(s)', $message);
            $this->assertStringContainsString('Home [home]', $message);
            $this->assertStringContainsString('About [about-us]', $message);
            $this->assertStringNotContainsString('more', $message);
        }
    }

    public function testSummarisesPagesBeyondTheSampleSize(): void
    {
        $this->usageCounter->method('count')->with(4)->willReturn(8);
        $sample = [];
        for ($i = 1; $i <= 5; $i++) {
            $sample[] = ['page_id' => (string) $i, 'title' => 'Page ' . $i, 'identifier' => 'p' . $i];
        }
        $this->usageCounter->method('firstPages')->with(4, 5)->willReturn($sample);

        try {
            $this->guard->assertDeletable($this->type(4, 'news'));
            $this->fail('Expected ContentTypeInUseException');
        } catch (ContentTypeInUseException $exception) {
            $this->assertStringContainsString('8 CMS page(s)', $exception->getMessage());
            $this->assertStringContainsString('and 3 more', $exception->getMessage());
        }
    }

    public function testIgnoresATypeThatWasNeverSaved(): void
    {
        $this->usageCounter->expects($this->never())->method('count');

        $this->guard->assertDeletable($this->type(null, 'news'));
        $this->addToAssertionCount(1);
    }

    public function testListsOrphanClassificationRowsById(): void
    {
        $this->usageCounter->method('count')->with(4)->willReturn(2);
        $this->usageCounter->method('firstPages')->with(4, 5)->willReturn([
            ['page_id' => '1', 'title' => 'Home', 'identifier' => 'home'],
            ['page_id' => '9', 'title' => '#9', 'identifier' => ''],
        ]);

        try {
            $this->guard->assertDeletable($this->type(4, 'news'));
            $this->fail('Expected ContentTypeInUseException');
        } catch (ContentTypeInUseException $exception) {
            $this->assertStringContainsString('Home [home], #9)', $exception->getMessage());
        }
    }
}
