<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The reindex consumer is a long-lived process and Magento's StoreManager keeps the store list it loaded when the
 * process started. Before each message the processor calls refreshIfChanged(): ONE small query (store views with the
 * root category of their group) is compared with the StoreManager's in-memory list, and the StoreManager is
 * reinitialised only when they differ (a store view or store group was added, deleted, moved, (de)activated or got
 * another root category since the process started). reinitStores() also cleans the store/website/group cache tags and
 * reloads the configuration, so it must never run per message. Values that are not part of this list (base URLs, home
 * page, locale) are baked into documents at index time and still need `--sync` (README runbook).
 * Convergence: with `app:config:dump scopes` the file source overrides the database, so the in-memory list can stay
 * different from the database after reinitStores(). The refresher therefore remembers the fingerprint (hash of the
 * database lines) it last saw and reinitialises only when the database fingerprint changes; the first message of a
 * process compares against memory. When memory still differs after a reinit a warning is logged (once per database
 * state, because the reinit itself happens once per database state).
 * Read-only on the database; its only state is that fingerprint.
 */
class StoreListRefresher
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    private ?string $lastDatabaseFingerprint = null;

    /**
     * @return bool true when the store list differed and the StoreManager was reinitialised
     */
    public function refreshIfChanged(): bool
    {
        $database = $this->fromDatabase();
        $fingerprint = hash('sha256', implode("\n", $database));
        $unchanged = $this->lastDatabaseFingerprint === null
            ? $database === $this->fromMemory()
            : $fingerprint === $this->lastDatabaseFingerprint;
        $this->lastDatabaseFingerprint = $fingerprint;
        if ($unchanged) {
            return false;
        }
        $this->storeManager->reinitStores();
        if ($database !== $this->fromMemory()) {
            $this->logger->warning(
                'the store list in memory still differs from the database after reinitialising the StoreManager '
                . '(scopes dumped to app/etc/config.php?); the file source wins, the consumer will not retry until the database changes'
            );
        }
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
