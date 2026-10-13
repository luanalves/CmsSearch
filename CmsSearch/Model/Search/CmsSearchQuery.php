<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Magento\Store\Model\StoreManagerInterface;
use OpenSearch\Exception\NotFoundHttpException;
use Thedevkitchen\CmsSearch\Api\CmsSearchQueryInterface;
use Thedevkitchen\CmsSearch\Exception\SearchUnavailableException;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexNameResolver;
use Thedevkitchen\CmsSearch\Model\Search\Data\CmsResult;
use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;

/**
 * One read-only _search on the alias of the CURRENT store view (never a store id taken from the request), through the
 * module's OpenSearch client (Plan 4 ClientProvider: built once per PHP request), bounded by results/search_timeout_ms
 * as the per-request curl timeout AND connect timeout, without the transport's retry: a slow or unreachable engine
 * costs the page at most that time once. No reset, no retry, no index or alias creation, no refresh.
 *
 * Every failure becomes a SearchUnavailableException whose message names only the alias and the exception class
 * (engine messages can quote the query; they are neither copied nor chained). The alias missing (HTTP 404, the store
 * view has no index yet) is flagged isMissingIndex(): the caller hides the CMS tabs without logging it.
 */
class CmsSearchQuery implements CmsSearchQueryInterface
{
    public function __construct(
        private readonly ClientProvider $clientProvider,
        private readonly IndexNameResolver $names,
        private readonly StoreManagerInterface $storeManager,
        private readonly QueryBuilder $builder,
        private readonly ResponseMapper $mapper,
        private readonly Config $config
    ) {
    }

    public function search(SearchRequest $request): CmsResult
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            if ($storeId !== $request->getStoreId()) {
                throw new SearchUnavailableException('The CMS search request is not for the current store view.');
            }
            $alias = $this->names->getAlias($storeId);
            $client = $this->clientProvider->get();
        } catch (SearchUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new SearchUnavailableException(
                sprintf('The CMS search engine is not usable (%s).', get_class($exception))
            );
        }

        $seconds = $this->config->getSearchTimeoutMs() / 1000.0;
        try {
            $response = $client->search([
                'index' => $alias,
                'body' => $this->builder->build($request),
                'client' => ['timeout' => $seconds, 'connect_timeout' => $seconds, 'never_retry' => true],
            ]);
        } catch (NotFoundHttpException) {
            throw SearchUnavailableException::missingIndex($alias);
        } catch (\Throwable $exception) {
            throw new SearchUnavailableException(
                sprintf('CMS search request on %s failed (%s).', $alias, get_class($exception))
            );
        }

        if (!is_array($response)) {
            throw new SearchUnavailableException(sprintf('CMS search request on %s gave no answer.', $alias));
        }
        if (!empty($response['timed_out'])) {
            throw new SearchUnavailableException(sprintf('CMS search request on %s timed out in the engine.', $alias));
        }
        if (is_array($response['_shards'] ?? null) && (int) ($response['_shards']['failed'] ?? 0) > 0) {
            throw new SearchUnavailableException(sprintf('CMS search request on %s failed on some shards.', $alias));
        }
        return $this->mapper->map($response, $request);
    }
}
