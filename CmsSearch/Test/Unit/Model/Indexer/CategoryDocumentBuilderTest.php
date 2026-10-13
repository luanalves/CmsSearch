<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\CategoryDocumentBuilder;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\CategorySnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\DocumentBuilder;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexDefinition;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostEnrichment;

class CategoryDocumentBuilderTest extends TestCase
{
    private function builder(): CategoryDocumentBuilder
    {
        $extractor = new TextExtractor();
        return new CategoryDocumentBuilder($extractor, new DocumentBuilder($extractor, new \Psr\Log\NullLogger()));
    }

    private static function store(): StoreContext
    {
        return new StoreContext(1, 1, 'default', 'en_US', 'https://app.test/', 'https://app.test/media/', 'home');
    }

    private static function category(string $description, ?string $requestPath = 'gear/bags.html'): CategorySnapshot
    {
        return new CategorySnapshot(38, '1/2/37/38', 3, 'Bags', $description, 0, 'PRODUCTS', true, true, $requestPath, '2026-10-11 09:00:00');
    }

    public function testTheCategoryDocumentHasEveryMappedField(): void
    {
        $document = $this->builder()->build(
            self::category('<p>Bags for <strong>every</strong> trip.</p>'),
            self::store(),
            new HostEnrichment('Landing block text.', [3])
        );
        $this->assertSame([
            'entity_type' => 'category',
            'entity_id' => 38,
            'source_types' => ['pdc'],
            'content_type_code' => null,
            'classification_term_id' => null,
            'classification_ancestor_ids' => [],
            'classification_path' => null,
            'title' => 'Bags',
            'text' => 'Bags for every trip. Landing block text.',
            'summary' => 'Bags for every trip. Landing block text.',
            'url' => 'https://app.test/gear/bags.html',
            'image' => null,
            'store_view_id' => 1,
            'website_id' => 1,
            'segment_ids' => [3],
            'updated_at' => '2026-10-11 09:00:00',
        ], $document);
        $properties = array_keys((new IndexDefinition())->build(self::store())['mappings']['properties']);
        $fields = array_keys($document);
        sort($properties);
        sort($fields);
        $this->assertSame($properties, $fields, 'the strict mapping of the CMS index accepts category documents unchanged');
        $this->assertSame('category_38', CategoryDocumentBuilder::documentId(38));
    }

    public function testWithoutAUrlRewriteTheUrlIsTheCategoryRoute(): void
    {
        $document = $this->builder()->build(self::category('', null), self::store());
        $this->assertSame('https://app.test/catalog/category/view/id/38', $document['url']);
        $this->assertSame(['', '', []], [$document['text'], $document['summary'], $document['segment_ids']]);
    }

    public function testUrlAndScopeIdsComeFromTheStoreView(): void
    {
        $store = new StoreContext(5, 3, 'w2s1v1', 'pt_BR', 'https://five.test/', 'https://five.test/media/', 'home');
        $document = $this->builder()->build(self::category('', '/casa/eletro.html'), $store);
        $this->assertSame(
            ['https://five.test/casa/eletro.html', 5, 3, 'category_38'],
            [$document['url'], $document['store_view_id'], $document['website_id'], CategoryDocumentBuilder::documentId($document['entity_id'])]
        );
    }

    public function testTheTextIsCappedLikePageDocuments(): void
    {
        $document = $this->builder()->build(self::category('<p>' . str_repeat('word ', 20000) . '</p>'), self::store());
        $this->assertSame(DocumentBuilder::MAX_TEXT_LENGTH, mb_strlen($document['text']));
        $this->assertLessThanOrEqual(DocumentBuilder::SUMMARY_LENGTH + 1, mb_strlen($document['summary']));
    }
}
