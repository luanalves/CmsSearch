<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Staging\PostProcessor;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Staging\Model\StagingApplier\PostProcessorInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Queue\SentMessageLog;

/**
 * StagingApplier post-processor (cron staging_apply_version) for the entities whose content reaches the hosts
 * (Plan 6, D9): a block, product or category version that starts or ends asks for a "staging" reindex of that entity
 * (the applier's own re-save has no changed fields, so the product/category save observers stay silent). Pages are
 * handled by PageVersionApplied (Plan 3); Dynamic Blocks and widget instances are not staged. It never reads
 * versions: the consumer reads the current state. Ids are the MetadataPool identifier field (block_id / entity_id),
 * never row_id. An entity whose re-save in the same applier run already sent a message is skipped (SentMessageLog): in
 * practice blocks; products and categories re-saved without changes publish nothing, so their staging message stays.
 */
class ReferenceVersionApplied implements PostProcessorInterface
{
    private const ENTITY_TYPES = [
        BlockInterface::class => ReindexMessageInterface::ENTITY_BLOCK,
        ProductInterface::class => ReindexMessageInterface::ENTITY_PRODUCT,
        CategoryInterface::class => ReindexMessageInterface::ENTITY_CATEGORY,
    ];

    public function __construct(
        private readonly ReindexPublisherInterface $publisher,
        private readonly SentMessageLog $sentLog
    ) {
    }

    /**
     * @param array<int|string> $entityIds identifier field values (block_id / entity_id)
     */
    public function execute(int $oldVersionId, int $currentVersionId, array $entityIds, string $entityType): void
    {
        $type = self::ENTITY_TYPES[ltrim($entityType, '\\')] ?? null;
        if ($type === null) {
            return;
        }
        $seen = [];
        foreach ($entityIds as $entityId) {
            $id = (int) $entityId;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($this->sentLog->wasSent($type, $id)) {
                continue; // the applier's re-save of this entity already asked for its reindex (after the version switch)
            }
            $this->publisher->publish($type, $id, ReindexMessageInterface::ACTION_STAGING);
        }
    }
}
