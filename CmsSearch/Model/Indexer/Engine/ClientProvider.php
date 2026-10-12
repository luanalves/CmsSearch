<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Engine;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Elasticsearch\Model\Config as SearchConfig;
use Magento\OpenSearch\Model\SearchClient;
use OpenSearch\Client;
use Thedevkitchen\CmsSearch\Exception\IndexingException;

/**
 * An OpenSearch client of this module's own, built by Magento's native client resolver from the native
 * catalog/search configuration (host, port, auth: nothing is configured or read by this module).
 *
 * It is NOT the shared ConnectionManager singleton: after one timeout or connection error the opensearch-php
 * connection pool marks its only node dead for 60 s, doubling up to 3600 s, so every following request of the same
 * process fails at once. reset() drops the client; the next get() builds a fresh one with a fresh pool (IndexManager
 * does that once per failed request). Magento builds the client without any request timeout (only ping() has one):
 * every request of IndexManager carries getRequestOptions() as per-request client options.
 */
class ClientProvider
{
    public const ENGINE = 'opensearch';

    private const DEFAULT_TIMEOUT = 15;
    private const MAX_CONNECT_TIMEOUT = 60;
    private const MIN_REQUEST_TIMEOUT = 60;
    private const MAX_REQUEST_TIMEOUT = 300;

    private ?Client $client = null;

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly SearchConfig $searchConfig
    ) {
    }

    /**
     * @throws IndexingException
     */
    public function get(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $engine = (string) $this->clientResolver->getCurrentEngine();
        if ($engine !== self::ENGINE) {
            throw $this->wrongEngine('the configured engine is ' . $engine);
        }
        try {
            $connection = $this->clientResolver->create(self::ENGINE);
        } catch (\Throwable $exception) {
            throw new IndexingException(
                sprintf('The OpenSearch connection is not available: %s', $exception->getMessage()),
                0,
                $exception
            );
        }
        if (!$connection instanceof SearchClient) {
            throw $this->wrongEngine('the search client is ' . get_debug_type($connection));
        }
        return $this->client = $connection->getOpenSearchClient();
    }

    /**
     * Forgets the client (and its connection pool with its dead-node state); the next get() builds a new one.
     */
    public function reset(): void
    {
        $this->client = null;
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

    private function wrongEngine(string $detail): IndexingException
    {
        return new IndexingException(sprintf(
            'CMS search needs the OpenSearch engine (catalog/search/engine = opensearch); %s.',
            $detail
        ));
    }
}
