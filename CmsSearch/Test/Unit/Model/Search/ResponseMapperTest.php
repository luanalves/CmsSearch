<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Search\Data\Card;
use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\ResponseMapper;

class ResponseMapperTest extends TestCase
{
    private function request(string $kind = Tab::KIND_CONTENT): SearchRequest
    {
        return new SearchRequest('milho', 2, 2, $kind, $kind === Tab::KIND_CONTENT ? 'news' : null, null, 1, 12);
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        return [
            'timed_out' => false,
            'hits' => [
                'total' => ['value' => 3, 'relation' => 'eq'],
                'hits' => [
                    ['_id' => 'page_5', '_source' => ['entity_type' => 'page', 'content_type_code' => 'news', 'title' => 'Alpha', 'summary' => 'First', 'url' => 'https://w1.test/alpha', 'image' => 'https://w1.test/media/a.jpg']],
                    ['_id' => 'page_6', '_source' => ['entity_type' => 'page', 'content_type_code' => 'news', 'title' => 'Beta', 'summary' => '', 'url' => 'https://w1.test/beta', 'image' => null]],
                ],
            ],
            'aggregations' => [
                'types' => ['doc_count' => 4, 'codes' => ['buckets' => [['key' => 'news', 'doc_count' => 3], ['key' => 'help', 'doc_count' => 1]]]],
                'categories' => ['doc_count' => 2],
                'category_documents' => ['doc_count' => 9, 'present' => ['doc_count' => 5]],
                'facets' => ['doc_count' => 3, 'terms' => ['buckets' => [['key' => 1, 'doc_count' => 2], ['key' => 4, 'doc_count' => 1]]]],
            ],
        ];
    }

    /** @param list<Card> $cards */
    private function rows(array $cards): array
    {
        return array_map(
            static fn (Card $c): array => [$c->getEntityType(), $c->getTitle(), $c->getUrl(), $c->getImage(), $c->getSummary(), $c->getContentTypeCode()],
            $cards
        );
    }

    public function testCountersHitsAndFacetsAreMapped(): void
    {
        $result = (new ResponseMapper())->map($this->response(), $this->request());
        $this->assertSame(['news' => 3, 'help' => 1], $result->getTypeCounts());
        $this->assertSame([2, true, 3], [$result->getCategoryCount(), $result->hasCategoryDocuments(), $result->getTotal()]);
        $this->assertSame([1 => 2, 4 => 1], $result->getTermCounts());
        $this->assertSame(
            [
                ['page', 'Alpha', 'https://w1.test/alpha', 'https://w1.test/media/a.jpg', 'First', 'news'],
                ['page', 'Beta', 'https://w1.test/beta', null, '', 'news'],
            ],
            $this->rows($result->getHits())
        );
    }

    public function testTheProductsTabKeepsOnlyTheCounters(): void
    {
        $result = (new ResponseMapper())->map($this->response(), $this->request(Tab::KIND_PRODUCTS));
        $this->assertSame([[], 0], [$result->getHits(), $result->getTotal()]);
        $this->assertSame(3, $result->getCount('news'));
    }

    public function testAMalformedResponseGivesAnEmptyResultWithoutWarnings(): void
    {
        foreach ([[], ['aggregations' => 'x', 'hits' => ['hits' => 'y', 'total' => 'z']], ['aggregations' => ['types' => 'x', 'facets' => ['terms' => ['buckets' => 'y']]]]] as $response) {
            $result = (new ResponseMapper())->map($response, $this->request());
            $this->assertSame([[], 0, false, 0, [], []], [$result->getTypeCounts(), $result->getCategoryCount(), $result->hasCategoryDocuments(), $result->getTotal(), $result->getHits(), $result->getTermCounts()]);
        }
    }

    public function testMalformedBucketsAndCountsAreSkipped(): void
    {
        $response = ['aggregations' => [
            'types' => ['codes' => ['buckets' => ['x', ['key' => 5, 'doc_count' => 1], ['key' => 'news', 'doc_count' => -4], ['key' => 'help', 'doc_count' => 'many'], ['key' => 'faq', 'doc_count' => 2]]]],
            'categories' => ['doc_count' => -1],
            'category_documents' => ['present' => ['doc_count' => 'yes']],
            'facets' => ['terms' => ['buckets' => [['key' => 'abc', 'doc_count' => 1], ['key' => '9', 'doc_count' => 3], ['doc_count' => 2], ['key' => 4, 'doc_count' => -2]]]],
        ]];
        $result = (new ResponseMapper())->map($response, $this->request());
        $this->assertSame(['news' => 0, 'help' => 0, 'faq' => 2], $result->getTypeCounts());
        $this->assertSame([0, false], [$result->getCategoryCount(), $result->hasCategoryDocuments()]);
        $this->assertSame([9 => 3, 4 => 0], $result->getTermCounts());
    }

    public function testUnsafeOrIncompleteDocumentsAreNeutralised(): void
    {
        $response = ['hits' => ['total' => ['value' => 5], 'hits' => [
            ['_source' => ['title' => '', 'url' => 'https://w1.test/x']],
            'not a hit',
            ['_source' => ['entity_type' => 'page', 'title' => 'Gamma', 'url' => 'javascript:alert(1)', 'image' => '/media/x.jpg', 'summary' => 's']],
            ['_source' => ['entity_type' => 'page', 'title' => 123, 'url' => 'https://w1.test/"onmouseover="x', 'image' => 'http://w1.test/i.png']],
            ['_source' => ['entity_type' => 'page', 'title' => '  ', 'url' => 'https://w1.test/blank']],
            ['_source' => ['entity_type' => 'page', 'title' => ['x'], 'url' => 'https://w1.test/array']],
            ['_source' => ['entity_type' => 'page', 'title' => 'Delta', 'url' => "https://w1.test/a\nb", 'image' => 'https://w1.test/<i>.png', 'summary' => ['x']]],
            ['_source' => ['entity_type' => 'page', 'title' => 'Zeta', 'url' => "https://w1.test/z\n", 'image' => "https://w1.test/z.png\n"]],
            ['_source' => ['entity_type' => 'page', 'title' => 'Eps', 'url' => 'HTTPS://W1.TEST/E', 'image' => 'ftp://w1.test/e.png', 'content_type_code' => '']],
        ]]];
        $this->assertSame(
            [
                ['page', 'Gamma', '', null, 's', null],
                ['page', '123', '', 'http://w1.test/i.png', '', null],
                ['page', 'Delta', '', null, '', null],
                ['page', 'Zeta', '', null, '', null],
                ['page', 'Eps', 'HTTPS://W1.TEST/E', null, '', null],
            ],
            $this->rows((new ResponseMapper())->map($response, $this->request())->getHits())
        );
    }

    public function testALegacyNumericTotalIsAccepted(): void
    {
        $this->assertSame(7, (new ResponseMapper())->map(['hits' => ['total' => 7, 'hits' => []]], $this->request())->getTotal());
    }

    public function testNoCategoryDocumentMeansNoCategoriesTab(): void
    {
        $response = $this->response();
        $response['aggregations']['category_documents']['present']['doc_count'] = 0;
        $this->assertFalse((new ResponseMapper())->map($response, $this->request())->hasCategoryDocuments());
    }
}
