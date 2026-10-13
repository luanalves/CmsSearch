<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The reindex consumer is a long-lived process and Magento's StoreManager keeps the store list it loaded when the
 * process started. Before each message the processor calls refreshIfChanged(): ONE small query (store views with the
 * root category of their group) is compared with the StoreManager's in-memory list, and the StoreManager is
 * reinitialised only when they differ (a store view or store group was added, deleted, moved, (de)activated or got
 * another root category since the process started). reinitStores() also cleans the store/website/group cache tags and
 * reloads the configuration, so it must never run per message. Values that are not part of this list (base URLs, home
 * page, locale) are baked into documents at index time and still need `--sync` (README runbook).
 * Read-only on the database; holds no state of its own.
 */
class StoreListRefresher
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return bool true when the store list differed and the StoreManager was reinitialised
     */
    public function refreshIfChanged(): bool
    {
        if ($this->fromDatabase() === $this->fromMemory()) {
            return false;
        }
        $this->storeManager->reinitStores();
        return true;
    }

    /**
     * @return list<string>
     */
    private function fromDatabase(): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    ['s' => $this->resource->getTableName('store')],
                    ['store_id', 'code', 'website_id', 'group_id', 'is_active']
                )
                ->joinLeft(['g' => $this->resource->getTableName('store_group')], 'g.group_id = s.group_id', ['root_category_id'])
                ->where('s.store_id > ?', 0)
                ->order('s.store_id ASC')
        );
        return array_map(
            static fn (array $row): string => self::line(
                (int) $row['store_id'],
                (string) $row['code'],
                (int) $row['website_id'],
                (int) $row['group_id'],
                (int) $row['is_active'],
                (int) ($row['root_category_id'] ?? 0)
            ),
            $rows
        );
    }

    /**
     * @return list<string>
     */
    private function fromMemory(): array
    {
        $lines = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $id = (int) $store->getId();
            if ($id <= 0) {
                continue;
            }
            $lines[$id] = self::line(
                $id,
                (string) $store->getCode(),
                (int) $store->getWebsiteId(),
                (int) $store->getStoreGroupId(),
                (int) $store->getIsActive(),
                $store instanceof Store ? (int) $store->getRootCategoryId() : 0
            );
        }
        ksort($lines);
        return array_values($lines);
    }

    private static function line(int $id, string $code, int $websiteId, int $groupId, int $active, int $rootId): string
    {
        return implode('|', [$id, $code, $websiteId, $groupId, $active, $rootId]);
    }
}
