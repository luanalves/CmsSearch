<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;

/**
 * Body of the single _search request of a result page (spec 5.6 steps 2-3). The query (q + store view scope) drives
 * the aggregations, so the tab counters never depend on the term filter nor the page; the active tab and the term
 * are applied as post_filter to the hits only. The facet counts are scoped to the active tab, not to the term (a
 * parent term counts its descendants through classification_ancestor_ids).
 *
 * The query text is only ever the value of a multi_match query (analysed as plain text, no query syntax), so
 * query_string metacharacters in q are harmless. Bounded here too, whatever the request holds: q to
 * MAX_QUERY_LENGTH characters (a much longer q exceeds OpenSearch's maxClauseCount of 1024 and fails the request) and
 * the page window (from + size never exceeds OpenSearch's max_result_window).
 */
class QueryBuilder
{
    public const ENTITY_PAGE = 'page';
    public const ENTITY_CATEGORY = 'category';
    /** Upper bound of content types counted (one bucket per active type). */
    public const TYPE_BUCKETS = 100;
    /** Upper bound of classification terms counted for the facets of one tab. */
    public const TERM_BUCKETS = 1000;
    /** What a card needs; the full `text` is never loaded. */
    public const SOURCE_FIELDS = ['entity_type', 'content_type_code', 'title', 'summary', 'url', 'image'];
    /** Characters of q sent to OpenSearch (spec 5.9). */
    public const MAX_QUERY_LENGTH = 256;

    /**
     * @return array<string, mixed>
     */
    public function build(SearchRequest $request): array
    {
        $body = [
            'size' => 0,
            'track_total_hits' => true,
            'query' => [
                'bool' => [
                    'must' => [[
                        'multi_match' => [
                            'query' => mb_substr($request->getQueryText(), 0, self::MAX_QUERY_LENGTH),
                            'fields' => ['title^3', 'text'],
                            'type' => 'cross_fields',
                            'operator' => 'and',
                        ],
                    ]],
                    'filter' => [
                        ['term' => ['store_view_id' => $request->getStoreId()]],
                        ['term' => ['website_id' => $request->getWebsiteId()]],
                    ],
                ],
            ],
            'aggs' => [
                'types' => [
                    'filter' => ['term' => ['entity_type' => self::ENTITY_PAGE]],
                    'aggs' => ['codes' => ['terms' => ['field' => 'content_type_code', 'size' => self::TYPE_BUCKETS]]],
                ],
                'categories' => ['filter' => ['term' => ['entity_type' => self::ENTITY_CATEGORY]]],
                // whatever the query: does this store view's index hold category documents at all (Plan 6)?
                'category_documents' => [
                    'global' => new \stdClass(),
                    'aggs' => ['present' => ['filter' => ['term' => ['entity_type' => self::ENTITY_CATEGORY]]]],
                ],
            ],
        ];
        if (!$request->wantsHits()) {
            return $body;
        }

        $content = $request->getActiveKind() === Tab::KIND_CONTENT;
        $tabFilter = $content
            ? [
                ['term' => ['entity_type' => self::ENTITY_PAGE]],
                ['term' => ['content_type_code' => (string) $request->getContentTypeCode()]],
            ]
            : [['term' => ['entity_type' => self::ENTITY_CATEGORY]]];
        $hitFilter = $tabFilter;
        if ($content && $request->getTermId() !== null) {
            $hitFilter[] = ['term' => ['classification_ancestor_ids' => $request->getTermId()]];
        }

        $size = max(1, min($request->getPageSize(), TabResolver::MAX_RESULT_WINDOW));
        $body['size'] = $size;
        $body['from'] = max(0, min($request->getFrom(), TabResolver::MAX_RESULT_WINDOW - $size));
        $body['_source'] = self::SOURCE_FIELDS;
        $body['sort'] = ['_score', ['entity_id' => 'asc']];
        $body['post_filter'] = ['bool' => ['filter' => $hitFilter]];
        if ($content) {
            $body['aggs']['facets'] = [
                'filter' => ['bool' => ['filter' => $tabFilter]],
                'aggs' => ['terms' => ['terms' => ['field' => 'classification_ancestor_ids', 'size' => self::TERM_BUCKETS]]],
            ];
        }
        return $body;
    }
}
