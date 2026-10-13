<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Plugin\CatalogPermissions;

use Magento\CatalogPermissions\Model\Indexer\Category as PermissionIndexer;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * Magento_CatalogPermissions writes its permission index AFTER Category::save has committed (and, in "Update by
 * Schedule" mode, later still, from the mview cron), so the category message published by the save observer can be
 * consumed before the new/moved category's guest permission exists and index it as public. After the permission
 * indexer has processed ids (executeRow / executeList / execute, the entry point of the mview), a category/save message
 * is published for each of them: the consumer then reads the permission that is really there.
 *
 * executeFull publishes nothing (one message per category of the catalog would be unbounded): a full permission
 * reindex requires `thedevkitchen:cmssearch:reindex --sync`. Never throws: the permission reindex must not fail because
 * of a queue problem (the publisher logs it).
 */
class PublishCategoryPermissionReindex
{
    public function __construct(private readonly ReindexPublisherInterface $publisher)
    {
    }

    /**
     * @param mixed $result
     * @param mixed $id
     * @return mixed
     */
    public function afterExecuteRow(PermissionIndexer $subject, $result, $id)
    {
        $this->publishAll([$id]);
        return $result;
    }

    /**
     * @param mixed $result
     * @param mixed $ids
     * @return mixed
     */
    public function afterExecuteList(PermissionIndexer $subject, $result, $ids)
    {
        $this->publishAll($ids);
        return $result;
    }

    /**
     * @param mixed $result
     * @param mixed $ids
     * @return mixed
     */
    public function afterExecute(PermissionIndexer $subject, $result, $ids)
    {
        $this->publishAll($ids);
        return $result;
    }

    /**
     * @param mixed $ids
     */
    private function publishAll($ids): void
    {
        if (!is_array($ids)) {
            return;
        }
        try {
            $seen = [];
            foreach ($ids as $value) {
                $id = (int) $value;
                if ($id > 0 && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $this->publisher->publish(ReindexMessageInterface::ENTITY_CATEGORY, $id, ReindexMessageInterface::ACTION_SAVE);
                }
            }
        } catch (\Throwable) {
            // never break the permission reindex; the publisher already logged what it could
        }
    }
}
