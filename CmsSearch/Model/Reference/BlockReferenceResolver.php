<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\BlockReferenceResolverInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockSource;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSelector;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostSource;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetInstance;

/**
 * Builds the reference graph of hosts in one store view. Direct references (depth 0) come from the host's content
 * directives, its extra references (category landing page) and the widget instances of the store view's theme whose
 * placement matches the host (All Pages placements match no host: user decision, see WidgetInstanceResolver).
 * Nested references are found by walking the content of the blocks the store view displays, level by level (one
 * BlockReader call per level for the whole batch), up to MAX_DEPTH; every (host, reference, source) is recorded once,
 * which also stops cycles. Hidden or missing blocks keep their structural row (so creating, renaming or re-activating
 * them finds the host) but are not followed; segment-restricted Dynamic Blocks record their segment ids and are not
 * followed (D7); Dynamic Blocks linked to a price rule keep their row and are not followed either. Writes only the
 * graph table (ReferenceStore): no message is published, no indexer is called. A block's content is parsed once per
 * call however many hosts publish it; a block whose content cannot be extracted keeps its row, is not followed and is
 * logged by id only (one malformed block never fails the batch).
 */
class BlockReferenceResolver implements BlockReferenceResolverInterface
{
    /** Nesting levels followed below the host (depth 0 = published by the host itself). */
    public const MAX_DEPTH = 5;
    /** Length of the segment_ids column. */
    public const MAX_SEGMENT_IDS_LENGTH = 255;

    public function __construct(
        private readonly HostSourceReader $hostSourceReader,
        private readonly DirectiveParser $directiveParser,
        private readonly WidgetInstanceReader $widgetInstanceReader,
        private readonly WidgetInstanceResolver $widgetInstanceResolver,
        private readonly StoreThemeResolver $storeThemeResolver,
        private readonly StoreContextProvider $storeContextProvider,
        private readonly BlockReader $blockReader,
        private readonly ReferenceStore $referenceStore,
        private readonly LoggerInterface $logger
    ) {
    }

    public function refreshForHost(string $hostType, int $hostId, int $storeId): void
    {
        $this->refreshForHosts($hostType, [$hostId], $storeId);
    }

    /**
     * @param array<int|string> $hostIds
     * @return int references written
     */
    public function refreshForHosts(string $hostType, array $hostIds, int $storeId): int
    {
        if (!in_array($hostType, HostType::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown host type "%s".', substr($hostType, 0, 40)));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $hostIds), static fn (int $id): bool => $id > 0)));
        $store = $ids === [] ? null : $this->storeContextProvider->get($storeId);
        if ($store === null) {
            return 0; // nothing asked, or a deleted store view (its rows went with it: FK CASCADE)
        }
        $sources = $this->hostSourceReader->read($hostType, $ids, $storeId);
        $widgets = $sources === [] ? [] : $this->widgetsOf($storeId);
        $frontier = [];
        foreach ($sources as $hostId => $source) {
            foreach ($this->directRefs($source, $widgets, $store->getHomeIdentifier()) as [$ref, $origin, $originId]) {
                $frontier[] = ['host' => (int) $hostId, 'ref' => $ref, 'source' => $origin, 'source_id' => $originId, 'depth' => 0];
            }
        }
        $rows = [];
        /** @var array<string, list<BlockRef>> $childrenOf block key => references in its content */
        $childrenOf = [];
        while ($frontier !== []) {
            $fresh = [];
            foreach ($frontier as $node) {
                $rowKey = implode('|', [$node['host'], $node['ref']->key(), $node['source'], $node['source_id']]);
                if (!isset($rows[$rowKey]) && !isset($fresh[$rowKey])) {
                    $fresh[$rowKey] = $node;
                }
            }
            if ($fresh === []) {
                break;
            }
            $blocks = $this->blockReader->read(array_values(array_map(static fn (array $node): BlockRef => $node['ref'], $fresh)), $storeId);
            $next = [];
            foreach ($fresh as $rowKey => $node) {
                /** @var BlockRef $ref */
                $ref = $node['ref'];
                $block = $blocks[$ref->key()] ?? null;
                $rows[$rowKey] = [
                    'host_id' => $node['host'],
                    'block_kind' => $ref->getKind(),
                    'block_id' => $ref->getId() > 0 ? $ref->getId() : ($block?->getId() ?? 0),
                    'block_identifier' => $ref->getIdentifier(),
                    'source' => $node['source'],
                    'source_id' => $node['source_id'],
                    'depth' => $node['depth'],
                    'segment_ids' => $block !== null && $block->hasSegments() ? $this->segmentList($block->getSegmentIds()) : null,
                ];
                if ($block === null || $block->isExcluded() || $node['depth'] >= self::MAX_DEPTH) {
                    continue;
                }
                $childrenOf[$block->key()] ??= $this->childrenOf($block);
                foreach ($childrenOf[$block->key()] as $child) {
                    $next[] = ['depth' => $node['depth'] + 1, 'ref' => $child] + $node;
                }
            }
            $frontier = $next;
        }
        $this->referenceStore->replace($hostType, $ids, $storeId, array_values($rows));
        return count($rows);
    }

    public function hostsForBlock(string $kind, int $blockId): iterable
    {
        $identifier = $kind === BlockRef::KIND_BLOCK ? ($this->blockReader->identifierOf($blockId) ?? '') : '';
        return $this->referenceStore->hostsForBlock($kind, $blockId, $identifier);
    }

    /**
     * @return iterable<list<array{store_id: int, host_type: string, host_id: int}>> pages of graph rows
     */
    public function hostsForWidgetInstance(int $instanceId): iterable
    {
        return $this->referenceStore->hostsForSource(ReferenceStore::SOURCE_WIDGET, $instanceId);
    }

    /**
     * @return list<BlockRef> [] when the content cannot be extracted (logged by id only)
     */
    private function childrenOf(BlockSource $block): array
    {
        try {
            return $this->directiveParser->parseHtml($block->getContent());
        } catch (\UnexpectedValueException) {
            $this->logger->warning(
                'CMS search block not followed: its content could not be extracted',
                ['block_kind' => $block->getKind(), 'block_id' => $block->getId()]
            );
            return [];
        }
    }

    /**
     * Comma list of the segment ids, cut at an id boundary to fit the column (a restricted block stays restricted:
     * D7 only needs to know that segments exist; the full list is in magento_banner_customersegment).
     *
     * @param list<int> $segmentIds
     */
    private function segmentList(array $segmentIds): string
    {
        $list = implode(',', $segmentIds);
        if (strlen($list) <= self::MAX_SEGMENT_IDS_LENGTH) {
            return $list;
        }
        $cut = substr($list, 0, self::MAX_SEGMENT_IDS_LENGTH + 1);
        return substr($cut, 0, (int) strrpos($cut, ','));
    }

    /**
     * @return list<array{0: WidgetInstance, 1: list<HostSelector>, 2: list<BlockRef>}> instances shown in the store view
     */
    private function widgetsOf(int $storeId): array
    {
        $themeId = $this->storeThemeResolver->themeId($storeId);
        $widgets = [];
        foreach ($this->widgetInstanceReader->all() as $instance) {
            if (!$this->widgetInstanceResolver->appliesToStore($instance, $storeId, $themeId)) {
                continue;
            }
            $selectors = $this->widgetInstanceResolver->selectors($instance);
            $refs = $this->widgetInstanceResolver->blockRefs($instance);
            if ($selectors !== [] && $refs !== []) {
                $widgets[] = [$instance, $selectors, $refs];
            }
        }
        return $widgets;
    }

    /**
     * @param list<array{0: WidgetInstance, 1: list<HostSelector>, 2: list<BlockRef>}> $widgets
     * @return list<array{0: BlockRef, 1: string, 2: int}> reference, source, source id
     */
    private function directRefs(HostSource $source, array $widgets, string $homeIdentifier): array
    {
        $refs = [];
        foreach ($source->getContents() as $html) {
            foreach ($this->directiveParser->parseHtml($html) as $ref) {
                $refs[] = [$ref, ReferenceStore::SOURCE_CONTENT, 0];
            }
        }
        foreach ($source->getExtraRefs() as $ref) {
            $refs[] = [$ref, ReferenceStore::SOURCE_CONTENT, 0];
        }
        foreach ($widgets as [$instance, $selectors, $blockRefs]) {
            foreach ($selectors as $selector) {
                if ($this->widgetInstanceResolver->matches($selector, $source->getFacts(), $homeIdentifier)) {
                    foreach ($blockRefs as $ref) {
                        $refs[] = [$ref, ReferenceStore::SOURCE_WIDGET, $instance->getId()];
                    }
                    break;
                }
            }
        }
        return $refs;
    }
}
