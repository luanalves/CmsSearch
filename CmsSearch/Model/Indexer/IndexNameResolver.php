<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Elasticsearch\Model\Config as SearchConfig;
use Thedevkitchen\CmsSearch\Exception\IndexingException;

/**
 * Names of the CMS search indices (engineering 3.4): alias {prefix}_thedevkitchen_cmssearch_{store_id}, read by the
 * search side; concrete versions {alias}_v{n}. parse() is the only gate for every destructive operation: an index
 * whose name it does not recognise is never changed or deleted.
 */
class IndexNameResolver
{
    public const NAMESPACE = 'thedevkitchen_cmssearch';

    public function __construct(private readonly SearchConfig $searchConfig)
    {
    }

    public function getAlias(int $storeId): string
    {
        return $this->base() . $storeId;
    }

    public function getVersionName(int $storeId, int $version): string
    {
        return $this->getAlias($storeId) . '_v' . $version;
    }

    public function getPattern(): string
    {
        return $this->base() . '*';
    }

    /**
     * @return array{store_id: int, version: int}|null
     */
    public function parse(string $index): ?array
    {
        $pattern = '/^' . preg_quote($this->base(), '/') . '([1-9][0-9]{0,4})_v([1-9][0-9]{0,8})$/';
        if (preg_match($pattern, $index, $match) !== 1) {
            return null;
        }
        return ['store_id' => (int) $match[1], 'version' => (int) $match[2]];
    }

    /**
     * @return int|null the store view id of one of our aliases ({prefix}_thedevkitchen_cmssearch_{store_id}), else null
     */
    public function parseAlias(string $name): ?int
    {
        $pattern = '/^' . preg_quote($this->base(), '/') . '([1-9][0-9]{0,4})$/';
        return preg_match($pattern, $name, $match) === 1 ? (int) $match[1] : null;
    }

    private function base(): string
    {
        $prefix = strtolower(trim((string) $this->searchConfig->getIndexPrefix()));
        if ($prefix === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $prefix) !== 1) {
            throw new IndexingException(
                'The search engine index prefix (catalog/search/opensearch_index_prefix) is empty or invalid.'
            );
        }
        return $prefix . '_' . self::NAMESPACE . '_';
    }
}
