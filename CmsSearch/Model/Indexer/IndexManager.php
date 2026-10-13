<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Store\Model\StoreManagerInterface;
use OpenSearch\Common\Exceptions\NoNodesAvailableException;
use OpenSearch\Common\Exceptions\TransportException;
use OpenSearch\Exception\NotFoundHttpException;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider;

/**
 * The only class that writes to OpenSearch. Searches read the alias of a store view; writes go through the alias
 * (incremental) or through the "build alias" ({version}_build) of a new version that replaces it atomically (full
 * rebuild). Every write uses require_alias=true: the cluster auto-creates indices (action.auto_create_index=true),
 * so a write into a version deleted meanwhile (e.g. by a concurrent --sync) fails instead of creating a
 * dynamically mapped index. Only indices recognised by IndexNameResolver::parse() are ever changed or deleted.
 *
 * Every OpenSearch failure surfaces as IndexingException (transient for the reindex handler) whose message never
 * quotes document content and which never chains the raw OpenSearch exception (its message is the response body).
 * A transport failure (dead node, timeout, connection error) is retried once with a fresh client.
 *
 * Mapping upgrades (IndexDefinition::MAPPING_VERSION): ensureAlias() only WARNS about an index with another mapping
 * version or locale, it never recreates one. Path: stop the consumers; deploy the code with MAPPING_VERSION + 1;
 * ensureMapping() is NOT implemented, use `bin/magento thedevkitchen:cmssearch:reindex --sync` (a manual
 * `PUT {alias}/_mapping` for an additive property must also send the complete `_meta`: module, mapping_version,
 * store_id, locale); start the consumers; publish the page reindex
 * (`thedevkitchen:cmssearch:reindex --entity=page`).
 */
class IndexManager
{
    public const MODULE = 'Thedevkitchen_CmsSearch';
    public const BUILD_ALIAS_SUFFIX = '_build';

    private const MAX_ERROR_LENGTH = 300;
    private const MAX_LOGGED_FAILURES = 20;

    /**
     * Item error types whose reason names indices/fields/limits but never a document value; the reason of any other
     * type is left out (type and status only).
     */
    private const VALUE_FREE_REASON_TYPES = [
        'strict_dynamic_mapping_exception',
        'index_not_found_exception',
        'cluster_block_exception',
        'index_closed_exception',
        'es_rejected_execution_exception',
        'version_conflict_engine_exception',
    ];

    /**
     * Second layer: parts of OpenSearch error texts that quote indexed values; everything from the first marker on
     * is dropped.
     */
    private const CONTENT_MARKERS = [
        "Preview of field's value",
        'caused_by',
        'The prefix of the first immense term',
        'For input string',
    ];

    /** @var array<string, true> aliases whose mapping was checked by this process */
    private array $checkedAliases = [];

    public function __construct(
        private readonly ClientProvider $clientProvider,
        private readonly IndexNameResolver $names,
        private readonly IndexDefinition $definition,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return string the alias of the store view, created (with its first version) when missing
     * @throws IndexingException
     */
    public function ensureAlias(StoreContext $store): string
    {
        $alias = $this->names->getAlias($store->getStoreId());
        if ($this->aliasExists($alias)) {
            $this->warnAboutOutdatedMapping($store, $alias);
            return $alias;
        }
        $index = $this->names->getVersionName($store->getStoreId(), $this->nextVersion($store->getStoreId()));
        $body = $this->definition->build($store);
        $body['aliases'] = [$alias => new \stdClass()];
        try {
            $this->call('create ' . $index, fn () => $this->clientProvider->get()->indices()->create(
                $this->params(['index' => $index, 'body' => $body])
            ));
        } catch (IndexingException $exception) {
            if (!$this->aliasExists($alias)) { // another consumer may have won the race
                throw new IndexingException(
                    sprintf('Could not create the CMS search index %s: %s', $index, $exception->getMessage())
                );
            }
        }
        return $alias;
    }

    /**
     * @return string a new, empty version for a full rebuild; it only carries its own build alias until switchAlias()
     * @throws IndexingException
     */
    public function createIndex(StoreContext $store): string
    {
        $index = $this->names->getVersionName($store->getStoreId(), $this->nextVersion($store->getStoreId()));
        $body = $this->definition->build($store);
        $body['aliases'] = [$this->buildAlias($index) => new \stdClass()];
        $this->call('create ' . $index, fn () => $this->clientProvider->get()->indices()->create(
            $this->params(['index' => $index, 'body' => $body])
        ));
        return $index;
    }

    /**
     * @param string $target incremental: the alias of a store view; full rebuild: a version from createIndex()
     * @param list<array{action: string, id: string, document?: array<string, mixed>}> $operations
     * @param bool $incremental true: the write must hit the existing alias; false: the version is filled through its
     *                          build alias. Neither waits for a refresh (REFRESH DECISION, no freshness SLA): an
     *                          incremental change becomes searchable at the next periodic refresh (refresh_interval
     *                          1s, or the first search of a search-idle index); FullReindexer refreshes once before
     *                          the alias switch; tests call refresh() explicitly
     * @throws IndexingException|\InvalidArgumentException
     */
    public function bulk(string $target, array $operations, bool $incremental): void
    {
        if ($incremental ? $this->names->parseAlias($target) === null : $this->names->parse($target) === null) {
            throw new \InvalidArgumentException(sprintf(
                '%s is not %s.',
                $target,
                $incremental ? 'the alias of a CMS search index' : 'a CMS search index version'
            ));
        }
        if ($operations === []) {
            return;
        }
        $body = [];
        foreach ($operations as $operation) {
            $id = $operation['id'] ?? null;
            if (!is_string($id) || $id === '') {
                throw new \InvalidArgumentException('Every bulk operation needs a non-empty document id.');
            }
            $action = $operation['action'] ?? null;
            if ($action === 'index') {
                $document = $operation['document'] ?? null;
                if (!is_array($document) || $document === [] || array_is_list($document)) {
                    throw new \InvalidArgumentException(sprintf('The index operation of %s needs a document.', $id));
                }
                $body[] = ['index' => ['_id' => $id]];
                $body[] = $document;
            } elseif ($action === 'delete') {
                $body[] = ['delete' => ['_id' => $id]];
            } else {
                throw new \InvalidArgumentException(sprintf('Unknown bulk action "%s".', (string) $action));
            }
        }
        $params = [
            'index' => $incremental ? $target : $this->buildAlias($target),
            'body' => $body,
            'require_alias' => 'true',
        ];
        $response = $this->call('bulk ' . $target, fn () => $this->clientProvider->get()->bulk($this->params($params)));
        if (is_array($response) && empty($response['errors'])) {
            return;
        }
        $failures = [];
        $first = null;
        foreach ((is_array($response) ? (array) ($response['items'] ?? []) : []) as $item) {
            $action = (string) array_key_first((array) $item);
            $result = (array) ($item[$action] ?? []);
            // a delete of a missing document is result=not_found WITHOUT an error (idempotent); a 404 that carries an
            // error (e.g. index_not_found_exception from require_alias) is a real failure
            if (!isset($result['error'])) {
                continue;
            }
            $error = $result['error'];
            $type = is_array($error) && is_string($error['type'] ?? null) ? $error['type'] : 'unknown';
            $failure = [
                'action' => $action,
                'id' => (string) ($result['_id'] ?? '?'),
                'status' => (int) ($result['status'] ?? 0),
                'type' => $type,
            ];
            $failures[] = $failure;
            if ($first === null) {
                $reason = is_array($error) && in_array($type, self::VALUE_FREE_REASON_TYPES, true)
                    ? trim((string) ($error['reason'] ?? ''))
                    : '';
                $first = sprintf('%s %s (%d %s)', $failure['action'], $failure['id'], $failure['status'], $type)
                    . ($reason !== '' ? ': ' . $reason : '');
            }
        }
        if ($first === null) {
            throw new IndexingException(sprintf(
                'The bulk request on %s reported errors without a failed item.',
                $target
            ));
        }
        $summary = sprintf('%d of %d bulk operation(s) failed on %s', count($failures), count($operations), $target);
        $this->logger->warning(
            $summary,
            ['target' => $target, 'failures' => array_slice($failures, 0, self::MAX_LOGGED_FAILURES)]
        );
        throw new IndexingException($summary . '; first: ' . $this->sanitize($first));
    }

    /**
     * @param string $target one of our versions or aliases
     * @throws IndexingException|\InvalidArgumentException
     */
    public function refresh(string $target): void
    {
        if ($this->names->parse($target) === null && $this->names->parseAlias($target) === null) {
            throw new \InvalidArgumentException(sprintf('%s is not a CMS search index or alias.', $target));
        }
        $this->call('refresh ' . $target, fn () => $this->clientProvider->get()->indices()->refresh(
            $this->params(['index' => $target])
        ));
    }

    /**
     * Points the store view's alias at $newIndex (which must carry this module's current strict mapping) in one atomic
     * _aliases call, also removing its build alias, then deletes the store's other versions (including an unfinished
     * rebuild of a concurrent --sync, whose next bulk then fails on its vanished build alias).
     *
     * Once the alias has moved this method NEVER throws: a version that could not be deleted is logged and returned
     * in "leftovers" (the next --sync removes it). An exception therefore always means "the alias did not move".
     *
     * @return array{deleted: list<string>, leftovers: list<string>}
     * @throws IndexingException|\InvalidArgumentException only before or instead of the swap
     */
    public function switchAlias(StoreContext $store, string $newIndex): array
    {
        $storeId = $store->getStoreId();
        $parsed = $this->names->parse($newIndex);
        if ($parsed === null || $parsed['store_id'] !== $storeId) {
            throw new \InvalidArgumentException(
                sprintf('%s is not a CMS search index of store view %d.', $newIndex, $storeId)
            );
        }
        $alias = $this->names->getAlias($storeId);
        $versions = $this->versions();
        if (!isset($versions[$newIndex])) {
            throw new IndexingException(sprintf('The CMS search index %s does not exist.', $newIndex));
        }
        $this->assertCurrentMapping($newIndex);
        $actions = [];
        foreach ($versions as $index => $info) {
            if ($index !== $newIndex && in_array($alias, $info['aliases'], true)) {
                $actions[] = ['remove' => ['index' => $index, 'alias' => $alias]];
            }
        }
        $actions[] = ['add' => ['index' => $newIndex, 'alias' => $alias]];
        if (in_array($this->buildAlias($newIndex), $versions[$newIndex]['aliases'], true)) {
            $actions[] = ['remove' => ['index' => $newIndex, 'alias' => $this->buildAlias($newIndex)]];
        }
        try {
            $this->call('alias switch ' . $alias, fn () => $this->clientProvider->get()->indices()->updateAliases(
                $this->params(['body' => ['actions' => $actions]])
            ));
        } catch (IndexingException $exception) {
            if (!$this->servesOnly($alias, $newIndex)) {
                throw $exception;
            }
            $this->logger->warning(sprintf(
                'The alias switch of %s reported a failure but %s already serves it: %s',
                $alias,
                $newIndex,
                $exception->getMessage()
            ));
        }
        $deleted = [];
        $leftovers = [];
        foreach ($versions as $index => $info) {
            if ($index === $newIndex || $info['store_id'] !== $storeId) {
                continue;
            }
            try {
                $this->delete($index);
                $deleted[] = $index;
            } catch (IndexingException $exception) {
                $leftovers[] = $index;
                $this->logger->warning(sprintf(
                    'The alias %s now serves %s, but the old version %s could not be deleted (the next --sync '
                    . 'removes it): %s',
                    $alias,
                    $newIndex,
                    $index,
                    $exception->getMessage()
                ));
            }
        }
        return ['deleted' => $deleted, 'leftovers' => $leftovers];
    }

    /**
     * Drops one of our versions, never one that serves a search alias (only its own build alias is allowed).
     * A missing index is a no-op.
     *
     * @throws IndexingException|\InvalidArgumentException
     */
    public function dropIndex(string $index): void
    {
        if ($this->names->parse($index) === null) {
            throw new \InvalidArgumentException(sprintf('%s is not a CMS search index.', $index));
        }
        $response = $this->call('aliases of ' . $index, function () use ($index) {
            try {
                return $this->clientProvider->get()->indices()->getAlias($this->params(['index' => $index]));
            } catch (NotFoundHttpException) {
                return null;
            }
        });
        if ($response === null) {
            return;
        }
        $aliases = array_map('strval', array_keys((array) ($response[$index]['aliases'] ?? [])));
        $held = array_values(array_diff($aliases, [$this->buildAlias($index)]));
        if ($held !== []) {
            throw new IndexingException(
                sprintf('%s is live (alias %s) and is not dropped.', $index, implode(', ', $held))
            );
        }
        $this->delete($index);
    }

    /**
     * Drops the indices of store views that no longer exist, judged against the complete store list.
     *
     * @return list<string> dropped indices
     * @throws IndexingException
     */
    public function dropOrphans(): array
    {
        $existing = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $id = (int) $store->getId();
            if ($id > 0) {
                $existing[] = $id;
            }
        }
        if ($existing === []) {
            throw new IndexingException('No store view was found; the orphan cleanup drops nothing.');
        }
        $dropped = [];
        foreach ($this->versions() as $index => $info) {
            if (!in_array($info['store_id'], $existing, true)) {
                $this->delete($index);
                $dropped[] = $index;
            }
        }
        return $dropped;
    }

    private function buildAlias(string $index): string
    {
        return $index . self::BUILD_ALIAS_SUFFIX;
    }

    private function aliasExists(string $alias): bool
    {
        return (bool) $this->call('alias check ' . $alias, fn () => $this->clientProvider->get()->indices()->existsAlias(
            $this->params(['name' => $alias])
        ));
    }

    /**
     * Logs (once per alias and process) when the live index was built with another mapping version or locale.
     */
    private function warnAboutOutdatedMapping(StoreContext $store, string $alias): void
    {
        if (isset($this->checkedAliases[$alias])) {
            return;
        }
        $this->checkedAliases[$alias] = true;
        try {
            $response = $this->call('mapping ' . $alias, fn () => $this->clientProvider->get()->indices()->getMapping(
                $this->params(['index' => $alias])
            ));
        } catch (IndexingException $exception) {
            $this->logger->warning(sprintf('The mapping of %s could not be checked: %s', $alias, $exception->getMessage()));
            return;
        }
        foreach ((array) $response as $index => $data) {
            $meta = (array) ($data['mappings']['_meta'] ?? []);
            $version = $meta['mapping_version'] ?? null;
            $locale = $meta['locale'] ?? null;
            if ($version !== IndexDefinition::MAPPING_VERSION || $locale !== $store->getLocale()) {
                $this->logger->warning(sprintf(
                    'The CMS search index %s (alias %s) has mapping_version %s and locale %s; this code expects %d '
                    . 'and %s. Run bin/magento thedevkitchen:cmssearch:reindex --sync.',
                    (string) $index,
                    $alias,
                    is_scalar($version) ? (string) $version : 'none',
                    is_scalar($locale) ? (string) $locale : 'none',
                    IndexDefinition::MAPPING_VERSION,
                    $store->getLocale()
                ));
            }
        }
    }

    /**
     * @throws IndexingException when $index was not created with this module's current strict mapping (for example
     *                           an index auto-created by a stray write)
     */
    private function assertCurrentMapping(string $index): void
    {
        $response = $this->call('mapping ' . $index, fn () => $this->clientProvider->get()->indices()->getMapping(
            $this->params(['index' => $index])
        ));
        $mapping = (array) ($response[$index]['mappings'] ?? []);
        $meta = (array) ($mapping['_meta'] ?? []);
        if (($mapping['dynamic'] ?? null) !== 'strict'
            || ($meta['module'] ?? null) !== self::MODULE
            || ($meta['mapping_version'] ?? null) !== IndexDefinition::MAPPING_VERSION
        ) {
            throw new IndexingException(sprintf(
                '%s does not have the current CMS search mapping (module %s, mapping_version %d, dynamic strict); '
                . 'it is not made live.',
                $index,
                self::MODULE,
                IndexDefinition::MAPPING_VERSION
            ));
        }
    }

    private function servesOnly(string $alias, string $index): bool
    {
        try {
            $versions = $this->versions();
        } catch (IndexingException) {
            return false;
        }
        foreach ($versions as $name => $info) {
            if (in_array($alias, $info['aliases'], true) !== ($name === $index)) {
                return false;
            }
        }
        return isset($versions[$index]);
    }

    /**
     * @param string $index a name already accepted by IndexNameResolver::parse()
     */
    private function delete(string $index): void
    {
        $this->call('delete ' . $index, fn () => $this->clientProvider->get()->indices()->delete(
            $this->params(['index' => $index])
        ));
    }

    private function nextVersion(int $storeId): int
    {
        $max = 0;
        foreach ($this->versions() as $info) {
            if ($info['store_id'] === $storeId) {
                $max = max($max, $info['version']);
            }
        }
        return $max + 1;
    }

    /**
     * @return array<string, array{store_id: int, version: int, aliases: list<string>}> our indices only, by name
     */
    private function versions(): array
    {
        $pattern = $this->names->getPattern();
        $response = $this->call('list ' . $pattern, function () use ($pattern) {
            try {
                return $this->clientProvider->get()->indices()->getAlias($this->params(['index' => $pattern]));
            } catch (NotFoundHttpException) {
                return [];
            }
        });
        $versions = [];
        foreach ((array) $response as $index => $data) {
            $parsed = $this->names->parse((string) $index);
            if ($parsed === null) {
                continue; // never touch an index this module did not create
            }
            $versions[(string) $index] = $parsed + [
                'aliases' => array_map('strval', array_keys((array) ($data['aliases'] ?? []))),
            ];
        }
        ksort($versions);
        return $versions;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function params(array $params): array
    {
        return $params + ['client' => $this->clientProvider->getRequestOptions()];
    }

    /**
     * Runs one OpenSearch request; a transport failure is retried once with a fresh client (every request of this
     * class is idempotent); any other or repeated failure becomes an IndexingException without the raw exception.
     *
     * @throws IndexingException
     */
    private function call(string $what, callable $request): mixed
    {
        for ($attempt = 1;; $attempt++) {
            try {
                return $request();
            } catch (IndexingException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                if ($attempt === 1 && $this->isTransportFailure($exception)) {
                    $this->logger->warning(sprintf(
                        'OpenSearch request (%s) failed with %s; retrying once with a new client.',
                        $what,
                        $exception::class
                    ));
                    $this->clientProvider->reset();
                    continue;
                }
                throw new IndexingException(
                    sprintf('OpenSearch request failed (%s): %s', $what, $this->describe($exception))
                );
            }
        }
    }

    private function isTransportFailure(\Throwable $exception): bool
    {
        return $exception instanceof NoNodesAvailableException || $exception instanceof TransportException;
    }

    private function describe(\Throwable $exception): string
    {
        return $exception::class . ': ' . $this->sanitize($exception->getMessage());
    }

    private function sanitize(string $message): string
    {
        foreach (self::CONTENT_MARKERS as $marker) {
            $position = strpos($message, $marker);
            if ($position !== false) {
                $message = substr($message, 0, $position) . '[value omitted]';
            }
        }
        return mb_substr(trim($message), 0, self::MAX_ERROR_LENGTH);
    }
}
