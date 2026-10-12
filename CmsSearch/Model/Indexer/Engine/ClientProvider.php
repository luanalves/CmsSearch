<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Engine;

use Magento\Elasticsearch\Model\Config as SearchConfig;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\OpenSearch\Model\SearchClient;
use OpenSearch\Client;
use Thedevkitchen\CmsSearch\Exception\IndexingException;

/**
 * The low-level OpenSearch client of Magento's own search connection (host, port, auth come from the native
 * catalog/search configuration; nothing is configured by this module).
 *
 * Magento builds that client without any request timeout (only ping() gets one), so a stalled cluster would block
 * a consumer forever: every request of IndexManager carries getRequestOptions() as per-request client options.
 */
class ClientProvider
{
    private const DEFAULT_TIMEOUT = 15;
    private const MAX_CONNECT_TIMEOUT = 60;
    private const MIN_REQUEST_TIMEOUT = 60;
    private const MAX_REQUEST_TIMEOUT = 300;

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SearchConfig $searchConfig
    ) {
    }

    /**
     * @throws IndexingException
     */
    public function get(): Client
    {
        try {
            $connection = $this->connectionManager->getConnection();
        } catch (\Throwable $exception) {
            throw new IndexingException(
                sprintf('The OpenSearch connection is not available: %s', $exception->getMessage()),
                0,
                $exception
            );
        }
        if (!$connection instanceof SearchClient) {
            throw new IndexingException(sprintf(
                'CMS search needs the OpenSearch engine (catalog/search/engine = opensearch); the search client is %s.',
                get_debug_type($connection)
            ));
        }
        return $connection->getOpenSearchClient();
    }

    /**
     * Per-request client options (seconds), derived from the native catalog/search/{engine}_server_timeout: the
     * connection must open within that time (at most 60 s); a request may take 60 s or that time, at most 300 s
     * (bulk batches and refresh=wait_for take longer than a ping).
     *
     * @return array{timeout: int, connect_timeout: int}
     */
    public function getRequestOptions(): array
    {
        $configured = $this->searchConfig->getElasticsearchConfigData('server_timeout');
        $seconds = is_numeric($configured) ? (int) $configured : 0;
        if ($seconds < 1) {
            $seconds = self::DEFAULT_TIMEOUT;
        }
        return [
            'timeout' => min(max($seconds, self::MIN_REQUEST_TIMEOUT), self::MAX_REQUEST_TIMEOUT),
            'connect_timeout' => min($seconds, self::MAX_CONNECT_TIMEOUT),
        ];
    }
}
