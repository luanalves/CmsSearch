<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Source;

use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\Source\ContentTypeOptions;
use Thedevkitchen\CmsSearch\Model\Source\PageContentTypeOptions;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ContentTypeBuilder;

class PageContentTypeOptionsTest extends TestCase
{
    private const ACTIVE = [['value' => 3, 'label' => 'News'], ['value' => 5, 'label' => 'TV']];

    private function options(?ContentTypeRepositoryInterface $repository = null): PageContentTypeOptions
    {
        $active = $this->createMock(ContentTypeOptions::class);
        $active->method('toOptionArray')->willReturn(self::ACTIVE);
        return new PageContentTypeOptions($active, $repository ?? $this->createMock(ContentTypeRepositoryInterface::class));
    }

    public function testWithoutCurrentValueOnlyActiveTypesAreListed(): void
    {
        $this->assertSame(self::ACTIVE, $this->options()->toOptionArrayWithCurrent(null));
    }

    public function testAnActiveCurrentTypeIsNotDuplicated(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->expects($this->never())->method('getById');
        $this->assertSame(self::ACTIVE, $this->options($repository)->toOptionArrayWithCurrent(5));
    }

    public function testAnInactiveCurrentTypeIsKeptAndMarked(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('getById')->with(9)->willReturn(
            ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 9, 'help', [0 => 'Help'])->setIsActive(false)
        );
        $options = $this->options($repository)->toOptionArrayWithCurrent(9);
        $this->assertSame(['value' => 9, 'label' => 'Help (inactive)'], end($options));
        $this->assertCount(3, $options);
    }

    public function testADeletedCurrentTypeIsIgnored(): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->assertSame(self::ACTIVE, $this->options($repository)->toOptionArrayWithCurrent(9));
    }
}
