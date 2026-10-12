<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Staging\PostProcessor;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Staging\Model\StagingApplier\PostProcessorInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * StagingApplier post-processor (cron staging_apply_version): when scheduled content starts or ends, ask for a
 * reindex of each page that changed (D9). It never reads versions: the consumer reads the page's current state.
 */
class PageVersionApplied implements PostProcessorInterface
{
    public function __construct(private readonly ReindexPublisherInterface $publisher)
    {
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
            $this->publisher->publish(
                ReindexMessageInterface::ENTITY_PAGE,
                $pageId,
                ReindexMessageInterface::ACTION_STAGING
            );
        }
    }
}
