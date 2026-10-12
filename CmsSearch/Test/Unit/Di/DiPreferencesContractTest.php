<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Di;

use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermSearchResultsInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterface;
use Thedevkitchen\CmsSearch\Model\ClassificationTermSearchResults;
use Thedevkitchen\CmsSearch\Model\ContentTypeSearchResults;
use Thedevkitchen\CmsSearch\Plugin\Cms\PageFormDataPlugin;
use Thedevkitchen\CmsSearch\Ui\DataProvider\Page\Form\Modifier\SearchFieldset;

/**
 * Static contract check of etc/di.xml and etc/adminhtml/di.xml (no DB, no ObjectManager).
 */
class DiPreferencesContractTest extends TestCase
{
    private const POOL = 'Thedevkitchen\\CmsSearch\\Ui\\DataProvider\\Page\\Form\\Modifier\\Pool';

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
}
