<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Di;

use Magento\Framework\Event\ObserverInterface;
use PHPUnit\Framework\TestCase;
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

    public function testThePageSaveObserverRunsInsideTheSaveTransaction(): void
    {
        $path = dirname(__DIR__, 3) . '/etc/events.xml';
        $this->assertFileExists($path, 'etc/events.xml must exist (global area: admin form, REST and CLI saves)');
        $xml = simplexml_load_file($path);
        $instances = array_map(
            static fn ($node): string => ltrim((string) $node, '\\'),
            $xml->xpath('//event[@name="cms_page_save_after"]/observer/@instance')
        );
        $this->assertSame([SavePageClassification::class], $instances);
        // Publishing reindex messages after commit is Plan 3; this plan must not listen to *_commit_after.
        $this->assertSame([], $xml->xpath('//event[contains(@name, "commit_after")]'));
    }
}
