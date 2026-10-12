<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Di;

use Magento\Staging\Model\StagingApplier\PostProcessorInterface;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermSearchResultsInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterface;
use Thedevkitchen\CmsSearch\Console\Command\ReindexCommand;
use Thedevkitchen\CmsSearch\Model\ClassificationTermSearchResults;
use Thedevkitchen\CmsSearch\Model\ContentTypeSearchResults;
use Thedevkitchen\CmsSearch\Model\Indexer\DocumentBuilder;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\PageReindexProcessor;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexHandler;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexPublisher;
use Thedevkitchen\CmsSearch\Model\Staging\PostProcessor\PageVersionApplied;
use Thedevkitchen\CmsSearch\Plugin\Cms\PageFormDataPlugin;
use Thedevkitchen\CmsSearch\Plugin\Framework\DB\FlushReindexMessagesPlugin;
use Thedevkitchen\CmsSearch\Ui\DataProvider\Page\Form\Modifier\SearchFieldset;

/**
 * Static contract check of etc/di.xml and etc/adminhtml/di.xml, plus the DI side of etc/events.xml (no DB, no ObjectManager).
 */
class DiPreferencesContractTest extends TestCase
{
    private const POOL = 'Thedevkitchen\\CmsSearch\\Ui\\DataProvider\\Page\\Form\\Modifier\\Pool';
    private const LOGGER = 'Thedevkitchen\\CmsSearch\\Model\\Queue\\Log\\Logger';

    /** Interfaces configured with <type> in our di.xml => the class Magento instantiates (app/etc/di.xml preference). */
    private const IMPLEMENTATIONS = [
        'Magento\\Framework\\Console\\CommandListInterface' => 'Magento\\Framework\\Console\\CommandList',
    ];

    /** @return array<string, array{0: string}> */
    public static function files(): array
    {
        return ['global' => ['etc/di.xml'], 'adminhtml' => ['etc/adminhtml/di.xml']];
    }

    private function di(string $file): \SimpleXMLElement
    {
        $path = dirname(__DIR__, 3) . '/' . $file;
        $this->assertFileExists($path, "$file must exist");
        $xml = simplexml_load_file($path);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml, "$file must exist and be valid XML");
        return $xml;
    }

    private function exists(string $name): bool
    {
        return class_exists($name) || interface_exists($name);
    }

    /** @return array<string, string> virtualType name => base type, from every DI file of the module */
    private function virtualTypes(): array
    {
        $virtual = [];
        foreach (self::files() as [$file]) {
            foreach ($this->di($file)->xpath('//virtualType') as $vt) {
                $virtual[ltrim((string) $vt['name'], '\\')] = ltrim((string) $vt['type'], '\\');
            }
        }
        return $virtual;
    }

    public function testSearchResultsImplementTheModuleInterfaces(): void
    {
        $this->assertTrue(is_a(ContentTypeSearchResults::class, ContentTypeSearchResultsInterface::class, true));
        $this->assertTrue(is_a(ClassificationTermSearchResults::class, ClassificationTermSearchResultsInterface::class, true));
    }

    public function testTheGlobalFileBindsEveryRepository(): void
    {
        $for = array_map(fn ($p): string => ltrim((string) $p['for'], '\\'), $this->di('etc/di.xml')->xpath('//preference'));
        foreach (['ContentTypeRepositoryInterface', 'ClassificationTermRepositoryInterface', 'PageClassificationRepositoryInterface'] as $api) {
            $this->assertContains('Thedevkitchen\\CmsSearch\\Api\\' . $api, $for);
        }
    }

    #[DataProvider('files')]
    public function testEveryPreferenceSatisfiesItsContract(string $file): void
    {
        $prefs = $this->di($file)->xpath('//preference');
        $this->assertIsArray($prefs);
        foreach ($prefs as $pref) {
            $for = ltrim((string) $pref['for'], '\\');
            $type = ltrim((string) $pref['type'], '\\');
            $this->assertTrue($this->exists($for), "Preference 'for' not found: $for");
            $this->assertTrue($this->exists($type), "Preference type not found: $type");
            $this->assertTrue(is_a($type, $for, true), "$type must implement/extend $for");
        }
    }

    #[DataProvider('files')]
    public function testEveryDeclaredTypeAndVirtualTypeClassExists(string $file): void
    {
        $nodes = $this->di($file)->xpath('//type | //virtualType');
        $this->assertNotEmpty($nodes);
        foreach ($nodes as $node) {
            $class = ltrim((string) ($node->getName() === 'type' ? $node['name'] : $node['type']), '\\');
            $this->assertTrue($this->exists($class), "class not found: $class");
        }
    }

    #[DataProvider('files')]
    public function testEveryArgumentNameIsAConstructorParameterOfTheResolvedClass(string $file): void
    {
        $virtual = $this->virtualTypes();
        $checked = 0;
        foreach ($this->di($file)->xpath('//type | //virtualType') as $node) {
            $name = ltrim((string) $node['name'], '\\');
            $class = $name;
            for ($i = 0; isset($virtual[$class]) && $i < 20; $i++) {
                $class = $virtual[$class];
            }
            $class = self::IMPLEMENTATIONS[$class] ?? $class;
            foreach ($node->xpath('arguments/argument') as $argument) {
                $argName = (string) $argument['name'];
                $constructor = (new \ReflectionClass($class))->getConstructor();
                $params = $constructor ? array_map(fn ($p) => $p->getName(), $constructor->getParameters()) : [];
                $this->assertContains($argName, $params, "$name: argument '$argName' is not a constructor parameter of $class");
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    #[DataProvider('files')]
    public function testEveryPluginMethodMatchesAPublicMethodOfItsTarget(string $file): void
    {
        $plugins = $this->di($file)->xpath('//type/plugin');
        $this->assertIsArray($plugins);
        foreach ($plugins as $plugin) {
            $class = ltrim((string) $plugin['type'], '\\');
            $target = ltrim((string) $plugin->xpath('..')[0]['name'], '\\');
            $this->assertTrue(class_exists($class), "plugin class not found: $class");
            $intercepted = 0;
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (preg_match('/^(before|after|around)(.+)$/', $method->getName(), $m) === 1) {
                    $this->assertTrue(method_exists($target, lcfirst($m[2])), "$class::{$method->getName()} has no target on $target");
                    $intercepted++;
                }
            }
            $this->assertGreaterThan(0, $intercepted, "$class intercepts nothing");
        }
    }

    public function testTheCmsPageFormGetsOurModifierAndDataPlugin(): void
    {
        $di = $this->di('etc/adminhtml/di.xml');
        $pool = $di->xpath('//type[@name="Magento\\Cms\\Model\\Page\\DataProvider"]/arguments/argument[@name="pool"]');
        $this->assertCount(1, $pool);
        $this->assertSame(self::POOL, ltrim((string) $pool[0], '\\'));
        $class = $di->xpath('//virtualType[@name="' . self::POOL . '"]/arguments/argument[@name="modifiers"]/item/item[@name="class"]');
        $this->assertSame(SearchFieldset::class, ltrim((string) $class[0], '\\'));
        $this->assertTrue(is_a(SearchFieldset::class, ModifierInterface::class, true));
        $plugin = $di->xpath('//type[@name="Magento\\Cms\\Model\\Page\\DataProvider"]/plugin/@type');
        $this->assertSame(PageFormDataPlugin::class, ltrim((string) $plugin[0], '\\'));
    }

    /**
     * Observers get no <arguments> in di.xml: every constructor parameter must be autowirable (an existing class or
     * interface, or optional), otherwise the page save itself fails at runtime.
     */
    public function testEveryEventObserverIsAutowirable(): void
    {
        $path = dirname(__DIR__, 3) . '/etc/events.xml';
        $this->assertFileExists($path, 'etc/events.xml must exist');
        $observers = simplexml_load_file($path)->xpath('//observer/@instance');
        $this->assertNotEmpty($observers);
        foreach ($observers as $instance) {
            $class = ltrim((string) $instance, '\\');
            $this->assertTrue(class_exists($class), "observer class not found: $class");
            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
                $type = $param->getType();
                $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
                $this->assertTrue(
                    $param->isOptional() || $this->exists($name),
                    "$class: constructor parameter \$" . $param->getName() . " ($name) cannot be injected"
                );
            }
        }
    }

    public function testTheProcessorIsAReplaceablePreferenceAndTheQueueLogsToItsOwnFile(): void
    {
        $di = $this->di('etc/di.xml');
        $preference = $di->xpath('//preference[@for="Thedevkitchen\\CmsSearch\\Model\\Queue\\ReindexProcessorInterface"]/@type');
        $this->assertSame([PageReindexProcessor::class], array_map(static fn ($node): string => ltrim((string) $node, '\\'), $preference));
        $logger = $di->xpath('//type[@name="Thedevkitchen\\CmsSearch\\Model\\Queue\\ReindexHandler"]/arguments/argument[@name="logger"]');
        $this->assertCount(1, $logger);
        $this->assertSame(self::LOGGER, ltrim((string) $logger[0], '\\'));
        $handlers = $di->xpath('//virtualType[@name="' . self::LOGGER . '"]/arguments/argument[@name="handlers"]/item');
        $this->assertSame(['Thedevkitchen\\CmsSearch\\Model\\Queue\\Log\\Handler'], array_map(static fn ($node): string => ltrim((string) $node, '\\'), $handlers));
        $file = $di->xpath('//virtualType[@name="Thedevkitchen\\CmsSearch\\Model\\Queue\\Log\\Handler"]/arguments/argument[@name="fileName"]');
        $this->assertSame('/var/log/cmssearch_reindex.log', (string) $file[0]);
    }

    public function testTheAfterCommitFlushIsPluggedIntoEveryDbAdapter(): void
    {
        $di = $this->di('etc/di.xml');
        $names = static fn (array $nodes): array => array_map(static fn ($node): string => ltrim((string) $node, '\\'), $nodes);
        $this->assertSame(
            [FlushReindexMessagesPlugin::class],
            $names($di->xpath('//type[@name="Magento\\Framework\\DB\\Adapter\\AdapterInterface"]/plugin/@type'))
        );
        // A proxy: commits that have nothing pending must not build the publisher stack.
        $this->assertSame(
            [MessageSender::class . '\\Proxy'],
            $names($di->xpath('//type[@name="Thedevkitchen\\CmsSearch\\Plugin\\Framework\\DB\\FlushReindexMessagesPlugin"]/arguments/argument[@name="sender"]'))
        );
        $this->assertSame(
            [ReindexPublisher::class],
            $names($di->xpath('//preference[@for="Thedevkitchen\\CmsSearch\\Api\\ReindexPublisherInterface"]/@type'))
        );
        foreach ([MessageSender::class, ReindexPublisher::class, ReindexHandler::class, FlushReindexMessagesPlugin::class, DocumentBuilder::class, PageReindexProcessor::class] as $class) {
            $this->assertSame(
                [self::LOGGER],
                $names($di->xpath('//type[@name="' . $class . '"]/arguments/argument[@name="logger"]')),
                "$class must log to var/log/cmssearch_reindex.log"
            );
        }
    }

    /**
     * The queue logger must write ONLY to var/log/cmssearch_reindex.log. A virtualType of
     * Magento\Framework\Logger\Monolog inherits (merges) the core handler list of that type (system, debug, syslog,
     * Magento_Support's report handler), so the base must be the plain Monolog\Logger, which no DI file configures.
     */
    public function testTheQueueLoggerHasNoInheritedHandlers(): void
    {
        $this->assertSame(\Monolog\Logger::class, $this->virtualTypes()[self::LOGGER] ?? null);
        $root = dirname(__DIR__, 7);
        $files = array_merge(
            [$root . '/app/etc/di.xml'],
            glob($root . '/vendor/*/*/etc/di.xml') ?: [],
            glob($root . '/vendor/*/*/etc/*/di.xml') ?: [],
            glob($root . '/app/code/*/*/etc/di.xml') ?: [],
            glob($root . '/app/code/*/*/etc/*/di.xml') ?: []
        );
        $this->assertGreaterThan(100, count($files), 'the scan must see the vendor DI files');
        foreach ($files as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            $this->assertSame(
                [],
                $xml->xpath('//type[@name="Monolog\\Logger" or @name="\\Monolog\\Logger"]/arguments/argument[@name="handlers"]'),
                "$file configures handlers for every Monolog\\Logger; the queue logger would inherit them"
            );
        }
    }

    public function testOurPostProcessorIsRegisteredOnTheStagingApplier(): void
    {
        $items = $this->di('etc/di.xml')->xpath(
            '//type[@name="Magento\\Staging\\Model\\StagingApplier"]/arguments/argument[@name="postProcessors"]/item'
        );
        $this->assertCount(1, $items);
        $this->assertSame('thedevkitchen_cmssearch_page', (string) $items[0]['name']);
        $this->assertSame('object', (string) $items[0]->attributes('xsi', true)->type);
        $this->assertSame(PageVersionApplied::class, ltrim((string) $items[0], '\\'));
        $this->assertTrue(is_a(PageVersionApplied::class, PostProcessorInterface::class, true));
    }

    public function testInterfaceTypesResolveToTheirImplementation(): void
    {
        foreach (self::IMPLEMENTATIONS as $interface => $class) {
            $this->assertTrue(is_a($class, $interface, true), "$class must implement $interface");
        }
    }

    public function testTheReindexCommandIsRegistered(): void
    {
        $items = $this->di('etc/di.xml')->xpath(
            '//type[@name="Magento\\Framework\\Console\\CommandListInterface"]/arguments/argument[@name="commands"]/item'
        );
        $this->assertSame([ReindexCommand::class], array_map(static fn ($item): string => ltrim((string) $item, '\\'), $items));
        $this->assertSame('thedevkitchen:cmssearch:reindex', ReindexCommand::NAME);
    }
}
