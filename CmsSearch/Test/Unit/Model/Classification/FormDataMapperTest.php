<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Classification;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Classification\FormDataMapper;
use Thedevkitchen\CmsSearch\Model\ContentType\FormDataMapper as LabelsMapper;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ClassificationTermBuilder;

class FormDataMapperTest extends TestCase
{
    public function testToFormData(): void
    {
        $term = ClassificationTermBuilder::build(
            fn (string $c) => $this->createMock($c),
            9,
            2,
            'milho',
            4,
            [0 => 'Milho', 3 => 'Corn']
        )->setSortOrder(5)->setIsActive(false);
        $this->assertSame(
            [
                'term_id' => '9',
                'website_id' => '2',
                'website_name' => 'Website 1',
                'parent_id' => '4',
                'code' => 'milho',
                'sort_order' => '5',
                'is_active' => '0',
                'default_label' => 'Milho',
                'store_labels' => [['record_id' => '3', 'store_id' => '3', 'label' => 'Corn']],
            ],
            (new FormDataMapper(new LabelsMapper()))->toFormData($term, 'Website 1')
        );
    }

    public function testARootTermHasAnEmptyParent(): void
    {
        $term = ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), 4, 2, 'agro');
        $this->assertSame('', (new FormDataMapper(new LabelsMapper()))->toFormData($term, 'W')['parent_id']);
    }

    public function testLabelsUseTheSharedLabelMapping(): void
    {
        $post = ['default_label' => ' Agro ', 'store_labels' => [['store_id' => '2', 'label' => 'Agronegocio'], ['store_id' => '3', 'label' => '']]];
        $this->assertSame([0 => 'Agro', 2 => 'Agronegocio'], (new FormDataMapper(new LabelsMapper()))->toLabels($post));
    }
}
