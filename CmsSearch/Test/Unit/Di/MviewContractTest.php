<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Di;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

/**
 * Schedule mode (spec 5.7): the native catalogsearch_fulltext mview watches the product block text table, so the
 * cron indexer_update_all_views reindexes a product whose block text changed (no synchronous reindex).
 */
class MviewContractTest extends TestCase
{
    public function testTheBlockTextTableIsSubscribedToTheNativeFulltextView(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/mview.xml');
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $views = $xml->xpath('/config/view');
        $this->assertCount(1, $views);
        $this->assertSame(
            ['catalogsearch_fulltext', 'Magento\\CatalogSearch\\Model\\Indexer\\Mview\\Action', 'indexer'],
            [(string) $views[0]['id'], ltrim((string) $views[0]['class'], '\\'), (string) $views[0]['group']]
        );
        $this->assertTrue(class_exists(ltrim((string) $views[0]['class'], '\\')));
        $tables = $views[0]->xpath('subscriptions/table');
        $this->assertCount(1, $tables);
        $this->assertSame([Tables::PRODUCT_BLOCK_TEXT, 'product_id'], [(string) $tables[0]['name'], (string) $tables[0]['entity_column']]);
        $this->assertSame('', (string) $tables[0]['subscription_model'], 'the default trigger subscription');
    }

    /** The view id and class must be the ones the native module declares, or the merge creates a second view. */
    public function testTheViewMatchesTheNativeDeclaration(): void
    {
        $native = simplexml_load_file(dirname(__DIR__, 7) . '/vendor/magento/module-catalog-search/etc/mview.xml');
        $this->assertInstanceOf(\SimpleXMLElement::class, $native);
        $view = $native->xpath('/config/view[@id="catalogsearch_fulltext"]');
        $this->assertCount(1, $view);
        $ours = simplexml_load_file(dirname(__DIR__, 3) . '/etc/mview.xml')->xpath('/config/view')[0];
        $this->assertSame(ltrim((string) $view[0]['class'], '\\'), ltrim((string) $ours['class'], '\\'));
        $this->assertSame((string) $view[0]['group'], (string) $ours['group']);
    }

    public function testTheModuleLoadsAfterTheModulesItExtends(): void
    {
        $modules = array_map('strval', simplexml_load_file(dirname(__DIR__, 3) . '/etc/module.xml')->xpath('//sequence/module/@name'));
        foreach (['Magento_Catalog', 'Magento_CatalogStaging', 'Magento_CatalogSearch', 'Magento_AdvancedSearch', 'Magento_Elasticsearch',
                     'Magento_ElasticsearchCatalogPermissions', 'Magento_Widget', 'Magento_Banner', 'Magento_BannerCustomerSegment'] as $module) {
            $this->assertContains($module, $modules);
        }
    }
}
