<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api;

/**
 * Reference graph (engineering 5.5, D3): which Blocks and Dynamic Blocks each host - CMS page, product page,
 * category page (Model\Reference\Data\HostType) - publishes in a store view. Used only by the reindex consumer and
 * the operator CLI, never in a web request (D10).
 */
interface BlockReferenceResolverInterface
{
    /** Recomputes the references of one host in one store view (delete + insert in one transaction). */
    public function refreshForHost(string $hostType, int $hostId, int $storeId): void;

    /**
     * @param string $kind Model\Reference\Data\BlockRef::KIND_BLOCK or KIND_DYNAMIC_BLOCK
     * @return iterable<list<array{store_id: int, host_type: string, host_id: int}>> pages (at most 1000 graph rows each,
     *         lazy, not distinct) of the hosts that referenced the block at their last refresh (a CMS block is also
     *         found through its current identifier)
     */
    public function hostsForBlock(string $kind, int $blockId): iterable;

    /**
     * @return iterable<list<array{store_id: int, host_type: string, host_id: int}>> pages (as above) of the hosts a
     *         widget instance published on at their last refresh (also after the instance was deleted or moved: the
     *         BEFORE side of a widget message)
     */
    public function hostsForWidgetInstance(int $instanceId): iterable;
}
