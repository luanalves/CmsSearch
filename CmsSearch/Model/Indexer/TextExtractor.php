<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Thedevkitchen\CmsSearch\Model\Indexer\Text\Directive;
use Thedevkitchen\CmsSearch\Model\Indexer\Text\ExtractedContent;

/**
 * Raw CMS content (Page Builder or legacy WYSIWYG HTML) -> plain text, WITHOUT rendering it (rendering needs a store
 * context and would run arbitrary widgets). Engineering 5.10-B:
 * - style/script/noscript/template, embeds and the Page Builder image/video/map content types carry no text;
 * - the Page Builder "html" content type is stored entity-escaped: its decoded text is parsed again (depth-limited);
 * - every {{...}} directive is removed from the text and returned as a Directive (block/widget text is Plan 6).
 */
class TextExtractor
{
    /** Levels of escaped Page Builder "html" content that are decoded; deeper ones are dropped. */
    public const MAX_HTML_DEPTH = 2;

    private const DIRECTIVE = '/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)(.*?)\}\}/s';
    private const ANY_DIRECTIVE = '/\{\{.*?\}\}/s';
    private const PARAM = '/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/';

    private const SKIP_TAGS = [
        'head', 'style', 'script', 'noscript', 'template', 'iframe', 'object', 'embed', 'video', 'audio', 'svg',
        'canvas', 'select', 'textarea',
    ];
    private const SKIP_CONTENT_TYPES = ['image', 'video', 'map'];
    private const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'br', 'button', 'caption', 'dd', 'div', 'dl', 'dt', 'figcaption',
        'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p',
        'pre', 'section', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    public function extract(string $html): ExtractedContent
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_scrub($html, 'UTF-8');
        }
        if (trim($html) === '') {
            return new ExtractedContent('', [], null);
        }
        $document = $this->load($html);
        return new ExtractedContent($this->text($document, 0), $this->directives($html), $this->firstImage($document));
    }

    private function load(string $html): \DOMDocument
    {
        // Same UTF-8 handling as Magento\PageBuilder\Model\Filter\Template: without a declared charset libxml reads
        // bytes as ISO-8859-1, so every non-ASCII character goes in as a numeric entity.
        $source = '<html><body>' . mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8') . '</body></html>';
        $document = $this->parse($source, LIBXML_NONET | LIBXML_COMPACT, $tooDeep);
        if ($tooDeep) {
            // libxml refuses more than 255 nested elements and then yields an EMPTY document; retry with its higher
            // (still bounded) limit instead of silently indexing a page without text.
            $document = $this->parse($source, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE, $tooDeep);
        }
        return $document;
    }

    private function parse(string $source, int $options, ?bool &$tooDeep = null): \DOMDocument
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($source, $options);
            $tooDeep = false;
            foreach (libxml_get_errors() as $error) {
                if (str_contains($error->message, 'Excessive depth')) {
                    $tooDeep = true;
                    break;
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $document;
    }

    private function text(\DOMDocument $document, int $depth): string
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        $parts = [];
        $this->collect($body, $parts, $depth);
        $text = preg_replace(self::ANY_DIRECTIVE, ' ', implode('', $parts)) ?? '';
        return trim(preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $text) ?? '');
    }

    /**
     * @param list<string> $parts
     */
    private function collect(\DOMNode $node, array &$parts, int $depth): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) { // CDATA sections are DOMText too; comments are not
                $parts[] = (string) $child->nodeValue;
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            $contentType = $child->getAttribute('data-content-type');
            if (in_array($tag, self::SKIP_TAGS, true) || in_array($contentType, self::SKIP_CONTENT_TYPES, true)) {
                continue;
            }
            if ($contentType === 'html') {
                // stored escaped: the parser decoded it once, so its text content is the user's HTML
                $parts[] = ' ';
                if ($depth < self::MAX_HTML_DEPTH) {
                    $parts[] = $this->text($this->load((string) $child->textContent), $depth + 1);
                }
                $parts[] = ' ';
                continue;
            }
            $block = in_array($tag, self::BLOCK_TAGS, true);
            if ($block) {
                $parts[] = ' ';
            }
            $this->collect($child, $parts, $depth);
            if ($block) {
                $parts[] = ' ';
            }
        }
    }

    private function firstImage(\DOMDocument $document): ?string
    {
        foreach ($document->getElementsByTagName('img') as $image) {
            $source = trim($image->getAttribute('src'));
            if ($source !== '') {
                return $source;
            }
        }
        return null;
    }

    /**
     * @return list<Directive>
     */
    private function directives(string $html): array
    {
        // Escaped "html" content keeps its directives entity-encoded ({{widget type=&quot;...&quot;}}).
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all(self::DIRECTIVE, $decoded, $matches, PREG_SET_ORDER);
        $directives = [];
        $seen = [];
        foreach ($matches as $match) {
            if (isset($seen[$match[0]])) {
                continue;
            }
            $seen[$match[0]] = true;
            $directives[] = new Directive(strtolower($match[1]), $this->params($match[2]), $match[0]);
        }
        return $directives;
    }

    /**
     * @return array<string, string>
     */
    private function params(string $source): array
    {
        preg_match_all(self::PARAM, $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $params = [];
        foreach ($matches as $match) {
            $params[(string) $match[1]] = (string) ($match[2] ?? $match[3] ?? $match[4] ?? '');
        }
        return $params;
    }
}
