<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Di;

use Magento\Framework\Event\ObserverInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Observer\AddCmsTabHandle;
use Thedevkitchen\CmsSearch\Observer\PublishBlockReindex;
use Thedevkitchen\CmsSearch\Observer\PublishCategoryReindex;
use Thedevkitchen\CmsSearch\Observer\PublishDynamicBlockReindex;
use Thedevkitchen\CmsSearch\Observer\PublishPageReindex;
use Thedevkitchen\CmsSearch\Observer\PublishProductImportReindex;
use Thedevkitchen\CmsSearch\Observer\PublishProductReindex;
use Thedevkitchen\CmsSearch\Observer\PublishWidgetInstanceReindex;
use Thedevkitchen\CmsSearch\Observer\SavePageClassification;

/**
 * Static contract of the module's events.xml files.
 */
class EventsContractTest extends TestCase
{
    /** @return string[] */
    private function files(): array
    {
        $etc = dirname(__DIR__, 3) . '/etc';
        return array_merge(glob($etc . '/events.xml') ?: [], glob($etc . '/*/events.xml') ?: []);
    }

    public function testEveryObserverClassExistsAndIsAnObserver(): void
    {
        $files = $this->files();
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            foreach (simplexml_load_file($file)->xpath('//observer') as $observer) {
                $class = ltrim((string) $observer['instance'], '\\');
                $this->assertTrue(class_exists($class), "$file: observer class not found: $class");
                $this->assertTrue(is_a($class, ObserverInterface::class, true), "$class must implement ObserverInterface");
            }
        }
    }

    public function testTheStorefrontFileIsCoveredAndOnlyAddsTheSearchHandle(): void
    {
        $frontend = dirname(__DIR__, 3) . '/etc/frontend/events.xml';
        $this->assertContains($frontend, $this->files());
        $xml = simplexml_load_file($frontend);
        $this->assertSame(['layout_load_before'], array_map('strval', $xml->xpath('//event/@name')));
        $this->assertSame(
            [AddCmsTabHandle::class],
            array_map(static fn ($node): string => ltrim((string) $node, '\\'), $xml->xpath('//observer/@instance'))
        );
    }

    public function testThePageSaveObserverRunsInsideTheSaveTransaction(): void
    {
        $path = dirname(__DIR__, 3) . '/etc/events.xml';
        $this->assertFileExists($path, 'etc/events.xml must exist (global area: admin form, REST and CLI saves)');
        $xml = simplexml_load_file($path);
        $instances = array_map(
            static fn ($node): string => ltrim((string) $node, '\\'),
            $xml->xpath('//event[@name="cms_page_save_after"]/observer/@instance')
        );
        $this->assertSame([SavePageClassification::class, PublishPageReindex::class], $instances);
        // Reindex requests are made inside the transaction and sent by the after-commit flush plugin, which also
        // merges duplicates; a *_commit_after observer would send a second, unmerged message.
        $this->assertSame([], $xml->xpath('//event[contains(@name, "commit_after")]'));
    }

    public function testEveryPageWriteAsksForAReindex(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/events.xml');
        foreach (['cms_page_save_after', 'cms_page_delete_after'] as $event) {
            $instances = array_map(
                static fn ($node): string => ltrim((string) $node, '\\'),
                $xml->xpath('//event[@name="' . $event . '"]/observer/@instance')
            );
            $this->assertContains(PublishPageReindex::class, $instances, $event);
        }
    }

    /**
     * Plan 6: every write of a block or of a host asks for a reindex, from the global area (admin, REST, CLI, cron,
     * async consumers). Model events fire inside the save transaction (the publisher sends after the outermost COMMIT);
     * product imports write by raw SQL and are covered by the bunch events.
     */
    public function testEveryBlockAndHostWriteAsksForAReindex(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/events.xml');
        $expected = [
            'cms_block_save_after' => PublishBlockReindex::class,
            'cms_block_delete_after' => PublishBlockReindex::class,
            'magento_banner_save_after' => PublishDynamicBlockReindex::class,
            'magento_banner_delete_after' => PublishDynamicBlockReindex::class,
            'widget_widget_instance_save_after' => PublishWidgetInstanceReindex::class,
            'widget_widget_instance_delete_after' => PublishWidgetInstanceReindex::class,
            'catalog_product_save_after' => PublishProductReindex::class,
            'catalog_product_delete_after' => PublishProductReindex::class,
            'catalog_category_save_after' => PublishCategoryReindex::class,
            'catalog_category_delete_after' => PublishCategoryReindex::class,
            'catalog_category_move_after' => PublishCategoryReindex::class,
            'catalog_product_import_bunch_save_after' => PublishProductImportReindex::class,
            'catalog_product_import_bunch_delete_after' => PublishProductImportReindex::class,
        ];
        foreach ($expected as $event => $class) {
            $instances = array_map(
                static fn ($node): string => ltrim((string) $node, '\\'),
                $xml->xpath('//event[@name="' . $event . '"]/observer/@instance')
            );
            $this->assertSame([$class], $instances, $event);
        }
        $this->assertSame(
            array_merge(['cms_page_save_after', 'cms_page_delete_after'], array_keys($expected)),
            array_map('strval', $xml->xpath('//event/@name')),
            'no other global event is observed'
        );
    }
}
