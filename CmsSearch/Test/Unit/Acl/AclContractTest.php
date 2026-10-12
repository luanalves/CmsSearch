<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Acl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards TS-47/TS-48 at the structural level: every admin controller must declare the ACL resource
 * the spec assigns to it, and every resource must exist in acl.xml under the right parent.
 */
class AclContractTest extends TestCase
{
    private const NS = 'Thedevkitchen\\CmsSearch\\Controller\\Adminhtml\\ContentType\\';
    private const VIEW = 'Thedevkitchen_CmsSearch::content_types';

    /** @var array<string, string> controller => expected ADMIN_RESOURCE */
    private const EXPECTED = [
        'Index' => self::VIEW,
        'Edit' => self::VIEW,
        'NewAction' => self::VIEW . '_create',
        'Save' => self::VIEW . '_edit',
        'InlineEdit' => self::VIEW . '_edit',
        'MassStatus' => self::VIEW . '_edit',
        'Delete' => self::VIEW . '_delete',
        'MassDelete' => self::VIEW . '_delete',
    ];

    /** @return string[] */
    private function aclIds(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/acl.xml');
        return array_map('strval', $xml->xpath('//resource/@id'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function controllers(): array
    {
        $cases = [];
        foreach (self::EXPECTED as $name => $resource) {
            $cases[$name] = [$name, $resource];
        }
        return $cases;
    }

    #[DataProvider('controllers')]
    public function testControllerDeclaresTheExpectedResource(string $controller, string $expected): void
    {
        $class = self::NS . $controller;
        $this->assertTrue(class_exists($class), $class . ' must exist');
        $this->assertSame($expected, constant($class . '::ADMIN_RESOURCE'));
        $this->assertContains($expected, $this->aclIds());
    }

    public function testNoControllerIsMissingFromTheContract(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/Controller/Adminhtml/ContentType/*.php');
        $found = array_diff(
            array_map(static fn (string $file): string => basename($file, '.php'), $files),
            ['AbstractContentType']
        );
        sort($found);
        $declared = array_keys(self::EXPECTED);
        sort($declared);
        $this->assertSame($declared, array_values($found), 'Add every new controller to EXPECTED with its ACL resource');
    }

    public function testActionResourcesAreChildrenOfTheViewResource(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/acl.xml');
        $children = array_map(
            'strval',
            $xml->xpath('//resource[@id="' . self::VIEW . '"]/resource/@id')
        );
        sort($children);
        $this->assertSame(
            [self::VIEW . '_create', self::VIEW . '_delete', self::VIEW . '_edit'],
            $children
        );
    }
}
