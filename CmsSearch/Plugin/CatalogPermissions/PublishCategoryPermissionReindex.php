<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Plugin\CatalogPermissions;

use Magento\CatalogPermissions\Model\Indexer\Category as PermissionIndexer;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Reference\CategorySubtreeReader;

/**
 * Magento_CatalogPermissions writes its permission index AFTER Category::save has committed (and, in "Update by
 * Schedule" mode, later still, from the mview cron), so the category message published by the save observer can be
 * consumed before the new/moved category's guest permission exists and index it as public. After the permission
 * indexer has processed ids (executeRow / executeList / execute, the entry point of the mview), a category/save message
 * is published for each of them: the consumer then reads the permission that is really there.
 *
 * The permission indexer also reindexes the whole subtree of each id it receives (Category\Action\Rows::
 * removeObsoleteIndexData() walks the children), so a deny on a parent changes the guest permission of every
 * descendant: the ids are expanded with their descendants (by path) before publishing. A failing subtree lookup is
 * logged (class only) and the original ids are still published.
 *
 * executeFull publishes nothing (one message per category of the catalog would be unbounded): a full permission
 * reindex requires `thedevkitchen:cmssearch:reindex --sync`. Never throws: the permission reindex must not fail because
 * of a queue problem (the publisher logs it).
 */
class PublishCategoryPermissionReindex
{
    public function __construct(
        private readonly ReindexPublisherInterface $publisher,
        private readonly CategorySubtreeReader $subtreeReader,
        private readonly LoggerInterface $logger
    ) {
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
            $given = [];
            foreach ($ids as $value) {
                if ((int) $value > 0) {
                    $given[] = (int) $value;
                }
            }
            $seen = [];
            foreach ($this->withSubtrees($given) as $id) {
                if ($id > 0 && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $this->publisher->publish(ReindexMessageInterface::ENTITY_CATEGORY, $id, ReindexMessageInterface::ACTION_SAVE);
                }
            }
        } catch (\Throwable) {
            // never break the permission reindex; the publisher already logged what it could
        }
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function withSubtrees(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            return array_merge($ids, $this->subtreeReader->withDescendants($ids));
        } catch (\Throwable $exception) {
            $this->logger->error(
                'descendants of permission-reindexed categories not looked up: ' . get_class($exception),
                ['exception_class' => get_class($exception)]
            );
            return $ids;
        }
    }
}
