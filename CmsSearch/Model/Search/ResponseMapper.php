<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Thedevkitchen\CmsSearch\Model\Search\Data\Card;
use Thedevkitchen\CmsSearch\Model\Search\Data\CmsResult;
use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;

/**
 * Turns the raw _search answer into a CmsResult. Defensive by design: a missing or malformed part becomes empty
 * (never a PHP warning on the result page), counts are never negative; a card URL or image that is not an absolute
 * http(s) URL without whitespace, quotes or angle brackets is dropped.
 */
class ResponseMapper
{
    public function map(array $response, SearchRequest $request): CmsResult
    {
        $aggs = is_array($response['aggregations'] ?? null) ? $response['aggregations'] : [];

        $typeCounts = [];
        foreach ($this->list($aggs['types']['codes']['buckets'] ?? null) as $bucket) {
            if (is_array($bucket) && is_string($bucket['key'] ?? null)) {
                $typeCounts[$bucket['key']] = $this->count($bucket['doc_count'] ?? 0);
            }
        }
        $termCounts = [];
        foreach ($this->list($aggs['facets']['terms']['buckets'] ?? null) as $bucket) {
            if (is_array($bucket) && is_numeric($bucket['key'] ?? null)) {
                $termCounts[(int) $bucket['key']] = $this->count($bucket['doc_count'] ?? 0);
            }
        }

        $hits = [];
        $total = 0;
        if ($request->wantsHits()) {
            $hitsPart = is_array($response['hits'] ?? null) ? $response['hits'] : [];
            foreach ($this->list($hitsPart['hits'] ?? null) as $hit) {
                $card = $this->card($hit);
                if ($card !== null) {
                    $hits[] = $card;
                }
            }
            $total = $this->total($hitsPart['total'] ?? 0);
        }

        return new CmsResult(
            $typeCounts,
            $this->count($aggs['categories']['doc_count'] ?? 0),
            $this->count($aggs['category_documents']['present']['doc_count'] ?? 0) > 0,
            $total,
            $hits,
            $termCounts
        );
    }

    private function card(mixed $hit): ?Card
    {
        $source = is_array($hit) && is_array($hit['_source'] ?? null) ? $hit['_source'] : null;
        if ($source === null) {
            return null;
        }
        $title = trim($this->string($source['title'] ?? ''));
        if ($title === '') {
            return null;
        }
        $type = $this->string($source['content_type_code'] ?? '');
        return new Card(
            $this->string($source['entity_type'] ?? QueryBuilder::ENTITY_PAGE),
            $title,
            $this->safeUrl($this->string($source['url'] ?? '')) ?? '',
            $this->safeUrl($this->string($source['image'] ?? '')),
            $this->string($source['summary'] ?? ''),
            $type === '' ? null : $type
        );
    }

    private function total(mixed $total): int
    {
        if (is_array($total)) {
            $total = $total['value'] ?? 0;
        }
        return $this->count($total);
    }

    private function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    private function safeUrl(string $url): ?string
    {
        // \z, not $: PHP's $ also matches before a trailing newline
        return preg_match('#^https?://[^\s"\'<>]+\z#i', $url) === 1 ? $url : null;
    }

    /** @return array<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
