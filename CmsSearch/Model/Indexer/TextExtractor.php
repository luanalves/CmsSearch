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
 * - every {{...}} directive is removed from the text and returned as a Directive (block/widget text is Plan 6);
 * - content hidden by Page Builder (display:none / visibility:hidden rules keyed by data-pb-style, inline style, the
 *   hidden attribute), comments, noscript and template are pruned BEFORE text, directives and the first image are read;
 * - input is capped (MAX_INPUT_BYTES) and so is the collected text (MAX_TEXT_CHARS); see ExtractedContent::isTruncated().
 * No regular expression runs over unbounded user content with backtracking: {{...}} is found with strpos and a failed
 * PCRE call throws instead of silently yielding empty text.
 */
class TextExtractor
{
    /** Levels of escaped Page Builder "html" content that are decoded; deeper ones are dropped. */
    public const MAX_HTML_DEPTH = 2;
    /** Bytes of raw content that are read (cut at a UTF-8 character boundary). */
    public const MAX_INPUT_BYTES = 1048576;
    /** Characters of text collected before the traversal stops (4 x the 65 536 stored by the document builder). */
    public const MAX_TEXT_CHARS = 262144;

    private const PARAM = '/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/';
    private const MARK_OPEN = "\u{E000}";
    private const MARK_CLOSE = "\u{E001}";
    private const MARK = '/\x{E000}(\d+)\x{E001}/u';

    /** Never read: not rendered content. */
    private const DROP_TAGS = ['head', 'style', 'script', 'noscript', 'template'];
    /** No text, but images and directives (src, href...) are still read. */
    private const MEDIA_TAGS = ['iframe', 'object', 'embed', 'video', 'audio', 'svg', 'canvas', 'select', 'textarea'];
    private const MEDIA_CONTENT_TYPES = ['image', 'video', 'map'];
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
        $truncated = false;
        if (strlen($html) > self::MAX_INPUT_BYTES) {
            $html = mb_strcut($html, 0, self::MAX_INPUT_BYTES, 'UTF-8');
            $truncated = true;
        }
        if (trim($html) === '') {
            return new ExtractedContent('', [], null, $truncated);
        }
        $html = $this->check(preg_replace('/[\x{E000}\x{E001}]/u', '', $html)); // our directive markers
        [$masked, $table] = $this->maskDirectives($html);
        $state = ['parts' => [], 'length' => 0, 'truncated' => $truncated, 'directives' => [], 'seen' => [],
            'image' => null, 'table' => $table];
        $this->collectDocument($this->load($masked), 0, $state);

        $text = $this->check(preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', implode('', $state['parts'])));
        return new ExtractedContent(trim($text), $state['directives'], $state['image'], $state['truncated']);
    }

    /**
     * Replaces every {{...}} by a marker (linear scan; an unclosed "{{" stays literal text). Markers survive the HTML
     * parser inside text and attribute values, so nested quotes such as href="{{store url="x"}}" cannot break it.
     *
     * @return array{0: string, 1: list<string>} masked html, the raw directives by marker index
     */
    private function maskDirectives(string $html): array
    {
        $out = [];
        $table = [];
        $offset = 0;
        while (($open = strpos($html, '{{', $offset)) !== false) {
            $close = strpos($html, '}}', $open + 2);
            if ($close === false) {
                break;
            }
            $out[] = substr($html, $offset, $open - $offset);
            $out[] = self::MARK_OPEN . count($table) . self::MARK_CLOSE;
            $table[] = substr($html, $open, $close + 2 - $open);
            $offset = $close + 2;
        }
        $out[] = substr($html, $offset);
        return [implode('', $out), $table];
    }

    private function load(string $html): \DOMDocument
    {
        // Same UTF-8 handling as Magento\PageBuilder\Model\Filter\Template: without a declared charset libxml reads
        // bytes as ISO-8859-1, so every non-ASCII character goes in as a numeric entity.
        $source = '<html><body>' . mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8') . '</body></html>';
        $document = $this->parse($source, LIBXML_NONET | LIBXML_COMPACT, $tooDeep);
        if ($tooDeep) {
            // libxml refuses more than 255 nested elements and then yields an EMPTY document; retry with its higher
            // (still bounded, the input is capped) limit instead of silently indexing a page without text.
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

    /**
     * @param array<string, mixed> $state
     */
    private function collectDocument(\DOMDocument $document, int $depth, array &$state): void
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return;
        }
        $css = '';
        foreach ($document->getElementsByTagName('style') as $style) {
            $css .= $style->textContent . "\n";
        }
        $this->collect($body, $this->hiddenStyleIds($css), $depth, true, $state);
    }

    /**
     * Page Builder stores "hide" as a top-level rule `#html-body [data-pb-style=ID]{display:none}` (rules inside
     * @media are device specific and do not hide the element everywhere, so they are ignored).
     *
     * @return array<string, true>
     */
    private function hiddenStyleIds(string $css): array
    {
        $ids = [];
        $length = strlen($css);
        $offset = 0;
        $depth = 0;
        $selectorStart = 0;
        $ruleStart = 0;
        while ($offset < $length) {
            $next = strcspn($css, '{}', $offset);
            $position = $offset + $next;
            if ($position >= $length) {
                break;
            }
            if ($css[$position] === '{') {
                if ($depth === 0) {
                    $selectorStart = $ruleStart;
                    $ruleStart = $position + 1;
                }
                $depth++;
            } else {
                $depth--;
                if ($depth === 0) {
                    $selector = substr($css, $selectorStart, $ruleStart - 1 - $selectorStart);
                    $declarations = substr($css, $ruleStart, $position - $ruleStart);
                    if (!str_contains($selector, '@') && $this->hides($declarations)
                        && preg_match_all('/\[data-pb-style=([A-Za-z0-9_-]+)\]/', $selector, $found)) {
                        foreach ($found[1] as $id) {
                            $ids[$id] = true;
                        }
                    }
                    $ruleStart = $position + 1;
                } elseif ($depth < 0) {
                    $depth = 0;
                    $ruleStart = $position + 1;
                }
            }
            $offset = $position + 1;
        }
        return $ids;
    }

    private function hides(string $declarations): bool
    {
        return (bool) preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:!\s*important\s*)?(?:;|$)/i', $declarations);
    }

    /**
     * @param array<string, true> $hiddenIds
     * @param array<string, mixed> $state
     */
    private function collect(\DOMNode $node, array $hiddenIds, int $depth, bool $withText, array &$state): void
    {
        foreach ($node->childNodes as $child) {
            if ($state['length'] > self::MAX_TEXT_CHARS) {
                $state['truncated'] = true;
                return;
            }
            if ($child instanceof \DOMText) { // CDATA sections are DOMText too; comments are not
                $this->addText((string) $child->nodeValue, $withText, $state);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::DROP_TAGS, true) || $this->isHidden($child, $hiddenIds)) {
                continue;
            }
            $this->readAttributes($child, $state);
            if ($tag === 'img') {
                $this->readImage($child, $state);
            }
            $contentType = $child->getAttribute('data-content-type');
            if ($contentType === 'html' && $withText) {
                // stored escaped: the parser decoded it once, so its text content is the user's HTML
                $state['parts'][] = ' ';
                if ($depth < self::MAX_HTML_DEPTH) {
                    $this->collectDocument($this->load((string) $child->textContent), $depth + 1, $state);
                }
                $state['parts'][] = ' ';
                continue;
            }
            $textual = $withText && !in_array($tag, self::MEDIA_TAGS, true)
                && !in_array($contentType, self::MEDIA_CONTENT_TYPES, true);
            $block = $textual && in_array($tag, self::BLOCK_TAGS, true);
            if ($block) {
                $state['parts'][] = ' ';
            }
            $this->collect($child, $hiddenIds, $depth, $textual, $state);
            if ($block) {
                $state['parts'][] = ' ';
            }
        }
    }

    /**
     * @param array<string, true> $hiddenIds
     */
    private function isHidden(\DOMElement $element, array $hiddenIds): bool
    {
        if ($element->hasAttribute('hidden')) {
            return true;
        }
        $style = $element->getAttribute('style');
        if ($style !== '' && $this->hides($style)) {
            return true;
        }
        $pbStyle = $element->getAttribute('data-pb-style');
        return $pbStyle !== '' && isset($hiddenIds[$pbStyle]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function addText(string $value, bool $withText, array &$state): void
    {
        if (str_contains($value, self::MARK_OPEN)) {
            $this->check(preg_match_all(self::MARK, $value, $found));
            foreach ($found[1] as $index) {
                $this->exposeDirective((int) $index, $state);
            }
            $value = $this->check(preg_replace(self::MARK, ' ', $value));
        }
        if ($withText) {
            $state['parts'][] = $value;
            $state['length'] += mb_strlen($value);
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function readAttributes(\DOMElement $element, array &$state): void
    {
        foreach ($element->attributes ?? [] as $attribute) {
            if (str_contains($attribute->value, self::MARK_OPEN)) {
                $this->check(preg_match_all(self::MARK, $attribute->value, $found));
                foreach ($found[1] as $index) {
                    $this->exposeDirective((int) $index, $state);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function readImage(\DOMElement $image, array &$state): void
    {
        $source = trim($image->getAttribute('src'));
        if ($state['image'] !== null || $source === '') {
            return;
        }
        $state['image'] = $this->check(preg_replace_callback(
            self::MARK,
            fn (array $match): string => $this->decode($state['table'][(int) $match[1]] ?? ''),
            $source
        ));
    }

    private function decode(string $raw): string
    {
        // Escaped "html" content keeps its directives entity-encoded ({{widget type=&quot;...&quot;}}).
        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $state
     */
    private function exposeDirective(int $index, array &$state): void
    {
        $raw = $this->decode($state['table'][$index] ?? '');
        if ($raw === '' || isset($state['seen'][$raw])) {
            return;
        }
        $state['seen'][$raw] = true;
        $parsed = $this->check(preg_match('/^\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)(.*)\}\}$/s', $raw, $match));
        if ($parsed === 1) {
            $state['directives'][] = new Directive(strtolower($match[1]), $this->params($match[2]), $raw);
        }
    }

    /**
     * @return array<string, string>
     */
    private function params(string $source): array
    {
        $this->check(preg_match_all(self::PARAM, $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL));
        $params = [];
        foreach ($matches as $match) {
            $params[(string) $match[1]] = (string) ($match[2] ?? $match[3] ?? $match[4] ?? '');
        }
        return $params;
    }

    /**
     * A failed PCRE call (null/false) must never turn into "no text": fail loudly, without echoing content.
     *
     * @template T
     * @param T|null|false $result
     * @return T
     */
    private function check(mixed $result): mixed
    {
        if ($result === null || $result === false) {
            throw new \UnexpectedValueException('Text extraction failed (regular expression error ' . preg_last_error() . ').');
        }
        return $result;
    }
}
