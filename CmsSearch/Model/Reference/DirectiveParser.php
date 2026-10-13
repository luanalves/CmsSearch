<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Thedevkitchen\CmsSearch\Model\Indexer\Text\Directive;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;

/**
 * Block references of raw CMS content (engineering 5.5 and 5.10-B). The reference always comes from the directive;
 * the Page Builder wrapper (data-content-type) is only a hint:
 * - {{widget type="Magento\Cms\Block\Widget\Block" block_id="N|identifier"}} (Page Builder "block", widget insert);
 * - {{block id="N|identifier"}} and {{block class="Magento\Cms\Block\Block" block_id="..."}} (legacy WYSIWYG; as in
 *   Magento\Email\Model\Template\Filter::blockDirective, "class" wins over "id", and any other class is no CMS block);
 * - {{widget type="Magento\Banner\Block\Widget\Banner" banner_ids="N,M" display_mode="fixed"}} (Dynamic Blocks). The
 *   rotator modes "salesrule"/"catalogrule" pick banners from the visitor's cart and price rules: like customer
 *   segments (D7) they depend on the visitor, so they publish nothing in v1.
 */
class DirectiveParser
{
    public const CMS_BLOCK_WIDGET = 'Magento\\Cms\\Block\\Widget\\Block';
    public const DYNAMIC_BLOCK_WIDGET = 'Magento\\Banner\\Block\\Widget\\Banner';
    public const CMS_BLOCK_CLASS = 'Magento\\Cms\\Block\\Block';
    public const FIXED_MODE = 'fixed';
    public const MAX_IDENTIFIER_LENGTH = 255;

    public function __construct(private readonly TextExtractor $textExtractor)
    {
    }

    /**
     * @return list<BlockRef>
     */
    public function parseHtml(string $html): array
    {
        return trim($html) === '' ? [] : $this->parse($this->textExtractor->extract($html)->getDirectives());
    }

    /**
     * @param list<Directive> $directives
     * @return list<BlockRef> unique by BlockRef::key(), in order of first appearance
     */
    public function parse(array $directives): array
    {
        $refs = [];
        foreach ($directives as $directive) {
            foreach ($this->refsOf($directive) as $ref) {
                $refs[$ref->key()] ??= $ref;
            }
        }
        return array_values($refs);
    }

    /** "Magento\\Cms\\..." (escaped in some stored content) and "\Magento\Cms\..." name the same class. */
    public static function normalizeClass(string $class): string
    {
        $class = trim($class);
        while (str_contains($class, '\\\\')) {
            $class = str_replace('\\\\', '\\', $class);
        }
        return ltrim($class, '\\');
    }

    /** A CMS block_id value: digits = block_id, anything else = identifier (Magento loads it by identifier). */
    public function cmsBlockRef(string $value): ?BlockRef
    {
        $value = trim($value);
        if (preg_match('/^[0-9]{1,10}$/', $value) === 1) {
            $id = (int) $value;
            return $id >= 1 && $id <= ReindexMessage::MAX_BLOCK_ID ? new BlockRef(BlockRef::KIND_BLOCK, $id) : null;
        }
        if ($value === '' || mb_strlen($value) > self::MAX_IDENTIFIER_LENGTH
            || preg_match('/[\\\\"\'<>{}\x00-\x1f]/', $value) !== 0) {
            // Backslash, quotes, brackets or control characters mean a broken attribute (for example an escaped
            // quote cut the value short): not an identifier anybody can have saved on purpose.
            return null;
        }
        return new BlockRef(BlockRef::KIND_BLOCK, 0, $value);
    }

    /**
     * @return list<BlockRef>
     */
    public function dynamicBlockRefs(string $bannerIds, string $displayMode): array
    {
        // As the storefront: Banner widget getDisplayMode() turns an empty value into "fixed", and banner.js picks
        // the list by exact key (items[displayMode]): case-sensitive, not trimmed.
        $mode = in_array($displayMode, ['', '0'], true) ? self::FIXED_MODE : $displayMode;
        if ($mode !== self::FIXED_MODE) {
            return [];
        }
        $refs = [];
        foreach (explode(',', $bannerIds) as $part) {
            $part = trim($part);
            if (preg_match('/^[0-9]{1,10}$/', $part) === 1 && (int) $part >= 1 && (int) $part <= ReindexMessage::MAX_ENTITY_ID) {
                $ref = new BlockRef(BlockRef::KIND_DYNAMIC_BLOCK, (int) $part);
                $refs[$ref->key()] ??= $ref;
            }
        }
        return array_values($refs);
    }

    /**
     * @return list<BlockRef>
     */
    private function refsOf(Directive $directive): array
    {
        if ($directive->getName() === 'widget') {
            $type = self::normalizeClass((string) $directive->getParam('type'));
            if ($type === self::CMS_BLOCK_WIDGET) {
                $ref = $this->cmsBlockRef((string) $directive->getParam('block_id'));
                return $ref === null ? [] : [$ref];
            }
            if ($type === self::DYNAMIC_BLOCK_WIDGET) {
                return $this->dynamicBlockRefs(
                    (string) $directive->getParam('banner_ids'),
                    $directive->getParam('display_mode') ?? self::FIXED_MODE
                );
            }
            return [];
        }
        if ($directive->getName() === 'block') {
            $class = $directive->getParam('class');
            if ($class !== null) {
                $ref = self::normalizeClass($class) === self::CMS_BLOCK_CLASS
                    ? $this->cmsBlockRef((string) $directive->getParam('block_id'))
                    : null;
            } else {
                $ref = $this->cmsBlockRef((string) $directive->getParam('id'));
            }
            return $ref === null ? [] : [$ref];
        }
        return [];
    }
}
