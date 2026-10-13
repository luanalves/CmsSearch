<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\Search\ClassificationTerms;

class ClassificationTermsTest extends TestCase
{
    private TreeBuilder&MockObject $builder;

    protected function setUp(): void
    {
        $node = static fn (int $id, ?int $parent, string $code, string $label, array $children = []): array => [
            'term_id' => $id, 'parent_id' => $parent, 'code' => $code, 'label' => $label, 'path' => (string) $id,
            'level' => 0, 'sort_order' => 0, 'is_active' => true, 'effective_active' => true, 'children' => $children,
        ];
        $this->builder = $this->createMock(TreeBuilder::class);
        $this->builder->method('build')->willReturn([
            $node(1, null, 'agro', 'Agro', [$node(4, 1, 'milho', 'Milho')]),
            $node(2, null, 'pecas', 'Pecas'),
        ]);
    }

    public function testTheTreeIsTheEffectivelyActiveTreeOfTheWebsiteWithStoreViewLabels(): void
    {
        $this->builder->expects($this->once())->method('build')->with(2, 3, true);
        $this->assertSame(
            [
                ['term_id' => 1, 'code' => 'agro', 'label' => 'Agro', 'children' => [
                    ['term_id' => 4, 'code' => 'milho', 'label' => 'Milho', 'children' => []],
                ]],
                ['term_id' => 2, 'code' => 'pecas', 'label' => 'Pecas', 'children' => []],
            ],
            (new ClassificationTerms($this->builder))->tree(2, 3)
        );
    }

    public function testTheTreeIsBuiltOncePerWebsiteAndStoreView(): void
    {
        $this->builder->expects($this->exactly(2))->method('build');
        $terms = new ClassificationTerms($this->builder);
        $terms->tree(2, 3);
        $terms->findByCode(2, 3, 'agro');
        $terms->tree(2, 4);
    }

    public function testACodeIsFoundAtAnyDepth(): void
    {
        $this->assertSame(
            ['term_id' => 4, 'code' => 'milho', 'label' => 'Milho'],
            (new ClassificationTerms($this->builder))->findByCode(2, 3, 'milho')
        );
    }

    public function testAnUnknownOrInactiveCodeIsNotFound(): void
    {
        $this->assertNull((new ClassificationTerms($this->builder))->findByCode(2, 3, 'soja'));
    }
}
