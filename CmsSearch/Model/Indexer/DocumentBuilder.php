<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\ContentTypeState;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PreparedContent;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\TermState;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostEnrichment;

/**
 * The search document of a visible CMS page in one store view (spec 4.1 / engineering 3.4). Its _id is
 * deterministic (page_<page_id>) so every write is an idempotent upsert.
 *
 * The expensive part (text extraction) does not depend on the store view. Contract for the indexer: call prepare()
 * ONCE per page, lazily on the first store view where the page is visible, and NOT AT ALL when the page is invisible
 * in every store view (nothing to extract for a document that will be deleted); then pass the same PreparedContent
 * to build() for every store view of that page (build() refuses the content of another page).
 *
 * The text includes the blocks the page publishes (Plan 6, HostEnrichment, per store view: block visibility is
 * store-scoped); blocks never get a document of their own (D2). Order: content heading, page text, block text; the
 * MAX_TEXT_LENGTH cap applies to the whole, so block text is cut first and the page text is never displaced. The
 * enrichment adds no field (the strict mapping v1 is unchanged): segment_ids carries the segment ids of the excluded
 * segment-restricted Dynamic Blocks (D7).
 *
 * text and summary are plain text, not HTML: the consumer of the index (Plan 5) must escape them on output.
 */
class DocumentBuilder
{
    public const ENTITY_TYPE = 'page';
    public const SOURCE_TYPE = 'cms';
    /** Characters of text kept per document (the largest real page gives about 15 000). */
    public const MAX_TEXT_LENGTH = 65536;
    /** Storage cap of the summary; the display length is a Plan 5 setting. */
    public const SUMMARY_LENGTH = 300;
    /** A cut text goes back to the last space only when it is at most this many characters away. */
    private const WORD_BOUNDARY_WINDOW = 512;

    private const MEDIA_DIRECTIVE = '/^\{\{\s*media\s+url\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s}"\']+))\s*\}\}$/i';

    public function __construct(
        private readonly TextExtractor $textExtractor,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function documentId(int $pageId): string
    {
        return self::ENTITY_TYPE . '_' . $pageId;
    }

    /**
     * Store-view independent part of the document; call once per page.
     */
    public function prepare(PageSnapshot $page): PreparedContent
    {
        $content = $this->textExtractor->extract($page->getContent());
        $text = trim($page->getContentHeading() . ' ' . $content->getText());
        $bounded = $this->bound($text);
        if ($content->isTruncated() || $bounded !== $text) {
            // Page id only: never content.
            $this->logger->warning('CMS page text truncated for indexing', ['page_id' => $page->getPageId()]);
        }
        return new PreparedContent($page->getPageId(), $bounded, $this->summary($page->getMetaDescription(), $bounded), $content->getFirstImage());
    }

    /**
     * @param PreparedContent $prepared result of prepare() for THIS page
     * @param HostEnrichment|null $enrichment text and excluded segment ids of the blocks the page publishes in $store
     * @return array<string, mixed> exactly the fields of the strict mapping
     * @throws \LogicException when $prepared belongs to another page
     */
    public function build(
        PageSnapshot $page,
        ContentTypeState $type,
        TermState $term,
        StoreContext $store,
        PreparedContent $prepared,
        ?HostEnrichment $enrichment = null
    ): array {
        if ($prepared->getPageId() !== $page->getPageId()) {
            throw new \LogicException(sprintf(
                'Prepared content of page %d used for page %d.',
                $prepared->getPageId(),
                $page->getPageId()
            ));
        }
        $text = $prepared->getText();
        $summary = $prepared->getSummary();
        $blockText = trim($enrichment?->getText() ?? '');
        if ($blockText !== '') {
            // page text first (already bounded), then the block text of this store view; the cap cuts block text
            $joined = $text === '' ? $blockText : $text . ' ' . $blockText;
            $bounded = $this->bound($joined);
            if ($enrichment->isTruncated() || $bounded !== $joined) {
                // Ids only: never content.
                $this->logger->warning(
                    'CMS page block text truncated for indexing',
                    ['page_id' => $page->getPageId(), 'store_id' => $store->getStoreId()]
                );
            }
            $text = $bounded;
            $summary = $this->summary($page->getMetaDescription(), $text);
        }
        return [
            'entity_type' => self::ENTITY_TYPE,
            'entity_id' => $page->getPageId(),
            'source_types' => [self::SOURCE_TYPE],
            'content_type_code' => $type->getCode(),
            'classification_term_id' => $term->getTermId(),
            'classification_ancestor_ids' => $term->getAncestorIds(),
            'classification_path' => $term->getPath(),
            'title' => $page->getTitle(),
            'text' => $text,
            'summary' => $summary,
            'url' => $this->url($page->getIdentifier(), $store),
            'image' => $this->image($prepared->getFirstImage(), $store),
            'store_view_id' => $store->getStoreId(),
            'website_id' => $store->getWebsiteId(),
            'segment_ids' => $enrichment?->getSegmentIds() ?? [], // D7: excluded Dynamic Blocks
            'updated_at' => $this->updatedAt($page->getUpdateTime()),
        ];
    }

    /** Character-safe cap; cuts at the last space when one is close enough, else exactly at the cap. */
    private function bound(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_TEXT_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_TEXT_LENGTH);
        if (preg_match('/\s/u', mb_substr($text, self::MAX_TEXT_LENGTH, 1)) !== 1) {
            $space = mb_strrpos($cut, ' ');
            if ($space !== false && $space >= self::MAX_TEXT_LENGTH - self::WORD_BOUNDARY_WINDOW) {
                $cut = mb_substr($cut, 0, $space);
            }
        }
        return rtrim($cut);
    }

    /**
     * Plain-text summary: the meta description when it has text, else the start of $text (also used by the category
     * documents).
     */
    public function summary(string $metaDescription, string $text): string
    {
        $meta = preg_replace('/\{\{.*?\}\}/s', ' ', $metaDescription) ?? '';
        // Strip, decode, strip again: an entity-encoded tag (&lt;b&gt;) must not survive as markup.
        $meta = strip_tags(html_entity_decode(strip_tags($meta), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $source = trim(preg_replace('/\s+/u', ' ', $meta) ?? '');
        if ($source === '') {
            $source = $text;
        }
        if (mb_strlen($source) <= self::SUMMARY_LENGTH) {
            return $source;
        }
        $cut = mb_substr($source, 0, self::SUMMARY_LENGTH);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int) (self::SUMMARY_LENGTH * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, ' ,.;:-') . '…';
    }

    private function url(string $identifier, StoreContext $store): string
    {
        $segments = array_values(array_filter(explode('/', $identifier), static fn (string $part): bool => $part !== ''));
        $path = implode('/', $segments);
        $base = $store->getBaseUrl();
        if ($path === trim($store->getHomeIdentifier(), '/')) {
            return $base;
        }
        return rtrim($base, '/') . '/' . implode('/', array_map('rawurlencode', $segments));
    }

    private function image(?string $source, StoreContext $store): ?string
    {
        if ($source === null) {
            return null;
        }
        if (preg_match(self::MEDIA_DIRECTIVE, $source, $match, PREG_UNMATCHED_AS_NULL) === 1) {
            $path = ltrim((string) ($match[1] ?? $match[2] ?? $match[3]), '/');
            if ($path === '' || str_contains($path, '..')) {
                return null;
            }
            $segments = array_filter(explode('/', $path), static fn (string $part): bool => $part !== '');
            return rtrim($store->getMediaUrl(), '/') . '/' . implode('/', array_map('rawurlencode', $segments));
        }
        // Absolute URLs are kept as written (http is not rewritten to https, a CDN may only serve http);
        // only a safe character set is accepted: no quotes, angle brackets, spaces or control characters.
        return preg_match("#^https?://[A-Za-z0-9\-._~:/?\#\[\]@!$&()*+,;=%]+$#", $source) === 1 ? $source : null;
    }

    private function updatedAt(?string $time): ?string
    {
        if ($time === null || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }
        return $time;
    }
}
