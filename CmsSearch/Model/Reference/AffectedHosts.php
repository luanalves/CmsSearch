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
 * list of unique hosts (store_id null = every store view of the message). Never writes, never publishes.
 */
class AffectedHosts
{
    public function __construct(
        private readonly AffectedPages $affectedPages,
        private readonly ShadowedPageReader $shadowedPageReader,
        private readonly BlockReferenceResolverInterface $blockReferenceResolver,
        private readonly WidgetInstanceReader $widgetInstanceReader,
        private readonly WidgetInstanceResolver $widgetInstanceResolver,
        private readonly StoreThemeResolver $storeThemeResolver,
        private readonly StoreContextProvider $storeContextProvider,
        private readonly HostCatalog $hostCatalog
    ) {
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
        yield self::unique($this->fromGraph($this->blockReferenceResolver->hostsForBlock($kind, $blockId)));
    }

    /**
     * @return \Generator<int, list<array{host_type: string, host_id: int, store_id: ?int}>>
     */
    private function ofWidgetInstance(int $instanceId): \Generator
    {
        yield self::unique(array_merge(
            $this->fromGraph($this->blockReferenceResolver->hostsForWidgetInstance($instanceId)),
            $this->placementOf($instanceId)
        ));
    }

    /**
     * @param list<array{store_id: int, host_type: string, host_id: int}> $rows
     * @return list<array{host_type: string, host_id: int, store_id: ?int}>
     */
    private function fromGraph(array $rows): array
    {
        return array_map(
            static fn (array $row): array => self::host((string) $row['host_type'], (int) $row['host_id'], (int) $row['store_id']),
            $rows
        );
    }

    /**
     * @return list<array{host_type: string, host_id: int, store_id: ?int}>
     */
    private function placementOf(int $instanceId): array
    {
        $instance = $this->widgetInstanceReader->get($instanceId);
        if ($instance === null || $this->widgetInstanceResolver->blockRefs($instance) === []) {
            return []; // deleted, or publishes no block: only its old hosts (BEFORE) change
        }
        $selectors = $this->widgetInstanceResolver->selectors($instance);
        if ($selectors === []) {
            return []; // only ignored placements (all_pages, cart, checkout...)
        }
        $hosts = [];
        foreach ($this->storeContextProvider->all() as $store) {
            $storeId = $store->getStoreId();
            if (!$this->widgetInstanceResolver->appliesToStore($instance, $storeId, $this->storeThemeResolver->themeId($storeId))) {
                continue;
            }
            foreach ($selectors as $selector) {
                foreach ($this->hostCatalog->ids($selector, $store) as $hostId) {
                    $hosts[] = self::host($selector->getHostType(), (int) $hostId, $storeId);
                }
            }
        }
        return $hosts;
    }

    /**
     * @param list<array{host_type: string, host_id: int, store_id: ?int}> $hosts
     * @return list<array{host_type: string, host_id: int, store_id: ?int}> first occurrence kept, order preserved
     */
    private static function unique(array $hosts): array
    {
        $unique = [];
        foreach ($hosts as $host) {
            $unique[$host['host_type'] . '|' . $host['host_id'] . '|' . ($host['store_id'] ?? '*')] ??= $host;
        }
        return array_values($unique);
    }

    /**
     * @return array{host_type: string, host_id: int, store_id: ?int}
     */
    private static function host(string $type, int $id, ?int $storeId): array
    {
        return ['host_type' => $type, 'host_id' => $id, 'store_id' => $storeId];
    }
}
