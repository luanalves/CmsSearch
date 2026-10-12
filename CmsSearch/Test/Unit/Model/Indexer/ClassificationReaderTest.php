<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\Indexer\ClassificationReader;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ResourceConnectionStubTrait;

class ClassificationReaderTest extends TestCase
{
    use ResourceConnectionStubTrait;

    private function reader(ResourceConnection $resource): ClassificationReader
    {
        return new ClassificationReader($resource, new TreeBuilder($this->createMock(TermResource::class)));
    }

    public function testManyTermsAndTheirAncestorsAreReadInExactlyTwoQueries(): void
    {
        $resource = $this->resourceReturning([
            [
                ['term_id' => '9', 'website_id' => '2', 'path' => '1/4/9'],
                ['term_id' => '10', 'website_id' => '2', 'path' => '1/10'],
                ['term_id' => '20', 'website_id' => '3', 'path' => '20'],
            ],
            [
                ['term_id' => '1', 'is_active' => '1'], ['term_id' => '4', 'is_active' => '1'],
                ['term_id' => '9', 'is_active' => '1'], ['term_id' => '10', 'is_active' => '1'],
                ['term_id' => '20', 'is_active' => '1'],
            ],
        ]);
        $terms = $this->reader($resource)->read([9, 10, 20, 10]);
        $this->assertSame([9, 10, 20], array_keys($terms));
        $this->assertSame(
            [9, 2, '1/4/9', [1, 4, 9], true],
            [$terms[9]->getTermId(), $terms[9]->getWebsiteId(), $terms[9]->getPath(), $terms[9]->getAncestorIds(), $terms[9]->isEffectiveActive()]
        );
        $this->assertSame([[1, 10], 3], [$terms[10]->getAncestorIds(), $terms[20]->getWebsiteId()]);
        $this->assertSame([['term_id IN (?)', [9, 10, 20]], ['term_id IN (?)', [1, 4, 9, 10, 20]]], $this->wheres);
    }

    public function testLargeIdSetsAreReadInBoundedChunksInBothStages(): void
    {
        $resource = $this->resourceReturning([
            [['term_id' => '9', 'website_id' => '2', 'path' => '1/4/9'], ['term_id' => '10', 'website_id' => '2', 'path' => '1/10']],
            [['term_id' => '20', 'website_id' => '3', 'path' => '20']],
            [['term_id' => '1', 'is_active' => '1'], ['term_id' => '4', 'is_active' => '1']],
            [['term_id' => '9', 'is_active' => '1'], ['term_id' => '10', 'is_active' => '0']],
            [['term_id' => '20', 'is_active' => '1']],
        ]);
        $terms = (new ClassificationReader($resource, new TreeBuilder($this->createMock(TermResource::class)), 2))
            ->read([20, 9, 10]);
        $this->assertSame([9, 10, 20], array_keys($terms));
        $this->assertSame([true, false, true], [$terms[9]->isEffectiveActive(), $terms[10]->isEffectiveActive(), $terms[20]->isEffectiveActive()]);
        $this->assertSame(
            [['term_id IN (?)', [9, 10]], ['term_id IN (?)', [20]],
                ['term_id IN (?)', [1, 4]], ['term_id IN (?)', [9, 10]], ['term_id IN (?)', [20]]],
            $this->wheres
        );
    }

    public function testAnInactiveAncestorMakesTheWholeBranchInactive(): void
    {
        $resource = $this->resourceReturning([
            [['term_id' => '9', 'website_id' => '2', 'path' => '1/4/9'], ['term_id' => '5', 'website_id' => '2', 'path' => '1/5']],
            [
                ['term_id' => '1', 'is_active' => '1'], ['term_id' => '4', 'is_active' => '0'],
                ['term_id' => '5', 'is_active' => '1'], ['term_id' => '9', 'is_active' => '1'],
            ],
        ]);
        $terms = $this->reader($resource)->read([9, 5]);
        $this->assertFalse($terms[9]->isEffectiveActive());
        $this->assertTrue($terms[5]->isEffectiveActive());
    }

    public function testAMissingAncestorRowOrAPathNotEndingWithTheTermIsNotActive(): void
    {
        $resource = $this->resourceReturning([
            [['term_id' => '9', 'website_id' => '2', 'path' => '1/4/9'], ['term_id' => '6', 'website_id' => '2', 'path' => '1/7']],
            [['term_id' => '1', 'is_active' => '1'], ['term_id' => '9', 'is_active' => '1'], ['term_id' => '7', 'is_active' => '1']],
        ]);
        $terms = $this->reader($resource)->read([9, 6]);
        $this->assertFalse($terms[9]->isEffectiveActive());
        $this->assertFalse($terms[6]->isEffectiveActive());
    }

    public function testUnknownTermsAreAbsentAndNoInputMeansNoQuery(): void
    {
        $this->assertSame([], $this->reader($this->resourceReturning([[]]))->read([404]));
        $this->wheres = [];
        $this->assertSame([], $this->reader($this->resourceReturning())->read([]));
        $this->assertSame([], $this->wheres);
    }
}
