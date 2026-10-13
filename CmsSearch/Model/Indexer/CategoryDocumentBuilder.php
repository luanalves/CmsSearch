<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Thedevkitchen\CmsSearch\Model\Indexer\Data\CategorySnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostEnrichment;

/**
 * The document of a category page (PDC) in the CMS search index (spec 4.1/5.7, Categories tab): no content type and
 * no classification (D2), title = name, text = description text + the text of the blocks the category page publishes
 * (description directives, landing page block, widget instances), capped like page documents. url = the store view's
 * base URL + the category's url_rewrite request path (catalog/category/view/id/<id> when it has none), image null.
 * Same strict mapping as page documents (no mapping change). Its _id is category_<entity_id> (no collision with
 * page_<page_id>). text and summary are plain text: the search UI escapes them on output.
 */
class CategoryDocumentBuilder
{
    public const ENTITY_TYPE = 'category';
    public const SOURCE_TYPE = 'pdc';

    public function __construct(
        private readonly TextExtractor $textExtractor,
        private readonly DocumentBuilder $documentBuilder
    ) {
    }

    public static function documentId(int $categoryId): string
    {
        return self::ENTITY_TYPE . '_' . $categoryId;
    }

    /**
     * @return array<string, mixed> exactly the fields of the strict mapping
     */
    public function build(CategorySnapshot $category, StoreContext $store, ?HostEnrichment $enrichment = null): array
    {
        $parts = [
            trim($this->textExtractor->extract($category->getDescription())->getText()),
            trim($enrichment?->getText() ?? ''),
        ];
        // description first, then block text: the cap cuts block text first
        $text = mb_substr(
            implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '')),
            0,
            DocumentBuilder::MAX_TEXT_LENGTH
        );
        $path = $category->getRequestPath();
        return [
            'entity_type' => self::ENTITY_TYPE,
            'entity_id' => $category->getCategoryId(),
            'source_types' => [self::SOURCE_TYPE],
            'content_type_code' => null,
            'classification_term_id' => null,
            'classification_ancestor_ids' => [],
            'classification_path' => null,
            'title' => $category->getName(),
            'text' => $text,
            'summary' => $this->documentBuilder->summary('', $text),
            'url' => rtrim($store->getBaseUrl(), '/') . '/'
                . ($path !== null ? ltrim($path, '/') : 'catalog/category/view/id/' . $category->getCategoryId()),
            'image' => null,
            'store_view_id' => $store->getStoreId(),
            'website_id' => $store->getWebsiteId(),
            'segment_ids' => $enrichment?->getSegmentIds() ?? [], // D7: excluded Dynamic Blocks
            'updated_at' => $category->getUpdatedAt(),
        ];
    }
}
