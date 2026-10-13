<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Staging\PostProcessor;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Staging\Model\StagingApplier\PostProcessorInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Queue\SentMessageLog;

/**
 * StagingApplier post-processor (cron staging_apply_version): when scheduled content starts or ends, ask for a
 * reindex of each page that changed (D9). It never reads versions: the consumer reads the page's current state. A page
 * whose re-save in the same applier run already sent page/save is skipped (SentMessageLog).
 */
class PageVersionApplied implements PostProcessorInterface
{
    public function __construct(
        private readonly ReindexPublisherInterface $publisher,
        private readonly SentMessageLog $sentLog
    ) {
    }

    /**
     * @param array<int|string> $entityIds page_id values (MetadataPool identifierField of PageInterface)
     */
    public function execute(int $oldVersionId, int $currentVersionId, array $entityIds, string $entityType): void
    {
        if (ltrim($entityType, '\\') !== PageInterface::class) {
            return;
        }
        $seen = [];
        foreach ($entityIds as $entityId) {
            $pageId = (int) $entityId;
            if ($pageId <= 0 || isset($seen[$pageId])) {
                continue;
            }
            $seen[$pageId] = true;
            if ($this->sentLog->wasSent(ReindexMessageInterface::ENTITY_PAGE, $pageId)) {
                continue; // the applier's re-save of this page already asked for its reindex (after the version switch)
            }
            $this->publisher->publish(
                ReindexMessageInterface::ENTITY_PAGE,
                $pageId,
                ReindexMessageInterface::ACTION_STAGING
            );
        }
    }
}
