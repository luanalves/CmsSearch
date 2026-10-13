<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\CacheInterface;
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
 * database lines) it last handled, in the process AND in the Magento cache (key FINGERPRINT_KEY, no tags): cron
 * spawns a new consumer process regularly and each of them must not repeat the reinit and the warning for a database
 * state that was already handled. Per message: the same fingerprint as in the process = nothing; a first look in a
 * process whose cached fingerprint equals the database one adopts it without reinit (memory may legitimately differ,
 * dumped scopes); a first look without a cache entry compares with memory and saves the fingerprint; any other
 * difference reinitialises once, saves the fingerprint and, when memory still differs, logs a warning (once per
 * database change, because the reinit happens once). A cache failure is ignored (the process then behaves as without
 * the cache). The cache entry may expire (default lifetime), which costs one more reinit and warning.
 * Read-only on the database; its only state is that fingerprint.
 */
class StoreListRefresher
{
    public const FINGERPRINT_KEY = 'thedevkitchen_cmssearch_store_fingerprint';

    private ?string $lastDatabaseFingerprint = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return bool true when the store list differed and the StoreManager was reinitialised
     */
    public function refreshIfChanged(): bool
    {
        $database = $this->fromDatabase();
        $fingerprint = hash('sha256', implode("\n", $database));
        if ($fingerprint === $this->lastDatabaseFingerprint) {
            return false;
        }
        $firstLook = $this->lastDatabaseFingerprint === null;
        $this->lastDatabaseFingerprint = $fingerprint;
        $cached = $this->cachedFingerprint();
        if ($firstLook) {
            if ($cached === $fingerprint) {
                return false;   // handled by an earlier process; memory may differ on purpose (dumped scopes)
            }
            if ($cached === null && $database === $this->fromMemory()) {
                $this->saveFingerprint($fingerprint);
                return false;
            }
        }
        $this->storeManager->reinitStores();
        $this->saveFingerprint($fingerprint);
        if ($database !== $this->fromMemory() && $cached !== $fingerprint) {
            $this->logger->warning(
                'the store list in memory still differs from the database after reinitialising the StoreManager '
                . '(scopes dumped to app/etc/config.php?); the file source wins, the consumer will not retry until the database changes'
            );
        }
        return true;
    }

    private function cachedFingerprint(): ?string
    {
        try {
            $value = $this->cache->load(self::FINGERPRINT_KEY);
        } catch (\Throwable) {
            return null;
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function saveFingerprint(string $fingerprint): void
    {
        try {
            $this->cache->save($fingerprint, self::FINGERPRINT_KEY, [], null);
        } catch (\Throwable) {
            // a cache failure only costs a repeated reinit in another process
        }
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
