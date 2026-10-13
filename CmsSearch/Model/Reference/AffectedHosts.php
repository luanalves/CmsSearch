<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Thedevkitchen\CmsSearch\Api\BlockReferenceResolverInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Model\Indexer\AffectedPages;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;

/**
 * The hosts whose references, documents or block text a message may change, from the CURRENT state and the graph:
 * - page / content type / classification term: Plan 4's AffectedPages, streamed chunk by chunk; a page message also
 *   reaches the page of All Store Views (store 0) that shares its URL key (ShadowedPageReader: whether that page is
 *   served in a store view depends on the store-view page); types and terms never change which page wins a URL key;
 * - product / category: the entity itself (its graph is rebuilt from its current content; a deleted one loses its
 *   rows, document or block text);
 * - block / Dynamic Block: every host that published it at its last refresh (one message per block, expanded here;
 *   a CMS block is also found through its current identifier);
 * - widget instance: its hosts of BEFORE (graph rows of that instance) and of AFTER (its current placement in the
 *   store views whose theme it belongs to), so removed text is cleaned and added text appears.
 *
 * resolve() validates the entity type at once (InvalidReindexMessageException, permanent) but reads nothing until the
 * returned chunks are iterated: the processor does not iterate when no store view is concerned. Each chunk is a
 * list of at most $chunkSize hosts (store_id null = every store view of the message); block and widget instance
 * messages read the graph in keyset pages and deduplicate hosts across pages (memory bounded by one int per host and
 * store view). Never writes, never publishes.
 */
class AffectedHosts
{
    private const TYPE_INDEX = [HostType::PAGE => 0, HostType::CATEGORY => 1, HostType::PRODUCT => 2];

    public function __construct(
        private readonly AffectedPages $affectedPages,
        private readonly ShadowedPageReader $shadowedPageReader,
        private readonly BlockReferenceResolverInterface $blockReferenceResolver,
        private readonly WidgetInstanceReader $widgetInstanceReader,
        private readonly WidgetInstanceResolver $widgetInstanceResolver,
        private readonly StoreThemeResolver $storeThemeResolver,
        private readonly StoreContextProvider $storeContextProvider,
        private readonly HostCatalog $hostCatalog,
        private readonly int $chunkSize = 1000
    ) {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('The chunk size must be positive.');
        }
    }

    /**
     * @return iterable<list<array{host_type: string, host_id: int, store_id: ?int}>> chunks of unique hosts
     * @throws InvalidReindexMessageException for an entity type no indexer can process (thrown here, not on iteration)
     */
    public function resolve(ReindexMessageInterface $message): iterable
    {
        $id = $message->getEntityId();
        return match ($message->getEntityType()) {
            ReindexMessageInterface::ENTITY_PAGE => $this->pages($this->affectedPages->resolve($message), true),
            ReindexMessageInterface::ENTITY_CONTENT_TYPE,
            ReindexMessageInterface::ENTITY_CLASSIFICATION_TERM => $this->pages($this->affectedPages->resolve($message), false),
            ReindexMessageInterface::ENTITY_PRODUCT => [[self::host(HostType::PRODUCT, $id, null)]],
            ReindexMessageInterface::ENTITY_CATEGORY => [[self::host(HostType::CATEGORY, $id, null)]],
            ReindexMessageInterface::ENTITY_BLOCK => $this->ofBlock(BlockRef::KIND_BLOCK, $id),
            ReindexMessageInterface::ENTITY_DYNAMIC_BLOCK => $this->ofBlock(BlockRef::KIND_DYNAMIC_BLOCK, $id),
            ReindexMessageInterface::ENTITY_WIDGET_INSTANCE => $this->ofWidgetInstance($id),
            default => throw new InvalidReindexMessageException(sprintf(
                'The CMS search indexer cannot process entity type "%s".',
                substr($message->getEntityType(), 0, 40)
            )),
        };
    }

    /**
     * @param iterable<list<int>> $chunks
     * @return \Generator<int, list<array{host_type: string, host_id: int, store_id: ?int}>>
     */
    private function pages(iterable $chunks, bool $withShadowSiblings): \Generator
    {
        foreach ($chunks as $pageIds) {
            if ($withShadowSiblings) {
                $pageIds = array_merge($pageIds, $this->shadowedPageReader->siblingsOf($pageIds));
            }
            $pageIds = array_values(array_unique(array_map('intval', $pageIds)));
            sort($pageIds);
            yield array_map(static fn (int $pageId): array => self::host(HostType::PAGE, $pageId, null), $pageIds);
        }
    }

    /**
     * @return \Generator<int, list<array{host_type: string, host_id: int, store_id: ?int}>>
     */
    private function ofBlock(string $kind, int $blockId): \Generator
    {
        yield from $this->chunked($this->graphHosts($this->blockReferenceResolver->hostsForBlock($kind, $blockId)));
    }

    /**
     * @return \Generator<int, list<array{host_type: string, host_id: int, store_id: ?int}>>
     */
    private function ofWidgetInstance(int $instanceId): \Generator
    {
        yield from $this->chunked((function () use ($instanceId): \Generator {
            yield from $this->graphHosts($this->blockReferenceResolver->hostsForWidgetInstance($instanceId));
            yield from $this->placementOf($instanceId);
        })());
    }

    /**
     * @param iterable<list<array{store_id: int, host_type: string, host_id: int}>> $pages
     * @return \Generator<int, array{host_type: string, host_id: int, store_id: ?int}>
     */
    private function graphHosts(iterable $pages): \Generator
    {
        foreach ($pages as $rows) {
            foreach ($rows as $row) {
                yield self::host((string) $row['host_type'], (int) $row['host_id'], (int) $row['store_id']);
            }
        }
    }

    /**
     * @return \Generator<int, array{host_type: string, host_id: int, store_id: ?int}> current placement (AFTER side)
     */
    private function placementOf(int $instanceId): \Generator
    {
        $instance = $this->widgetInstanceReader->get($instanceId);
        if ($instance === null || $this->widgetInstanceResolver->blockRefs($instance) === []) {
            return; // deleted, or publishes no block: only its old hosts (BEFORE) change
        }
        $selectors = $this->widgetInstanceResolver->selectors($instance);
        if ($selectors === []) {
            return; // only ignored placements (all_pages, cart, checkout...)
        }
        foreach ($this->storeContextProvider->all() as $store) {
            $storeId = $store->getStoreId();
            if (!$this->widgetInstanceResolver->appliesToStore($instance, $storeId, $this->storeThemeResolver->themeId($storeId))) {
                continue;
            }
            foreach ($selectors as $selector) {
                foreach ($this->hostCatalog->ids($selector, $store) as $hostId) {
                    yield self::host($selector->getHostType(), (int) $hostId, $storeId);
                }
            }
        }
    }

    /**
     * Each host once per message (first occurrence), in chunks of at most $chunkSize. The dedupe map holds ONE int per
     * host and store view (host ids <= 2^32, store ids <= 2^16), never the host rows: a block placed on every product
     * of a large catalog stays bounded in memory (TECHNICAL-REFERENCE.md "Known limits").
     *
     * @param iterable<array{host_type: string, host_id: int, store_id: ?int}> $hosts
     * @return \Generator<int, list<array{host_type: string, host_id: int, store_id: ?int}>>
     */
    private function chunked(iterable $hosts): \Generator
    {
        /** @var array<int, true> $seen */
        $seen = [];
        $chunk = [];
        foreach ($hosts as $host) {
            $key = $host['host_id'] * 262144 + ($host['store_id'] ?? 0) * 4 + (self::TYPE_INDEX[$host['host_type']] ?? 3);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $chunk[] = $host;
            if (count($chunk) >= $this->chunkSize) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /**
     * @return array{host_type: string, host_id: int, store_id: ?int}
     */
    private static function host(string $type, int $id, ?int $storeId): array
    {
        return ['host_type' => $type, 'host_id' => $id, 'store_id' => $storeId];
    }
}
