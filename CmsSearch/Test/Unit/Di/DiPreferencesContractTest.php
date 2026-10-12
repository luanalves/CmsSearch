<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Di;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterface;
use Thedevkitchen\CmsSearch\Model\ContentTypeSearchResults;

/**
 * Static contract check of etc/di.xml (no DB, no ObjectManager).
 */
class DiPreferencesContractTest extends TestCase
{
    private function di(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }

    private function exists(string $name): bool
    {
        return class_exists($name) || interface_exists($name);
    }

    public function testSearchResultsImplementsModuleInterface(): void
    {
        $this->assertTrue(is_a(ContentTypeSearchResults::class, ContentTypeSearchResultsInterface::class, true));
    }

    public function testEveryPreferenceSatisfiesItsContract(): void
    {
        $prefs = $this->di()->xpath('//preference');
        $this->assertNotEmpty($prefs);
        foreach ($prefs as $pref) {
            $for = ltrim((string) $pref['for'], '\\');
            $type = ltrim((string) $pref['type'], '\\');
            $this->assertTrue($this->exists($for), "Preference 'for' not found: $for");
            $this->assertTrue($this->exists($type), "Preference type not found: $type");
            $this->assertTrue(is_a($type, $for, true), "$type must implement/extend $for");
        }
    }

    public function testEveryDeclaredTypeAndVirtualTypeClassExists(): void
    {
        foreach ($this->di()->xpath('//type') as $type) {
            $name = ltrim((string) $type['name'], '\\');
            $this->assertTrue($this->exists($name), "<type> class not found: $name");
        }
        foreach ($this->di()->xpath('//virtualType') as $vt) {
            $base = ltrim((string) $vt['type'], '\\');
            $this->assertTrue($this->exists($base), "virtualType base class not found: $base");
        }
    }

    public function testEveryArgumentNameIsAConstructorParameterOfTheResolvedClass(): void
    {
        $virtual = [];
        foreach ($this->di()->xpath('//virtualType') as $vt) {
            $virtual[ltrim((string) $vt['name'], '\\')] = ltrim((string) $vt['type'], '\\');
        }
        $checked = 0;
        foreach ($this->di()->xpath('//type | //virtualType') as $node) {
            $name = ltrim((string) $node['name'], '\\');
            $class = $name;
            for ($i = 0; isset($virtual[$class]) && $i < 20; $i++) {
                $class = $virtual[$class];
            }
            foreach ($node->xpath('arguments/argument') as $argument) {
                $argName = (string) $argument['name'];
                $constructor = (new \ReflectionClass($class))->getConstructor();
                $params = $constructor ? array_map(fn ($p) => $p->getName(), $constructor->getParameters()) : [];
                $this->assertContains(
                    $argName,
                    $params,
                    "$name: argument '$argName' is not a constructor parameter of $class"
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
    }
}
