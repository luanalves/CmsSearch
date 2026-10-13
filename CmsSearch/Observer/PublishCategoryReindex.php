<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Model\Reference\CategorySubtreeReader;

/**
 * catalog_category_save_after / _delete_after / _move_after (all inside the category transaction). A category
 * document depends on its name, description, landing page, display mode, active and anchor flags and URL. A URL key
 * change or a move changes the URL of every descendant (Magento regenerates their url_rewrite rows in the same
 * transaction), so those ask for the whole subtree (one message per category: the consumer reindexes a category
 * message for that category only); a delete also names the deleted children (deleted_children_ids). Other saves
 * (position, children count...) publish nothing. Staging: ReferenceVersionApplied (the applier's re-save changes no
 * field). Never throws: a subtree lookup failure is logged (ids only) and the category itself is still asked for.
 */
class PublishCategoryReindex implements ObserverInterface
{
    public const TRACKED = ['name', 'description', 'landing_page', 'display_mode', 'is_active', 'is_anchor', 'url_key', 'url_path'];
    public const URL_FIELDS = ['url_key', 'url_path'];

    public function __construct(
        private readonly ReindexPublisherInterface $publisher,
        private readonly CategorySubtreeReader $subtreeReader,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $category = $event->getData('data_object') ?? $event->getData('category');
        if (!$category instanceof Category || (int) $category->getId() <= 0) {
            return;
        }
        $id = (int) $category->getId();
        $action = ReindexMessageInterface::ACTION_SAVE;
        if ($event->getName() === 'catalog_category_delete_after') {
            $action = ReindexMessageInterface::ACTION_DELETE;
            $ids = array_merge([$id], (array) ($category->getData('deleted_children_ids') ?? []));
        } elseif ($event->getName() === 'catalog_category_move_after') {
            $ids = $this->subtree($category, $id);
        } else {
            $new = $category->getOrigData('entity_id') === null;
            if (!$new && !$this->changed($category, self::TRACKED)) {
                return;
            }
            $ids = !$new && $this->changed($category, self::URL_FIELDS) ? $this->subtree($category, $id) : [$id];
        }
        $seen = [];
        foreach ($ids as $value) {
            $categoryId = (int) $value;
            if ($categoryId > 0 && !isset($seen[$categoryId])) {
                $seen[$categoryId] = true;
                $this->publisher->publish(ReindexMessageInterface::ENTITY_CATEGORY, $categoryId, $action);
            }
        }
    }

    /**
     * @return list<int> the category first, then its descendants
     */
    private function subtree(Category $category, int $id): array
    {
        try {
            return array_merge([$id], $this->subtreeReader->descendantIds((string) $category->getPath()));
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('descendants of category %d not looked up: %s', $id, get_class($exception)),
                ['exception_class' => get_class($exception)]
            );
            return [$id];
        }
    }

    /**
     * @param list<string> $fields
     */
    private function changed(Category $category, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($category->dataHasChangedFor($field)) {
                return true;
            }
        }
        return false;
    }
}
