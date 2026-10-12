<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\ContentType;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\ContentType\FormDataMapper;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ContentTypeBuilder;

class FormDataMapperTest extends TestCase
{
    private FormDataMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FormDataMapper();
    }

    public function testBuildsLabelsFromTheDefaultFieldAndStoreRows(): void
    {
        $labels = $this->mapper->toLabels([
            'default_label' => '  News ',
            'store_labels' => [
                ['store_id' => '2', 'label' => 'Noticias'],
                ['store_id' => '3', 'label' => '   '],
                ['store_id' => '0', 'label' => 'ignored: store 0 comes from default_label'],
            ],
        ]);

        $this->assertSame([0 => 'News', 2 => 'Noticias'], $labels);
    }

    public function testReturnsEmptyLabelsWhenNothingWasPosted(): void
    {
        $this->assertSame([], $this->mapper->toLabels([]));
    }

    public function testMapsATypeToFormData(): void
    {
        $type = ContentTypeBuilder::build(
            fn (string $c) => $this->createMock($c),
            4,
            'news',
            [0 => 'News', 2 => 'Noticias']
        );
        $type->setSortOrder(10)->setIsActive(true);

        $this->assertSame([
            'type_id' => 4,
            'code' => 'news',
            'sort_order' => 10,
            'is_active' => '1',
            'default_label' => 'News',
            'store_labels' => [['record_id' => '2', 'store_id' => '2', 'label' => 'Noticias']],
        ], $this->mapper->toFormData($type));
    }
}
