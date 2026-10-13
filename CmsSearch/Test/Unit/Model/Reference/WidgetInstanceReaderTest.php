<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetPageGroup;
use Thedevkitchen\CmsSearch\Model\Reference\WidgetInstanceReader;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class WidgetInstanceReaderTest extends TestCase
{
    use DbStubTrait;

    private static function row(int $id, string $type, int $theme, string $stores, ?string $parameters): array
    {
        return ['instance_id' => $id, 'instance_type' => $type, 'theme_id' => $theme, 'store_ids' => $stores, 'widget_parameters' => $parameters];
    }

    public function testAllReadsTheSupportedInstancesWithTheirPageGroupsInTwoQueries(): void
    {
        $reader = new WidgetInstanceReader($this->db([
            [
                self::row(1, 'Magento\\\\Cms\\\\Block\\\\Widget\\\\Block', 3, '0', '{"block_id":"3"}'),
                self::row(2, 'Magento\\CatalogWidget\\Block\\Product\\ProductsList', 3, '0', '{}'),
                self::row(3, 'Magento\\Banner\\Block\\Widget\\Banner', 5, '2, 3,x,3', '{"display_mode":"fixed","banner_ids":"4"}'),
            ],
            [
                ['instance_id' => 1, 'page_group' => 'anchor_categories', 'layout_handle' => 'catalog_category_view_type_layered', 'page_for' => 'specific', 'entities' => '37, x,0,37,4294967296'],
                ['instance_id' => 3, 'page_group' => 'all_pages', 'layout_handle' => 'default', 'page_for' => 'all', 'entities' => null],
                ['instance_id' => 1, 'page_group' => 'pages', 'layout_handle' => 'contact_index_index', 'page_for' => 'all', 'entities' => ''],
            ],
        ]));
        $instances = $reader->all();
        $this->assertCount(2, $instances);
        [$cms, $banner] = $instances;
        $this->assertSame([1, 'Magento\\Cms\\Block\\Widget\\Block', 3, [0], ['block_id' => '3']], [
            $cms->getId(), $cms->getType(), $cms->getThemeId(), $cms->getStoreIds(), $cms->getParameters(),
        ]);
        $this->assertSame(
            [['anchor_categories', 'catalog_category_view_type_layered', 'specific', [37]], ['pages', 'contact_index_index', 'all', []]],
            array_map(static fn (WidgetPageGroup $g): array => [$g->getGroup(), $g->getLayoutHandle(), $g->getPageFor(), $g->getEntities()], $cms->getPageGroups())
        );
        $this->assertTrue($cms->getPageGroups()[0]->isSpecific());
        $this->assertFalse($cms->getPageGroups()[1]->isSpecific());
        $this->assertSame([3, 5, [2, 3], 'all_pages'], [$banner->getId(), $banner->getThemeId(), $banner->getStoreIds(), $banner->getPageGroups()[0]->getGroup()]);
        $this->assertSame([[1, 3]], $this->whereValues('instance_id IN (?)'));
        $this->assertSame([], $this->whereValues('instance_id = ?'), 'all() reads every instance');
        $this->assertSame(['widget_instance', 'widget_instance_page'], $this->tables);
    }

    public function testGetReadsOneInstance(): void
    {
        $reader = new WidgetInstanceReader($this->db([
            [self::row(3, 'Magento\\Banner\\Block\\Widget\\Banner', 5, '0', 'not json')],
            [],
        ]));
        $instance = $reader->get(3);
        $this->assertNotNull($instance);
        $this->assertSame([[], []], [$instance->getParameters(), $instance->getPageGroups()]);
        $this->assertSame([3], $this->whereValues('instance_id = ?'));
        $this->assertSame([[3]], $this->whereValues('instance_id IN (?)'));
    }

    public function testNullParametersAndAScalarJsonAreEmptyParameters(): void
    {
        $reader = new WidgetInstanceReader($this->db([
            [
                self::row(4, 'Magento\\Cms\\Block\\Widget\\Block', 3, '0', null),
                self::row(5, 'Magento\\Cms\\Block\\Widget\\Block', 3, '', '"3"'),
            ],
            [],
        ]));
        [$four, $five] = $reader->all();
        $this->assertSame([[], [0], [], []], [$four->getParameters(), $four->getStoreIds(), $five->getParameters(), $five->getStoreIds()]);
    }

    public function testAnUnsupportedOrMissingInstanceSkipsThePageGroupQuery(): void
    {
        $reader = new WidgetInstanceReader($this->db([[self::row(2, 'Magento\\CatalogWidget\\Block\\Product\\ProductsList', 3, '0', '{}')]]));
        $this->assertNull($reader->get(2));
        $this->assertNull($reader->get(0)); // no query for an invalid id
        $this->assertNull($reader->get(-1));
    }
}
