<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostEnrichment;

/**
 * Text of the blocks each host publishes in a store view, from the graph's direct references (refresh the graph
 * first): only blocks the store view displays (BlockReader: current version, active, store-scoped), nested blocks
 * followed up to BlockReferenceResolver::MAX_DEPTH, each block once per host (cycle-safe). Identifier references are
 * resolved again by identifier, like the storefront does. Segment-restricted Dynamic Blocks add no text in v1 but
 * report their segment ids (D7). Order: the host's references in graph order (document order of directives, then
 * widget placements), nested blocks breadth-first; the text per host is capped at MAX_TEXT_CHARS.
 */
class BlockTextResolver
{
    /**
     * Characters of block text kept per host (the document builder stores 65 536 characters of `text` in total, so
     * more could never be indexed); the host's text is cut there and flagged truncated.
     */
    public const MAX_TEXT_CHARS = 65536;

    public function __construct(
        private readonly ReferenceStore $referenceStore,
        private readonly BlockReader $blockReader,
        private readonly DirectiveParser $directiveParser,
        private readonly TextExtractor $textExtractor
    ) {
    }

    /**
     * @param array<int|string> $hostIds
     * @return array<int, HostEnrichment> one entry per requested host id
     */
    public function forHosts(string $hostType, array $hostIds, int $storeId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $hostIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $texts = array_fill_keys($ids, []);
        $segments = array_fill_keys($ids, []);
        $lengths = array_fill_keys($ids, 0);
        $truncated = array_fill_keys($ids, false);
        $seenRefs = [];
        $doneBlocks = [];
        $frontier = [];
        foreach ($this->referenceStore->directRefs($hostType, $ids, $storeId) as $hostId => $rows) {
            foreach ($rows as $row) {
                $ref = self::refOf($row);
                if ($ref !== null) {
                    $frontier[] = [(int) $hostId, $ref, 0];
                }
            }
        }
        while ($frontier !== []) {
            $fresh = [];
            foreach ($frontier as [$hostId, $ref, $depth]) {
                if (!isset($seenRefs[$hostId][$ref->key()])) {
                    $seenRefs[$hostId][$ref->key()] = true;
                    $fresh[] = [$hostId, $ref, $depth];
                }
            }
            if ($fresh === []) {
                break;
            }
            $blocks = $this->blockReader->read(array_map(static fn (array $node): BlockRef => $node[1], $fresh), $storeId);
            $next = [];
            foreach ($fresh as [$hostId, $ref, $depth]) {
                $block = $blocks[$ref->key()] ?? null;
                if ($block === null || isset($doneBlocks[$hostId][$block->key()])) {
                    continue;
                }
                $doneBlocks[$hostId][$block->key()] = true;
                if ($block->hasSegments()) {
                    array_push($segments[$hostId], ...$block->getSegmentIds());
                    continue;
                }
                $content = $this->textExtractor->extract($block->getContent());
                $truncated[$hostId] = $truncated[$hostId] || $content->isTruncated();
                $text = $content->getText();
                if ($text !== '') {
                    $room = self::MAX_TEXT_CHARS - $lengths[$hostId] - ($texts[$hostId] === [] ? 0 : 1);
                    if (mb_strlen($text) > $room) {
                        $text = rtrim(mb_substr($text, 0, max(0, $room)));
                        $truncated[$hostId] = true;
                    }
                    if ($text !== '') {
                        $lengths[$hostId] += mb_strlen($text) + ($texts[$hostId] === [] ? 0 : 1);
                        $texts[$hostId][] = $text;
                    }
                }
                if ($depth < BlockReferenceResolver::MAX_DEPTH) {
                    foreach ($this->directiveParser->parse($content->getDirectives()) as $child) {
                        $next[] = [$hostId, $child, $depth + 1];
                    }
                }
            }
            $frontier = $next;
        }
        $result = [];
        foreach ($ids as $id) {
            $segmentIds = array_values(array_unique($segments[$id]));
            sort($segmentIds);
            $result[$id] = new HostEnrichment(implode(' ', $texts[$id]), $segmentIds, $truncated[$id]);
        }
        return $result;
    }

    /**
     * @param array{block_kind: string, block_id: int, block_identifier: string, segment_ids: ?string} $row
     */
    private static function refOf(array $row): ?BlockRef
    {
        try {
            if ($row['block_identifier'] !== '') {
                return new BlockRef($row['block_kind'], 0, $row['block_identifier']);
            }
            return $row['block_id'] > 0 ? new BlockRef($row['block_kind'], $row['block_id']) : null;
        } catch (\InvalidArgumentException) {
            return null; // a row written by an older version with an unknown kind: ignored
        }
    }
}
