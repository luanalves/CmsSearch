<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Acl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the ACL structurally: every admin controller declares the resource the spec assigns to it, every
 * resource exists in acl.xml under the right parent, and no controller is left out of the contract.
 */
class AclContractTest extends TestCase
{
    private const NS = 'Thedevkitchen\\CmsSearch\\Controller\\Adminhtml\\';
    private const TYPES = 'Thedevkitchen_CmsSearch::content_types';
    private const CLS = 'Thedevkitchen_CmsSearch::classification';

    /** @var array<string, array<string, string>> controller folder => controller => expected ADMIN_RESOURCE */
    private const EXPECTED = [
        'ContentType' => [
            'Index' => self::TYPES,
            'Edit' => self::TYPES,
            'NewAction' => self::TYPES . '_create',
            'Save' => self::TYPES . '_edit',
            'InlineEdit' => self::TYPES . '_edit',
            'MassStatus' => self::TYPES . '_edit',
            'Delete' => self::TYPES . '_delete',
            'MassDelete' => self::TYPES . '_delete',
        ],
        'Classification' => [
            'Index' => self::CLS,
            'Edit' => self::CLS,
            'NewAction' => self::CLS . '_manage',
            'Save' => self::CLS . '_manage',
            'Delete' => self::CLS . '_manage',
        ],
    ];

    /** @return string[] */
    private function aclIds(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/acl.xml');
        return array_map('strval', $xml->xpath('//resource/@id'));
    }

    /** @return string[] ids of the direct children of an ACL resource */
    private function children(string $resource): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/acl.xml');
        $ids = array_map('strval', $xml->xpath('//resource[@id="' . $resource . '"]/resource/@id'));
        sort($ids);
        return $ids;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function controllers(): array
    {
        $cases = [];
        foreach (self::EXPECTED as $folder => $controllers) {
            foreach ($controllers as $name => $resource) {
                $cases["$folder\\$name"] = ["$folder\\$name", $resource];
            }
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
        foreach (self::EXPECTED as $folder => $controllers) {
            $files = glob(dirname(__DIR__, 3) . '/Controller/Adminhtml/' . $folder . '/*.php');
            $found = array_values(array_filter(
                array_map(static fn (string $file): string => basename($file, '.php'), $files),
                static fn (string $name): bool => !str_starts_with($name, 'Abstract')
            ));
            sort($found);
            $declared = array_keys($controllers);
            sort($declared);
            $this->assertSame($declared, $found, "Add every new $folder controller to EXPECTED with its ACL resource");
        }
    }

    public function testContentTypeActionResourcesAreChildrenOfTheViewResource(): void
    {
        $this->assertSame(
            [self::TYPES . '_create', self::TYPES . '_delete', self::TYPES . '_edit'],
            $this->children(self::TYPES)
        );
    }

    public function testClassificationManageIsTheOnlyChildOfTheViewResource(): void
    {
        $this->assertSame([self::CLS . '_manage'], $this->children(self::CLS));
        $this->assertContains(self::CLS, $this->children('Thedevkitchen_CmsSearch::root'));
    }

    public function testAbstractControllerConstantsMatchTheAclIds(): void
    {
        $abstract = self::NS . 'Classification\\AbstractClassification';
        $this->assertSame(self::CLS, constant($abstract . '::RESOURCE_VIEW'));
        $this->assertSame(self::CLS . '_manage', constant($abstract . '::RESOURCE_MANAGE'));
    }
}
