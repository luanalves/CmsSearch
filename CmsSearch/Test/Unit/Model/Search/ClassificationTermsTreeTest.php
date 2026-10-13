<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;
use Thedevkitchen\CmsSearch\Model\Search\ClassificationTerms;

/**
 * ClassificationTerms over the REAL TreeBuilder (only the resource is mocked): website scoping, effective-active
 * semantics, label fallback and the number of resource reads.
 */
class ClassificationTermsTreeTest extends TestCase
{
    private TermResource&MockObject $resource;

    /** @param array<string, mixed> $over */
    private static function row(int $id, ?int $parent, string $code, array $over = []): array
    {
        return $over + [
            'term_id' => $id, 'parent_id' => $parent, 'code' => $code, 'path' => (string) $id, 'level' => 0,
            'sort_order' => 0, 'is_active' => true,
        ];
    }

    private function terms(): ClassificationTerms
    {
        $site = [
            2 => [
                self::row(1, null, 'agro'),
                self::row(2, 1, 'milho', ['sort_order' => 2]),
                self::row(3, 1, 'soja', ['sort_order' => 1]),
                self::row(4, null, 'off', ['is_active' => false]),
                self::row(5, 4, 'child_of_off'),
                self::row(6, 5, 'grandchild_of_off'),
            ],
            3 => [self::row(9, null, 'other_site')],
        ];
        $this->resource = $this->createMock(TermResource::class);
        $this->resource->method('fetchTreeRows')->willReturnCallback(static fn (int $w): array => $site[$w] ?? []);
        $this->resource->method('fetchLabels')->willReturn([
            1 => [0 => 'Agro', 7 => 'Agronegocio'],
            2 => [0 => 'Milho'],
            3 => [],
        ]);
        return new ClassificationTerms(new TreeBuilder($this->resource));
    }

    public function testAnInactiveTermAndItsWholeSubtreeAreHidden(): void
    {
        $codes = [];
        $walk = static function (array $nodes) use (&$walk, &$codes): void {
            foreach ($nodes as $n) {
                $codes[] = $n['code'];
                $walk($n['children']);
            }
        };
        $terms = $this->terms();
        $walk($terms->tree(2, 7));
        $this->assertSame(['agro', 'soja', 'milho'], $codes);
        foreach (['off', 'child_of_off', 'grandchild_of_off'] as $hidden) {
            $this->assertNull($terms->findByCode(2, 7, $hidden));
        }
    }

    public function testLabelsFallBackFromTheStoreViewToTheDefaultLabelToTheCode(): void
    {
        $terms = $this->terms();
        $this->assertSame('Agronegocio', $terms->findByCode(2, 7, 'agro')['label']);
        $this->assertSame('Milho', $terms->findByCode(2, 7, 'milho')['label']);
        $this->assertSame('soja', $terms->findByCode(2, 7, 'soja')['label']);
    }

    public function testATermOfAnotherWebsiteIsNeverFound(): void
    {
        $terms = $this->terms();
        $this->assertNull($terms->findByCode(2, 7, 'other_site'));
        $this->assertNull($terms->findByCode(3, 7, 'agro'));
        $this->assertSame('other_site', $terms->findByCode(3, 7, 'other_site')['code']);
    }

    public function testEachWebsiteAndStoreViewCostsOneRowReadAndOneLabelRead(): void
    {
        $terms = $this->terms();
        $this->resource->expects($this->exactly(2))->method('fetchTreeRows');
        $this->resource->expects($this->exactly(2))->method('fetchLabels');
        $terms->tree(2, 7);
        $terms->findByCode(2, 7, 'milho');
        $terms->findByCode(2, 7, 'soja');
        $terms->findByCode(2, 7, 'nope');
        $terms->tree(3, 7);
        $terms->tree(3, 7);
    }
}
