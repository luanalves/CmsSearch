<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use OpenSearch\Exception\NotFoundHttpException;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider;

/**
 * The only class that writes to OpenSearch. Searches read the alias of a store view; writes go through the alias
 * (incremental) or into a new version that replaces it atomically (full rebuild). Only indices recognised by
 * IndexNameResolver::parse() are ever changed or deleted.
 *
 * Every OpenSearch failure surfaces as IndexingException (transient for the reindex handler) whose message never
 * quotes document content: OpenSearch error reasons can echo field values, so they are cut before such parts.
 */
class IndexManager
{
    private const MAX_ERROR_LENGTH = 300;

    /**
     * Parts of OpenSearch error texts that quote the indexed values; everything from the first marker on is dropped.
     */
    private const CONTENT_MARKERS = [
        "Preview of field's value",
        'caused_by',
        'The prefix of the first immense term',
        'For input string',
    ];

    public function __construct(
        private readonly ClientProvider $clientProvider,
        private readonly IndexNameResolver $names,
        private readonly IndexDefinition $definition
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
            return $alias;
        }
        $index = $this->names->getVersionName($store->getStoreId(), $this->nextVersion($store->getStoreId()));
        $body = $this->definition->build($store);
        $body['aliases'] = [$alias => new \stdClass()];
        try {
            $this->clientProvider->get()->indices()->create($this->params(['index' => $index, 'body' => $body]));
        } catch (\Throwable $exception) {
            if (!$this->aliasExists($alias)) { // another consumer may have won the race
                throw new IndexingException(
                    sprintf('Could not create the CMS search index %s: %s', $index, $this->describe($exception)),
                    0,
                    $exception
                );
            }
        }
        return $alias;
    }

    /**
     * @return string a new, empty version for a full rebuild (no alias yet)
     * @throws IndexingException
     */
    public function createIndex(StoreContext $store): string
    {
        $index = $this->names->getVersionName($store->getStoreId(), $this->nextVersion($store->getStoreId()));
        $body = $this->definition->build($store);
        $this->call('create ' . $index, fn () => $this->clientProvider->get()->indices()->create(
            $this->params(['index' => $index, 'body' => $body])
        ));
        return $index;
    }

    /**
     * @param list<array{action: string, id: string, document?: array<string, mixed>}> $operations
     * @param bool $incremental true: $target is an alias; the write must hit an existing alias and be searchable when
     *                          this returns (refresh=wait_for); false: $target is a new version being filled
     * @throws IndexingException|\InvalidArgumentException
     */
    public function bulk(string $target, array $operations, bool $incremental): void
    {
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
        $params = ['index' => $target, 'body' => $body];
        if ($incremental) {
            $params['refresh'] = 'wait_for';
            $params['require_alias'] = 'true';
        }
        $response = $this->call('bulk ' . $target, fn () => $this->clientProvider->get()->bulk($this->params($params)));
        if (is_array($response) && empty($response['errors'])) {
            return;
        }
        $failures = [];
        foreach ((is_array($response) ? (array) ($response['items'] ?? []) : []) as $item) {
            $action = (string) array_key_first((array) $item);
            $result = (array) ($item[$action] ?? []);
            // a delete of a missing document is result=not_found WITHOUT an error (idempotent); a 404 that carries an
            // error (e.g. index_not_found_exception from require_alias) is a real failure
            if (!isset($result['error'])) {
                continue;
            }
            $error = $result['error'];
            $failures[] = sprintf(
                '%s %s: %s',
                $action,
                (string) ($result['_id'] ?? '?'),
                is_array($error)
                    ? trim((string) ($error['type'] ?? '') . ' ' . (string) ($error['reason'] ?? ''))
                    : (string) $error
            );
        }
        if ($failures === []) {
            throw new IndexingException(sprintf(
                'The bulk request on %s reported errors without a failed item.',
                $target
            ));
        }
        throw new IndexingException(sprintf(
            '%d of %d bulk operation(s) failed on %s; first: %s',
            count($failures),
            count($operations),
            $target,
            $this->sanitize($failures[0])
        ));
    }

    /**
     * @throws IndexingException
     */
    public function refresh(string $index): void
    {
        $this->call('refresh ' . $index, fn () => $this->clientProvider->get()->indices()->refresh(
            $this->params(['index' => $index])
        ));
    }

    /**
     * Points the store view's alias at $newIndex in one atomic _aliases call, then deletes the store's other versions.
     *
     * @return list<string> deleted index names
     * @throws IndexingException|\InvalidArgumentException
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
        $actions = [];
        foreach ($versions as $index => $info) {
            if ($index !== $newIndex && in_array($alias, $info['aliases'], true)) {
                $actions[] = ['remove' => ['index' => $index, 'alias' => $alias]];
            }
        }
        $actions[] = ['add' => ['index' => $newIndex, 'alias' => $alias]];
        $this->call('alias switch ' . $alias, fn () => $this->clientProvider->get()->indices()->updateAliases(
            $this->params(['body' => ['actions' => $actions]])
        ));
        $deleted = [];
        foreach ($versions as $index => $info) {
            if ($index !== $newIndex && $info['store_id'] === $storeId) {
                $this->delete($index);
                $deleted[] = $index;
            }
        }
        return $deleted;
    }

    /**
     * @throws IndexingException|\InvalidArgumentException
     */
    public function dropIndex(string $index): void
    {
        if ($this->names->parse($index) === null) {
            throw new \InvalidArgumentException(sprintf('%s is not a CMS search index.', $index));
        }
        $this->delete($index);
    }

    /**
     * @param int[] $existingStoreIds every store view that exists (never empty: that would drop every index)
     * @return list<string> dropped indices of store views that no longer exist
     * @throws IndexingException|\InvalidArgumentException
     */
    public function dropOrphans(array $existingStoreIds): array
    {
        $existing = array_values(array_filter(array_map('intval', $existingStoreIds), static fn (int $id): bool => $id > 0));
        if ($existing === []) {
            throw new \InvalidArgumentException('Orphan cleanup needs the ids of the existing store views.');
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

    private function aliasExists(string $alias): bool
    {
        return (bool) $this->call('alias check ' . $alias, fn () => $this->clientProvider->get()->indices()->existsAlias(
            $this->params(['name' => $alias])
        ));
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
     * Runs one OpenSearch request; any failure (unreachable cluster, timeout, HTTP error) becomes an IndexingException.
     *
     * @throws IndexingException
     */
    private function call(string $what, callable $request): mixed
    {
        try {
            return $request();
        } catch (IndexingException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new IndexingException(
                sprintf('OpenSearch request failed (%s): %s', $what, $this->describe($exception)),
                0,
                $exception
            );
        }
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
