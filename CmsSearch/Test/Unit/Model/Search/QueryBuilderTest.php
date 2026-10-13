<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\QueryBuilder;
use Thedevkitchen\CmsSearch\Model\Search\TabResolver;

class QueryBuilderTest extends TestCase
{
    private function request(string $kind, ?string $code = null, ?int $term = null, int $page = 1, int $size = 12): SearchRequest
    {
        return new SearchRequest('milho verde', 2, 2, $kind, $code, $term, $page, $size);
    }

    /** @return array<string, mixed> */
    private function build(SearchRequest $request): array
    {
        return (new QueryBuilder())->build($request);
    }

    public function testTheProductsTabOnlyAsksForTheCounters(): void
    {
        $body = $this->build($this->request(Tab::KIND_PRODUCTS));
        $this->assertSame(0, $body['size']);
        $this->assertTrue($body['track_total_hits']);
        foreach (['from', 'post_filter', '_source', 'sort'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }
        $this->assertSame(['types', 'categories', 'category_documents'], array_keys($body['aggs']));
        $this->assertSame(['size', 'track_total_hits', 'query', 'aggs'], array_keys($body));
        $this->assertSame(
            [['multi_match' => ['query' => 'milho verde', 'fields' => ['title^3', 'text'], 'type' => 'cross_fields', 'operator' => 'and']]],
            $body['query']['bool']['must']
        );
        $this->assertSame(
            [['term' => ['store_view_id' => 2]], ['term' => ['website_id' => 2]]],
            $body['query']['bool']['filter']
        );
    }

    public function testTheCountersAggregateContentTypesAndCategories(): void
    {
        $aggs = $this->build($this->request(Tab::KIND_PRODUCTS))['aggs'];
        $this->assertSame(
            ['filter' => ['term' => ['entity_type' => 'page']], 'aggs' => ['codes' => ['terms' => ['field' => 'content_type_code', 'size' => QueryBuilder::TYPE_BUCKETS]]]],
            $aggs['types']
        );
        $this->assertSame(['filter' => ['term' => ['entity_type' => 'category']]], $aggs['categories']);
        $this->assertInstanceOf(\stdClass::class, $aggs['category_documents']['global']);
        $this->assertSame(
            ['present' => ['filter' => ['term' => ['entity_type' => 'category']]]],
            $aggs['category_documents']['aggs']
        );
        $this->assertStringContainsString('"global":{}', (string) json_encode($aggs));
    }

    public function testAContentTabPagesThroughItsDocumentsAndAggregatesItsTerms(): void
    {
        $body = $this->build($this->request(Tab::KIND_CONTENT, 'news', null, 3, 12));
        $this->assertSame([12, 24], [$body['size'], $body['from']]);
        $this->assertSame(QueryBuilder::SOURCE_FIELDS, $body['_source']);
        $this->assertSame(['types', 'categories', 'category_documents', 'facets'], array_keys($body['aggs']));
        $tab = [['term' => ['entity_type' => 'page']], ['term' => ['content_type_code' => 'news']]];
        $this->assertSame(['bool' => ['filter' => $tab]], $body['post_filter']);
        $this->assertSame(
            ['filter' => ['bool' => ['filter' => $tab]], 'aggs' => ['terms' => ['terms' => ['field' => 'classification_ancestor_ids', 'size' => QueryBuilder::TERM_BUCKETS]]]],
            $body['aggs']['facets']
        );
    }

    public function testATermFiltersTheHitsButNeitherTheFacetsNorTheCounters(): void
    {
        $plain = $this->build($this->request(Tab::KIND_CONTENT, 'news'));
        $filtered = $this->build($this->request(Tab::KIND_CONTENT, 'news', 7));
        $this->assertSame(
            ['bool' => ['filter' => [
                ['term' => ['entity_type' => 'page']],
                ['term' => ['content_type_code' => 'news']],
                ['term' => ['classification_ancestor_ids' => 7]],
            ]]],
            $filtered['post_filter']
        );
        $this->assertSame($plain['aggs']['facets'], $filtered['aggs']['facets']);
        $products = $this->build($this->request(Tab::KIND_PRODUCTS));
        $this->assertEquals($products['aggs']['types'], $filtered['aggs']['types']);
        $this->assertEquals($products['aggs']['categories'], $filtered['aggs']['categories']);
        $this->assertEquals($products['query'], $filtered['query']);
    }

    public function testTheCategoriesTabHasNoClassificationFacets(): void
    {
        $body = $this->build($this->request(Tab::KIND_CATEGORIES, null, 7));
        $this->assertSame(['bool' => ['filter' => [['term' => ['entity_type' => 'category']]]]], $body['post_filter']);
        $this->assertArrayNotHasKey('facets', $body['aggs']);
    }

    public function testTheOrderIsDeterministic(): void
    {
        $body = $this->build($this->request(Tab::KIND_CONTENT, 'news'));
        $this->assertSame(['_score', ['entity_id' => 'asc']], $body['sort']);
    }

    public function testTheCardsNeverLoadTheFullText(): void
    {
        $this->assertNotContains('text', QueryBuilder::SOURCE_FIELDS);
        $this->assertSame(['entity_type', 'content_type_code', 'title', 'summary', 'url', 'image'], QueryBuilder::SOURCE_FIELDS);
        $this->assertSame(QueryBuilder::SOURCE_FIELDS, $this->build($this->request(Tab::KIND_CATEGORIES))['_source']);
    }

    public function testTheCountersAndTheFacetsAreTheSameOnEveryPage(): void
    {
        $first = $this->build($this->request(Tab::KIND_CONTENT, 'news', 7, 1));
        $later = $this->build($this->request(Tab::KIND_CONTENT, 'news', 7, 5));
        $this->assertSame([0, 48], [$first['from'], $later['from']]);
        $this->assertEquals($first['aggs'], $later['aggs']);
        $this->assertEquals($first['query'], $later['query']);
        $this->assertSame($first['post_filter'], $later['post_filter']);
    }

    public function testTheStoreViewScopeComesFromTheRequest(): void
    {
        $body = $this->build(new SearchRequest('x', 6, 3, Tab::KIND_PRODUCTS, null, null, 1, 12));
        $this->assertSame(
            [['term' => ['store_view_id' => 6]], ['term' => ['website_id' => 3]]],
            $body['query']['bool']['filter']
        );
    }

    /** @return array<string, array{string}> */
    public static function hostileQueries(): array
    {
        return [
            'query_string syntax' => ['title:* OR _exists_:text AND (a~2 "b" [c TO d]) \\ / ^3 !x -y +z && ||'],
            'json-looking' => ['{"match_all":{}}'],
            'markup' => ['<script>alert(1)</script>'],
            'unicode' => ["informa\u{00E7}\u{00F5}es \u{1F600} \u{200B}\u{0301}"],
            'empty' => [''],
            'very long' => [str_repeat('palavra ', 2000)],
        ];
    }

    #[DataProvider('hostileQueries')]
    public function testTheQueryTextIsOnlyEverAMultiMatchValue(string $q): void
    {
        $body = (new QueryBuilder())->build(new SearchRequest($q, 2, 2, Tab::KIND_CONTENT, 'news', 7, 2, 12));
        $this->assertSame(mb_substr($q, 0, QueryBuilder::MAX_QUERY_LENGTH), $body['query']['bool']['must'][0]['multi_match']['query']);
        $json = (string) json_encode($body);
        foreach (['query_string', 'simple_query_string', 'script', 'regexp', 'wildcard', 'highlight'] as $unsafe) {
            $this->assertStringNotContainsString('"' . $unsafe . '"', $json);
        }
        $withoutQuery = $body;
        unset($withoutQuery['query']['bool']['must']);
        $this->assertStringNotContainsString(json_encode($q) ?: "\0", (string) json_encode($withoutQuery));
    }

    public function testTheQueryTextIsCutToItsMaximumLengthInCharacters(): void
    {
        $this->assertSame(256, QueryBuilder::MAX_QUERY_LENGTH);
        $q = str_repeat("\u{00E7}", 300);
        $body = (new QueryBuilder())->build(new SearchRequest($q, 2, 2, Tab::KIND_PRODUCTS, null, null, 1, 12));
        $this->assertSame(str_repeat("\u{00E7}", 256), $body['query']['bool']['must'][0]['multi_match']['query']);
        $exact = str_repeat('b', 256);
        $body = (new QueryBuilder())->build(new SearchRequest($exact, 2, 2, Tab::KIND_PRODUCTS, null, null, 1, 12));
        $this->assertSame($exact, $body['query']['bool']['must'][0]['multi_match']['query']);
    }

    /** @return array<string, array{int, int, int, int}> page, page size => size, from */
    public static function pages(): array
    {
        $window = TabResolver::MAX_RESULT_WINDOW;
        return [
            'first page' => [1, 12, 12, 0],
            'last allowed page' => [833, 12, 12, 9984],
            'past the window' => [834, 12, 12, $window - 12],
            'far past the window' => [99999, 60, 60, $window - 60],
            'page zero' => [0, 12, 12, 0],
            'negative page' => [-3, 12, 12, 0],
            'page size zero' => [2, 0, 1, 0],
            'negative page size' => [2, -5, 1, 0],
            'page size above the window' => [1, 20000, $window, 0],
        ];
    }

    #[DataProvider('pages')]
    public function testThePaginationStaysInsideTheResultWindow(int $page, int $pageSize, int $size, int $from): void
    {
        $body = $this->build($this->request(Tab::KIND_CONTENT, 'news', null, $page, $pageSize));
        $this->assertSame([$size, $from], [$body['size'], $body['from']]);
        $this->assertLessThanOrEqual(TabResolver::MAX_RESULT_WINDOW, $body['size'] + $body['from']);
    }
}
