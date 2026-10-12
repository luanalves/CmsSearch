<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;

/**
 * Settings and explicit mapping of a store view's CMS search index (engineering 3.4, spec 4.1). "dynamic: strict"
 * rejects any field that is not declared here; a mapping change needs a full rebuild (--sync) and a new
 * MAPPING_VERSION.
 */
class IndexDefinition
{
    public const MAPPING_VERSION = 1;

    /** Exact locale, then language => OpenSearch built-in language analyzer. */
    public const ANALYZERS = [
        'pt_BR' => 'brazilian',
        'pt' => 'portuguese',
        'en' => 'english',
        'es' => 'spanish',
        'fr' => 'french',
        'de' => 'german',
        'it' => 'italian',
        'nl' => 'dutch',
    ];

    public const FALLBACK_ANALYZER = 'standard';

    public function getAnalyzer(string $locale): string
    {
        $language = strtolower(explode('_', $locale, 2)[0]);
        return self::ANALYZERS[$locale] ?? self::ANALYZERS[$language] ?? self::FALLBACK_ANALYZER;
    }

    /**
     * @return array{settings: array<string, mixed>, mappings: array<string, mixed>}
     */
    public function build(StoreContext $store): array
    {
        $analyzer = $this->getAnalyzer($store->getLocale());
        $keyword = ['type' => 'keyword'];
        $integer = ['type' => 'integer'];
        $long = ['type' => 'long']; // entity/term/segment ids are unsigned int in MySQL (up to 4294967295)
        return [
            'settings' => ['index' => ['number_of_shards' => 1]],
            'mappings' => [
                'dynamic' => 'strict',
                '_meta' => [
                    'module' => 'Thedevkitchen_CmsSearch',
                    'mapping_version' => self::MAPPING_VERSION,
                    'store_id' => $store->getStoreId(),
                    'locale' => $store->getLocale(),
                ],
                'properties' => [
                    'entity_type' => $keyword,
                    'entity_id' => $long,
                    'source_types' => $keyword,
                    'content_type_code' => $keyword,
                    'classification_term_id' => $long,
                    'classification_ancestor_ids' => $long,
                    'classification_path' => $keyword,
                    'title' => [
                        'type' => 'text',
                        'analyzer' => $analyzer,
                        'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                    'text' => ['type' => 'text', 'analyzer' => $analyzer],
                    'summary' => ['type' => 'text', 'index' => false],
                    'url' => ['type' => 'keyword', 'index' => false],
                    'image' => ['type' => 'keyword', 'index' => false],
                    'store_view_id' => $integer,
                    'website_id' => $integer,
                    'segment_ids' => $long,
                    'updated_at' => [
                        'type' => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss||strict_date_optional_time||epoch_second',
                    ],
                ],
            ],
        ];
    }
}
