<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Di;

use Magento\Framework\Config\Dom\UrnResolver;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\ProductBlockTextProvider;

/**
 * Plan 6 Task 11 (TS-49): the storefront quick search request (quick_search_container) matches only the searchable
 * attribute fields in 2.4.8 - no "*" / _search match - so the block text field of the native product document is
 * added to its "search" match query; without it the field is indexed but never queried.
 */
class SearchRequestContractTest extends TestCase
{
    private const QUICK_SEARCH = 'quick_search_container';

    private function load(string $path): \SimpleXMLElement
    {
        $this->assertFileExists($path);
        $xml = simplexml_load_file($path);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }

    private function ours(): \SimpleXMLElement
    {
        return $this->load(dirname(__DIR__, 3) . '/etc/search_request.xml');
    }

    public function testTheBlockTextFieldIsAddedToTheQuickSearchMatchQueryOnly(): void
    {
        $xml = $this->ours();
        $this->assertSame([self::QUICK_SEARCH], array_map('strval', $xml->xpath('/requests/request/@query')));
        $queries = $xml->xpath('/requests/request/queries/query');
        $this->assertCount(1, $queries, 'only the match query is extended (no bool clause, filter or aggregation)');
        $this->assertSame(['matchQuery', 'search', '$search_term$'], [
            (string) $queries[0]->attributes('xsi', true)->type,
            (string) $queries[0]['name'],
            (string) $queries[0]['value'],
        ]);
        $matches = $queries[0]->xpath('match');
        $this->assertCount(1, $matches);
        $this->assertSame(ProductBlockTextProvider::FIELD, (string) $matches[0]['field']);
        $this->assertSame('', (string) $matches[0]['matchCondition'], 'the default match condition, like the other attribute fields');
        $this->assertSame([], $xml->xpath('//filters | //aggregations | //dimensions'));
    }

    /** The merge (id attributes request/query/match) must land on the native "search" query of the same request. */
    public function testTheNativeQuickSearchHasThatMatchQueryAndNoCatchAllField(): void
    {
        $native = $this->load(dirname(__DIR__, 7) . '/vendor/magento/module-catalog-search/etc/search_request.xml');
        $query = $native->xpath('/requests/request[@query="' . self::QUICK_SEARCH . '"]/queries/query[@name="search"]');
        $this->assertCount(1, $query);
        $this->assertSame('matchQuery', (string) $query[0]->attributes('xsi', true)->type);
        $this->assertSame('$search_term$', (string) $query[0]['value']);
        $this->assertSame(
            [],
            $native->xpath('/requests/request[@query="' . self::QUICK_SEARCH . '"]//match[@field="*" or @field="_search"]'),
            'if core matches "*" again, this extension is redundant'
        );
        $this->assertSame([], $query[0]->xpath('match[@field="' . ProductBlockTextProvider::FIELD . '"]'));
    }

    public function testTheFileIsValidAgainstTheSearchRequestSchema(): void
    {
        $resolver = new UrnResolver();
        $dom = new \DOMDocument();
        $this->assertTrue($dom->load(dirname(__DIR__, 3) . '/etc/search_request.xml'));
        libxml_set_external_entity_loader([$resolver, 'registerEntityLoader']);
        $previous = libxml_use_internal_errors(true);
        try {
            $valid = $dom->schemaValidate($resolver->getRealPath('urn:magento:framework:Search/etc/search_request.xsd'));
            $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            libxml_set_external_entity_loader(null);
        }
        $this->assertTrue($valid, implode('; ', $errors));
    }
}
