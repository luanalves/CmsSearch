<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexDefinition;

class IndexDefinitionTest extends TestCase
{
    private function store(string $locale): StoreContext
    {
        return new StoreContext(2, 2, 'w1', $locale, 'https://w1.test/', 'https://w1.test/media/', 'home');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function locales(): array
    {
        return [
            'Brazilian Portuguese' => ['pt_BR', 'brazilian'],
            'European Portuguese' => ['pt_PT', 'portuguese'],
            'US English' => ['en_US', 'english'],
            'British English' => ['en_GB', 'english'],
            'Spanish' => ['es_ES', 'spanish'],
            'German' => ['de_DE', 'german'],
            'unsupported language' => ['ja_JP', 'standard'],
            'empty' => ['', 'standard'],
        ];
    }

    #[DataProvider('locales')]
    public function testTheAnalyzerFollowsTheStoreLocale(string $locale, string $analyzer): void
    {
        $this->assertSame($analyzer, (new IndexDefinition())->getAnalyzer($locale));
    }

    public function testTheMappingIsStrictAndHasExactlyTheDocumentFields(): void
    {
        $definition = (new IndexDefinition())->build($this->store('pt_BR'));
        $this->assertSame('strict', $definition['mappings']['dynamic']);
        $this->assertSame(1, $definition['settings']['index']['number_of_shards']);
        $this->assertSame(IndexDefinition::MAPPING_VERSION, $definition['mappings']['_meta']['mapping_version']);
        $fields = array_keys($definition['mappings']['properties']);
        sort($fields);
        $this->assertSame([
            'classification_ancestor_ids', 'classification_path', 'classification_term_id', 'content_type_code',
            'entity_id', 'entity_type', 'image', 'segment_ids', 'source_types', 'store_view_id', 'summary', 'text',
            'title', 'updated_at', 'url', 'website_id',
        ], $fields);
        $properties = $definition['mappings']['properties'];
        foreach (['entity_type', 'source_types', 'content_type_code', 'classification_path'] as $keyword) {
            $this->assertSame('keyword', $properties[$keyword]['type'], $keyword);
        }
        // ids that may exceed 2^31-1 (message bounds allow 4294967295; Plan 6 product/category, segment ids unsigned int)
        foreach (['entity_id', 'classification_term_id', 'classification_ancestor_ids', 'segment_ids'] as $long) {
            $this->assertSame('long', $properties[$long]['type'], $long);
        }
        foreach (['store_view_id', 'website_id'] as $integer) {
            $this->assertSame('integer', $properties[$integer]['type'], $integer);
        }
        $this->assertSame('date', $properties['updated_at']['type']);
        $this->assertStringContainsString('yyyy-MM-dd HH:mm:ss', $properties['updated_at']['format']);
    }

    public function testDisplayOnlyFieldsAreStoredButNotSearchable(): void
    {
        $properties = (new IndexDefinition())->build($this->store('pt_BR'))['mappings']['properties'];
        foreach (['url', 'image', 'summary'] as $field) {
            $this->assertFalse($properties[$field]['index'], "$field must not be searchable");
        }
        $this->assertArrayNotHasKey('index', $properties['title']);
        $this->assertArrayNotHasKey('index', $properties['text']);
    }

    public function testTitleAndTextUseTheAnalyzerOfTheStoreLocale(): void
    {
        $properties = (new IndexDefinition())->build($this->store('pt_BR'))['mappings']['properties'];
        $this->assertSame(['text', 'brazilian'], [$properties['title']['type'], $properties['title']['analyzer']]);
        $this->assertSame(['text', 'brazilian'], [$properties['text']['type'], $properties['text']['analyzer']]);
        $this->assertSame(['type' => 'keyword', 'ignore_above' => 256], $properties['title']['fields']['keyword']);
        $english = (new IndexDefinition())->build($this->store('en_US'))['mappings']['properties'];
        $this->assertSame('english', $english['text']['analyzer']);
    }
}
