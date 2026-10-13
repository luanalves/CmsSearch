<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Di;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Search\CmsSearchQuery;
use Thedevkitchen\CmsSearch\Model\Search\ProductCount;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;
use Thedevkitchen\CmsSearch\Observer\AddCmsTabHandle;

/**
 * Static contract of etc/frontend/events.xml, etc/frontend/di.xml and the search part of etc/di.xml.
 */
class FrontendContractTest extends TestCase
{
    private const LOGGER = 'Thedevkitchen\\CmsSearch\\Model\\Search\\Log\\Logger';

    private function xml(string $file): \SimpleXMLElement
    {
        $path = dirname(__DIR__, 3) . '/etc/' . $file;
        $this->assertFileExists($path);
        $xml = simplexml_load_file($path);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }

    public function testTheHandleObserverListensOnlyToLayoutLoadBeforeOnTheStorefront(): void
    {
        $events = $this->xml('frontend/events.xml');
        $this->assertSame(['layout_load_before'], array_map('strval', $events->xpath('//event/@name')));
        $instances = array_map(static fn ($n): string => ltrim((string) $n, '\\'), $events->xpath('//event/observer/@instance'));
        $this->assertSame([AddCmsTabHandle::class], $instances);
        $global = simplexml_load_file(dirname(__DIR__, 3) . '/etc/events.xml');
        $this->assertSame([], $global->xpath('//observer[contains(@instance, "AddCmsTabHandle")]'));
    }

    public function testTheObserverGetsALazyStateAndEverySearchClassLogsToItsOwnFile(): void
    {
        $args = $this->xml('frontend/di.xml')->xpath('//type[@name="' . AddCmsTabHandle::class . '"]/arguments/argument');
        $byName = [];
        foreach ($args as $arg) {
            $byName[(string) $arg['name']] = ltrim((string) $arg, '\\');
        }
        $this->assertSame(['state' => SearchState::class . '\\Proxy', 'logger' => self::LOGGER], $byName);
        $this->assertTrue(class_exists(SearchState::class));
        $di = $this->xml('di.xml');
        foreach ([SearchState::class, ProductCount::class] as $class) {
            $logger = $di->xpath('//type[@name="' . $class . '"]/arguments/argument[@name="logger"]');
            $this->assertCount(1, $logger, $class);
            $this->assertSame(self::LOGGER, ltrim((string) $logger[0], '\\'), $class);
        }
        $file = $di->xpath('//virtualType[@name="Thedevkitchen\\CmsSearch\\Model\\Search\\Log\\Handler"]/arguments/argument[@name="fileName"]');
        $this->assertSame('/var/log/cmssearch_search.log', (string) ($file[0] ?? ''));
        $this->assertTrue(class_exists(CmsSearchQuery::class));
    }
}
