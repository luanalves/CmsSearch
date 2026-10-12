<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Elasticsearch\Model\Config as SearchConfig;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\NoNodesAvailableException;
use OpenSearch\Exception\BadRequestHttpException;
use OpenSearch\Exception\NotFoundHttpException;
use OpenSearch\Namespaces\IndicesNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexDefinition;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexManager;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexNameResolver;

class IndexManagerTest extends TestCase
{
    private const ALIAS = 'magento2_thedevkitchen_cmssearch_2';
    private const PATTERN = 'magento2_thedevkitchen_cmssearch_*';
    private const CLIENT = ['timeout' => 60, 'connect_timeout' => 15];

    private Client&MockObject $client;
    private IndicesNamespace&MockObject $indices;
    private ClientProvider&MockObject $provider;
    private LoggerInterface&MockObject $logger;
    private StoreContext $store;
    /** @var list<int> */
    private array $storeIds = [1, 2, 3];
    /** @var array<string, array{aliases: array<string, array<mixed>>}> */
    private array $aliasResponse = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->indices = $this->createMock(IndicesNamespace::class);
        $this->client->method('indices')->willReturn($this->indices);
        $this->provider = $this->createMock(ClientProvider::class);
        $this->provider->method('get')->willReturn($this->client);
        $this->provider->method('getRequestOptions')->willReturn(self::CLIENT);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->store = new StoreContext(2, 2, 'w1', 'pt_BR', 'https://w1.test/', 'https://w1.test/media/', 'home');
        $this->indices->method('getAlias')->willReturnCallback(function (array $params): array {
            $this->assertSame(self::CLIENT, $params['client']);
            if ($params['index'] === self::PATTERN) {
                return $this->aliasResponse;
            }
            if (!isset($this->aliasResponse[$params['index']])) {
                throw new NotFoundHttpException('{"error":"index_not_found_exception","status":404}');
            }
            return [$params['index'] => $this->aliasResponse[$params['index']]];
        });
    }

    private function manager(): IndexManager
    {
        $config = $this->createMock(SearchConfig::class);
        $config->method('getIndexPrefix')->willReturn('magento2');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->with(false)->willReturnCallback(function (): array {
            return array_map(function (int $id): StoreInterface {
                $store = $this->createMock(StoreInterface::class);
                $store->method('getId')->willReturn((string) $id);
                return $store;
            }, $this->storeIds);
        });
        return new IndexManager($this->provider, new IndexNameResolver($config), new IndexDefinition(), $storeManager, $this->logger);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> the request parameters plus the bounded client timeouts
     */
    private static function req(array $params): array
    {
        return $params + ['client' => self::CLIENT];
    }

    /** @param array<string, list<string>> $indices index => aliases */
    private function existing(array $indices): void
    {
        $this->aliasResponse = [];
        foreach ($indices as $index => $aliases) {
            $this->aliasResponse[$index] = ['aliases' => array_fill_keys($aliases, [])];
        }
    }

    /**
     * @return array<string, mixed> a GET _mapping entry
     */
    private static function mapping(
        mixed $version = IndexDefinition::MAPPING_VERSION,
        mixed $module = 'Thedevkitchen_CmsSearch',
        mixed $dynamic = 'strict',
        string $locale = 'pt_BR'
    ): array {
        $meta = array_filter(
            ['module' => $module, 'mapping_version' => $version, 'store_id' => 2, 'locale' => $locale],
            static fn ($value): bool => $value !== null
        );
        return ['mappings' => array_filter(
            ['dynamic' => $dynamic, '_meta' => $meta === [] ? null : $meta, 'properties' => ['title' => ['type' => 'text']]],
            static fn ($value): bool => $value !== null
        )];
    }

    /** @param array<string, mixed> $mapping */
    private function mappingOf(string $index, array $mapping): void
    {
        $this->indices->method('getMapping')->with(self::req(['index' => $index]))->willReturn([$index => $mapping]);
    }

    // ---- ensureAlias -----------------------------------------------------------------------------------------

    public function testAnExistingAliasIsUsedAsIsAndItsMappingIsCheckedOncePerProcess(): void
    {
        $this->indices->method('existsAlias')->with(self::req(['name' => self::ALIAS]))->willReturn(true);
        $this->indices->expects($this->never())->method('create');
        $this->indices->expects($this->once())->method('getMapping')->with(self::req(['index' => self::ALIAS]))
            ->willReturn([self::ALIAS . '_v3' => self::mapping()]);
        $this->logger->expects($this->never())->method('warning');
        $manager = $this->manager();
        $this->assertSame(self::ALIAS, $manager->ensureAlias($this->store));
        $this->assertSame(self::ALIAS, $manager->ensureAlias($this->store));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function outdatedMappings(): array
    {
        return [
            'older mapping version' => [self::mapping(version: 0), 'mapping_version 0'],
            'another locale' => [self::mapping(locale: 'en_US'), 'locale en_US'],
        ];
    }

    /** @param array<string, mixed> $mapping */
    #[DataProvider('outdatedMappings')]
    public function testAnOutdatedIndexIsReportedButKept(array $mapping, string $expected): void
    {
        $this->indices->method('existsAlias')->willReturn(true);
        $this->mappingOf(self::ALIAS, $mapping);
        $this->indices->expects($this->never())->method('create');
        $this->indices->expects($this->never())->method('delete');
        $this->logger->expects($this->once())->method('warning')
            ->with($this->logicalAnd($this->stringContains($expected), $this->stringContains('--sync')));
        $this->assertSame(self::ALIAS, $this->manager()->ensureAlias($this->store));
    }

    public function testTheFirstIndexIsCreatedTogetherWithItsAlias(): void
    {
        $this->indices->method('existsAlias')->willReturn(false);
        $this->indices->expects($this->once())->method('create')->willReturnCallback(function (array $params): array {
            $this->assertSame(self::ALIAS . '_v1', $params['index']);
            $this->assertEquals([self::ALIAS => new \stdClass()], $params['body']['aliases']);
            $this->assertSame('strict', $params['body']['mappings']['dynamic']);
            $this->assertSame('brazilian', $params['body']['mappings']['properties']['text']['analyzer']);
            $this->assertSame(self::CLIENT, $params['client']);
            return ['acknowledged' => true];
        });
        $this->assertSame(self::ALIAS, $this->manager()->ensureAlias($this->store));
    }

    public function testTheNextVersionSkipsExistingVersionsAndIgnoresForeignIndices(): void
    {
        $this->indices->method('existsAlias')->willReturn(false);
        $this->existing([
            self::ALIAS . '_v2' => [],
            self::ALIAS . '_v4' => [],
            'magento2_thedevkitchen_cmssearch_3_v9' => ['magento2_thedevkitchen_cmssearch_3'],
            'magento2_thedevkitchen_cmssearch_2_v7_backup' => [],
        ]);
        $this->indices->expects($this->once())->method('create')
            ->with($this->callback(static fn (array $params): bool => $params['index'] === self::ALIAS . '_v5'));
        $this->manager()->ensureAlias($this->store);
    }

    public function testAConcurrentCreationByAnotherConsumerIsTolerated(): void
    {
        $this->indices->method('existsAlias')->willReturnOnConsecutiveCalls(false, true);
        $this->indices->method('create')->willThrowException(new BadRequestHttpException('resource_already_exists_exception'));
        $this->assertSame(self::ALIAS, $this->manager()->ensureAlias($this->store));
    }

    public function testACreationFailureWithoutAliasIsAnIndexingErrorWithoutTheRawCause(): void
    {
        $this->indices->method('existsAlias')->willReturn(false);
        $this->indices->method('create')->willThrowException(new BadRequestHttpException("illegal_argument_exception Preview of field's value: 'SECRET'"));
        try {
            $this->manager()->ensureAlias($this->store);
            $this->fail('IndexingException expected');
        } catch (IndexingException $exception) {
            $this->assertStringStartsWith('Could not create the CMS search index magento2_thedevkitchen_cmssearch_2_v1: ', $exception->getMessage());
            $this->assertStringNotContainsString('SECRET', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testAnUnreachableClusterIsAnIndexingError(): void
    {
        $this->indices->method('existsAlias')->willThrowException(new \RuntimeException('Connection refused'));
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('OpenSearch request failed (alias check magento2_thedevkitchen_cmssearch_2): RuntimeException: Connection refused');
        $this->manager()->ensureAlias($this->store);
    }

    public function testNoIndexAtAllStartsAtVersionOne(): void
    {
        $this->indices->method('existsAlias')->willReturn(false);
        $this->indices->expects($this->once())->method('create')
            ->with($this->callback(static fn (array $params): bool => $params['index'] === self::ALIAS . '_v1'));
        $this->manager()->ensureAlias($this->store);
    }

    // ---- createIndex ------------------------------------------------------------------------------------------

    public function testAFullRebuildIndexIsCreatedWithOnlyItsOwnBuildAlias(): void
    {
        $this->existing([self::ALIAS . '_v3' => [self::ALIAS]]);
        $this->indices->expects($this->once())->method('create')->willReturnCallback(function (array $params): array {
            $this->assertSame(self::ALIAS . '_v4', $params['index']);
            $this->assertEquals([self::ALIAS . '_v4_build' => new \stdClass()], $params['body']['aliases']);
            return [];
        });
        $this->assertSame(self::ALIAS . '_v4', $this->manager()->createIndex($this->store));
    }

    // ---- bulk -------------------------------------------------------------------------------------------------

    /**
     * REFRESH DECISION (no freshness SLA): the consumer never waits for a refresh (wait_for cost ~0.5 s per store view
     * and message); the periodic/search-idle refresh makes the change searchable, tests refresh explicitly.
     */
    public function testIncrementalBulkWritesThroughTheAliasWithoutWaitingForARefresh(): void
    {
        $this->client->expects($this->once())->method('bulk')->with($this->callback(function (array $params): bool {
            $this->assertArrayNotHasKey('refresh', $params);
            return true;
        }))->willReturn(['errors' => false, 'items' => []]);
        $this->manager()->bulk(self::ALIAS, [['action' => 'delete', 'id' => 'page_6']], true);
    }

    public function testIncrementalBulkWritesThroughTheAlias(): void
    {
        $this->client->expects($this->once())->method('bulk')->with(self::req([
            'index' => self::ALIAS,
            'body' => [
                ['index' => ['_id' => 'page_5']],
                ['title' => 'A'],
                ['delete' => ['_id' => 'page_6']],
            ],
            'require_alias' => 'true',
        ]))->willReturn(['errors' => false, 'items' => []]);
        $this->manager()->bulk(self::ALIAS, [
            ['action' => 'index', 'id' => 'page_5', 'document' => ['title' => 'A']],
            ['action' => 'delete', 'id' => 'page_6'],
        ], true);
    }

    public function testFullRebuildBulkWritesThroughTheBuildAliasSoAVanishedIndexIsNeverAutoCreated(): void
    {
        $this->client->expects($this->once())->method('bulk')->with(self::req([
            'index' => self::ALIAS . '_v4_build',
            'body' => [['index' => ['_id' => 'page_5']], ['title' => 'A']],
            'require_alias' => 'true',
        ]))->willReturn(['errors' => false]);
        $this->manager()->bulk(self::ALIAS . '_v4', [['action' => 'index', 'id' => 'page_5', 'document' => ['title' => 'A']]], false);
    }

    public function testABuildIndexDeletedByAConcurrentSwapMakesTheNextBulkFail(): void
    {
        // real shape: A's switchAlias deleted B's _v5 (and with it _v5_build); B's next bulk hits require_alias
        $this->client->method('bulk')->willReturn(['errors' => true, 'items' => [
            ['index' => ['_id' => 'page_5', 'status' => 404, 'error' => ['type' => 'index_not_found_exception', 'reason' => 'no such index [magento2_thedevkitchen_cmssearch_2_v5_build] and [require_alias] request flag is [true]']]],
        ]]);
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('first: index page_5 (404 index_not_found_exception): no such index [magento2_thedevkitchen_cmssearch_2_v5_build]');
        $this->manager()->bulk(self::ALIAS . '_v5', [['action' => 'index', 'id' => 'page_5', 'document' => ['title' => 'A']]], false);
    }

    /** @return array<string, array{string, bool}> */
    public static function invalidTargets(): array
    {
        return [
            'incremental into a concrete version' => [self::ALIAS . '_v4', true],
            'full rebuild into the live alias' => [self::ALIAS, false],
            'full rebuild into a build alias name' => [self::ALIAS . '_v4_build', false],
            'native product alias' => ['magento2_product_2', true],
            'native product index' => ['magento2_product_2_v59', false],
            'wildcard' => [self::PATTERN, true],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function testBulkOnlyWritesIntoOurAliasOrOneOfOurVersions(string $target, bool $incremental): void
    {
        $this->client->expects($this->never())->method('bulk');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->bulk($target, [['action' => 'delete', 'id' => 'page_6']], $incremental);
    }

    public function testAnEmptyBulkSendsNothing(): void
    {
        $this->client->expects($this->never())->method('bulk');
        $this->manager()->bulk(self::ALIAS, [], true);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidOperations(): array
    {
        return [
            'unknown action' => [['action' => 'update', 'id' => 'page_5', 'document' => ['title' => 'A']]],
            'index without document' => [['action' => 'index', 'id' => 'page_5']],
            'index with a list document' => [['action' => 'index', 'id' => 'page_5', 'document' => ['A']]],
            'empty id' => [['action' => 'delete', 'id' => '']],
            'missing id' => [['action' => 'delete']],
        ];
    }

    /** @param array<string, mixed> $operation */
    #[DataProvider('invalidOperations')]
    public function testMalformedOperationsAreRejectedBeforeAnythingIsSent(array $operation): void
    {
        $this->client->expects($this->never())->method('bulk');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->bulk(self::ALIAS, [$operation], true);
    }

    public function testDeletingAMissingDocumentIsNotAnError(): void
    {
        // real OpenSearch 2.12 shape: a delete of a missing document carries no "error", only result not_found
        $this->client->method('bulk')->willReturn(['errors' => false, 'items' => [
            ['delete' => ['_id' => 'page_6', 'status' => 404, 'result' => 'not_found']],
        ]]);
        $this->manager()->bulk(self::ALIAS, [['action' => 'delete', 'id' => 'page_6']], true);
        $this->addToAssertionCount(1);
    }

    public function testAMissingDocumentIsNotCountedAmongTheFailuresOfAFailedBulk(): void
    {
        $this->client->method('bulk')->willReturn(['errors' => true, 'items' => [
            ['delete' => ['_id' => 'page_6', 'status' => 404, 'result' => 'not_found']],
            ['index' => ['_id' => 'page_5', 'status' => 429, 'error' => ['type' => 'cluster_block_exception', 'reason' => 'index [x] blocked by: [TOO_MANY_REQUESTS/12/disk usage exceeded flood-stage watermark, index has read-only-allow-delete block];']]],
        ]]);
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('1 of 2 bulk operation(s) failed on magento2_thedevkitchen_cmssearch_2; first: index page_5 (429 cluster_block_exception): index [x] blocked by');
        $this->manager()->bulk(self::ALIAS, [
            ['action' => 'delete', 'id' => 'page_6'],
            ['action' => 'index', 'id' => 'page_5', 'document' => ['title' => 'A']],
        ], true);
    }

    public function testADeleteThroughAMissingAliasIsAnError(): void
    {
        // real shape with require_alias=true and no alias: status 404 on a delete, but WITH an error
        $this->client->method('bulk')->willReturn(['errors' => true, 'items' => [
            ['delete' => ['_id' => 'page_6', 'status' => 404, 'error' => ['type' => 'index_not_found_exception', 'reason' => 'no such index [magento2_thedevkitchen_cmssearch_2]']]],
        ]]);
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('1 of 1 bulk operation(s) failed on magento2_thedevkitchen_cmssearch_2; first: delete page_6 (404 index_not_found_exception): no such index');
        $this->manager()->bulk(self::ALIAS, [['action' => 'delete', 'id' => 'page_6']], true);
    }

    public function testFailedItemsMakeTheBulkFailAndAreLoggedWithoutReasons(): void
    {
        $this->client->method('bulk')->willReturn(['errors' => true, 'items' => [
            ['index' => ['_id' => 'page_5', 'status' => 201, 'result' => 'created']],
            ['index' => ['_id' => 'page_6', 'status' => 400, 'error' => ['type' => 'strict_dynamic_mapping_exception', 'reason' => 'mapping set to strict, dynamic introduction of [bad] within [_doc] is not allowed']]],
            ['index' => ['_id' => 'page_7', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => "failed to parse field [title]. Preview of field's value: 'SECRET'"]]],
        ]]);
        $this->logger->expects($this->once())->method('warning')->with(
            $this->stringContains('2 of 3 bulk operation(s) failed'),
            ['target' => self::ALIAS, 'failures' => [
                ['action' => 'index', 'id' => 'page_6', 'status' => 400, 'type' => 'strict_dynamic_mapping_exception'],
                ['action' => 'index', 'id' => 'page_7', 'status' => 400, 'type' => 'mapper_parsing_exception'],
            ]]
        );
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('2 of 3 bulk operation(s) failed on magento2_thedevkitchen_cmssearch_2; first: index page_6 (400 strict_dynamic_mapping_exception): mapping set to strict, dynamic introduction of [bad] within [_doc] is not allowed');
        $this->manager()->bulk(self::ALIAS, [
            ['action' => 'index', 'id' => 'page_5', 'document' => ['title' => 'A']],
            ['action' => 'index', 'id' => 'page_6', 'document' => ['bad' => 1]],
            ['action' => 'index', 'id' => 'page_7', 'document' => ['title' => ['SECRET']]],
        ], true);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function valueQuotingErrors(): array
    {
        return [
            // real OpenSearch 2.12 reasons: the type is not allow-listed, so its reason is never shown
            'mapper parsing' => [[
                'type' => 'mapper_parsing_exception',
                'reason' => "failed to parse field [updated_at] of type [date] in document with id 'page_3'. Preview of field's value: 'SECRET PAGE CONTENT'",
                'caused_by' => ['type' => 'illegal_argument_exception', 'reason' => 'failed to parse date field [SECRET PAGE CONTENT]'],
            ]],
            'illegal argument' => [['type' => 'illegal_argument_exception', 'reason' => 'Document contains at least one immense term in field="title.keyword": SECRET']],
            // second layer: an allow-listed type whose reason would still quote a value is cut at the marker
            'allow-listed type quoting a value' => [['type' => 'version_conflict_engine_exception', 'reason' => "conflict. Preview of field's value: 'SECRET'"]],
        ];
    }

    /** @param array<string, mixed> $error */
    #[DataProvider('valueQuotingErrors')]
    public function testBulkErrorsNeverQuoteDocumentContent(array $error): void
    {
        $this->client->method('bulk')->willReturn(['errors' => true, 'items' => [
            ['index' => ['_id' => 'page_3', 'status' => 400, 'error' => $error]],
        ]]);
        try {
            $this->manager()->bulk(self::ALIAS, [['action' => 'index', 'id' => 'page_3', 'document' => ['updated_at' => 'SECRET PAGE CONTENT']]], true);
            $this->fail('IndexingException expected');
        } catch (IndexingException $exception) {
            $this->assertStringNotContainsString('SECRET', $exception->getMessage());
            $this->assertStringContainsString('first: index page_3 (400 ' . $error['type'] . ')', $exception->getMessage());
        }
    }

    public function testARequestLevelBulkFailureIsAnIndexingErrorWithoutDocumentContent(): void
    {
        $this->client->method('bulk')->willThrowException(new BadRequestHttpException(
            '{"error":{"type":"mapper_parsing_exception","reason":"Preview of field\'s value: \'SECRET\'"},"status":400}'
        ));
        try {
            $this->manager()->bulk(self::ALIAS, [['action' => 'index', 'id' => 'page_3', 'document' => ['title' => 'SECRET']]], true);
            $this->fail('IndexingException expected');
        } catch (IndexingException $exception) {
            $this->assertStringNotContainsString('SECRET', $exception->getMessage());
            $this->assertStringStartsWith('OpenSearch request failed (bulk magento2_thedevkitchen_cmssearch_2): OpenSearch\Exception\BadRequestHttpException:', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testAnErrorFlagWithoutAnyFailedItemIsStillAnError(): void
    {
        $this->client->method('bulk')->willReturn(['errors' => true, 'items' => []]);
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('bulk request on magento2_thedevkitchen_cmssearch_2 reported errors without a failed item');
        $this->manager()->bulk(self::ALIAS, [['action' => 'delete', 'id' => 'page_6']], true);
    }

    // ---- one retry with a fresh client after a transport failure ------------------------------------------------

    public function testATransportFailureResetsTheClientAndRetriesOnce(): void
    {
        $calls = 0;
        $this->client->expects($this->exactly(2))->method('bulk')->willReturnCallback(function () use (&$calls): array {
            if (++$calls === 1) {
                throw new NoNodesAvailableException('No alive nodes found in your cluster');
            }
            return ['errors' => false, 'items' => []];
        });
        $this->provider->expects($this->once())->method('reset');
        $this->manager()->bulk(self::ALIAS, [['action' => 'delete', 'id' => 'page_6']], true);
    }

    public function testTheRetryHappensOnlyOnce(): void
    {
        $this->client->expects($this->exactly(2))->method('bulk')
            ->willThrowException(new NoNodesAvailableException('No alive nodes found in your cluster'));
        $this->provider->expects($this->once())->method('reset');
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('OpenSearch request failed (bulk magento2_thedevkitchen_cmssearch_2): OpenSearch\Common\Exceptions\NoNodesAvailableException: No alive nodes');
        $this->manager()->bulk(self::ALIAS, [['action' => 'delete', 'id' => 'page_6']], true);
    }

    public function testAnHttpErrorIsNotRetried(): void
    {
        $this->client->expects($this->once())->method('bulk')->willThrowException(new BadRequestHttpException('bad'));
        $this->provider->expects($this->never())->method('reset');
        $this->expectException(IndexingException::class);
        $this->manager()->bulk(self::ALIAS, [['action' => 'delete', 'id' => 'page_6']], true);
    }

    // ---- refresh ----------------------------------------------------------------------------------------------

    public function testRefreshTargetsOurVersionOrAlias(): void
    {
        $refreshed = [];
        $this->indices->method('refresh')->willReturnCallback(function (array $params) use (&$refreshed): array {
            $this->assertSame(self::CLIENT, $params['client']);
            $refreshed[] = $params['index'];
            return [];
        });
        $this->manager()->refresh(self::ALIAS . '_v4');
        $this->manager()->refresh(self::ALIAS);
        $this->assertSame([self::ALIAS . '_v4', self::ALIAS], $refreshed);
    }

    public function testRefreshRefusesForeignTargets(): void
    {
        $this->indices->expects($this->never())->method('refresh');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->refresh('magento2_product_2_v59');
    }

    // ---- switchAlias ------------------------------------------------------------------------------------------

    public function testTheAliasSwitchIsAtomicAndRemovesOnlyThisStoresOldVersions(): void
    {
        $this->existing([
            self::ALIAS . '_v3' => [self::ALIAS],
            self::ALIAS . '_v2' => [],
            self::ALIAS . '_v4' => [self::ALIAS . '_v4_build'],
            'magento2_thedevkitchen_cmssearch_3_v1' => ['magento2_thedevkitchen_cmssearch_3'],
        ]);
        $this->mappingOf(self::ALIAS . '_v4', self::mapping());
        $calls = [];
        $this->indices->expects($this->once())->method('updateAliases')->with(self::req(['body' => ['actions' => [
            ['remove' => ['index' => self::ALIAS . '_v3', 'alias' => self::ALIAS]],
            ['add' => ['index' => self::ALIAS . '_v4', 'alias' => self::ALIAS]],
            ['remove' => ['index' => self::ALIAS . '_v4', 'alias' => self::ALIAS . '_v4_build']],
        ]]]))->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'swap';
            return ['acknowledged' => true];
        });
        $this->indices->method('delete')->willReturnCallback(function (array $params) use (&$calls): array {
            $this->assertSame(self::CLIENT, $params['client']);
            $calls[] = $params['index'];
            return [];
        });
        $this->assertSame(
            ['deleted' => [self::ALIAS . '_v2', self::ALIAS . '_v3'], 'leftovers' => []],
            $this->manager()->switchAlias($this->store, self::ALIAS . '_v4')
        );
        $this->assertSame(['swap', self::ALIAS . '_v2', self::ALIAS . '_v3'], $calls);
    }

    public function testAFailedCleanupAfterTheSwapNeverThrowsSoTheLiveIndexIsNeverRolledBack(): void
    {
        $this->existing([self::ALIAS . '_v3' => [self::ALIAS], self::ALIAS . '_v2' => [], self::ALIAS . '_v4' => []]);
        $this->mappingOf(self::ALIAS . '_v4', self::mapping());
        $this->indices->expects($this->once())->method('updateAliases')->willReturn(['acknowledged' => true]);
        $this->indices->method('delete')->willReturnCallback(function (array $params): array {
            if ($params['index'] === self::ALIAS . '_v2') {
                throw new BadRequestHttpException('cluster_block_exception');
            }
            return [];
        });
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains(self::ALIAS . '_v2'));
        $this->assertSame(
            ['deleted' => [self::ALIAS . '_v3'], 'leftovers' => [self::ALIAS . '_v2']],
            $this->manager()->switchAlias($this->store, self::ALIAS . '_v4')
        );
    }

    public function testAFailedSwitchDeletesNothing(): void
    {
        $this->existing([self::ALIAS . '_v3' => [self::ALIAS], self::ALIAS . '_v4' => []]);
        $this->mappingOf(self::ALIAS . '_v4', self::mapping());
        $this->indices->method('updateAliases')->willThrowException(new BadRequestHttpException('aliases_not_found_exception'));
        $this->indices->expects($this->never())->method('delete');
        $this->expectException(IndexingException::class);
        $this->manager()->switchAlias($this->store, self::ALIAS . '_v4');
    }

    public function testASwitchReportedAsFailedThatWasAppliedIsASuccess(): void
    {
        // e.g. the response timed out, the retry's "remove" then failed because the first request had been applied
        $this->existing([self::ALIAS . '_v3' => [self::ALIAS], self::ALIAS . '_v4' => []]);
        $this->mappingOf(self::ALIAS . '_v4', self::mapping());
        $this->indices->method('updateAliases')->willReturnCallback(function (): array {
            $this->existing([self::ALIAS . '_v3' => [], self::ALIAS . '_v4' => [self::ALIAS]]);
            throw new BadRequestHttpException('aliases_not_found_exception');
        });
        $this->indices->expects($this->once())->method('delete')->with(self::req(['index' => self::ALIAS . '_v3']));
        $this->assertSame(
            ['deleted' => [self::ALIAS . '_v3'], 'leftovers' => []],
            $this->manager()->switchAlias($this->store, self::ALIAS . '_v4')
        );
    }

    public function testTheAliasCannotMoveToAnIndexThatDoesNotExist(): void
    {
        $this->existing([self::ALIAS . '_v3' => [self::ALIAS]]);
        $this->indices->expects($this->never())->method('updateAliases');
        $this->indices->expects($this->never())->method('delete');
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('magento2_thedevkitchen_cmssearch_2_v4 does not exist');
        $this->manager()->switchAlias($this->store, self::ALIAS . '_v4');
    }

    public function testTheAliasCanOnlyMoveToAnIndexOfTheSameStore(): void
    {
        $this->indices->expects($this->never())->method('updateAliases');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->switchAlias($this->store, 'magento2_thedevkitchen_cmssearch_3_v1');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function foreignMappings(): array
    {
        return [
            'auto-created by a write (dynamic, no _meta)' => [self::mapping(version: null, module: null, dynamic: null)],
            'another module' => [self::mapping(module: 'Other_Module')],
            'older mapping version' => [self::mapping(version: 0)],
            'mapping version as a string' => [self::mapping(version: (string) IndexDefinition::MAPPING_VERSION)],
            'not strict' => [self::mapping(dynamic: 'true')],
        ];
    }

    /** @param array<string, mixed> $mapping */
    #[DataProvider('foreignMappings')]
    public function testTheAliasOnlyMovesToAnIndexWithTheCurrentStrictMappingOfThisModule(array $mapping): void
    {
        $this->existing([self::ALIAS . '_v3' => [self::ALIAS], self::ALIAS . '_v4' => []]);
        $this->mappingOf(self::ALIAS . '_v4', $mapping);
        $this->indices->expects($this->never())->method('updateAliases');
        $this->indices->expects($this->never())->method('delete');
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('magento2_thedevkitchen_cmssearch_2_v4 does not have the current CMS search mapping');
        $this->manager()->switchAlias($this->store, self::ALIAS . '_v4');
    }

    // ---- dropIndex / dropOrphans ------------------------------------------------------------------------------

    public function testOnlyOurIndicesCanBeDropped(): void
    {
        $this->indices->expects($this->never())->method('delete');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->dropIndex('magento2_product_2_v59');
    }

    public function testOurAliasIsNotADroppableIndex(): void
    {
        $this->indices->expects($this->never())->method('delete');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->dropIndex(self::ALIAS);
    }

    public function testAnIndexServingTheAliasIsNeverDropped(): void
    {
        $this->existing([self::ALIAS . '_v4' => [self::ALIAS]]);
        $this->indices->expects($this->never())->method('delete');
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('magento2_thedevkitchen_cmssearch_2_v4 is live (alias magento2_thedevkitchen_cmssearch_2) and is not dropped');
        $this->manager()->dropIndex(self::ALIAS . '_v4');
    }

    public function testAnUnfinishedRebuildIndexIsDroppedByItsExactName(): void
    {
        $this->existing([self::ALIAS . '_v4' => [self::ALIAS . '_v4_build']]);
        $this->indices->expects($this->once())->method('delete')->with(self::req(['index' => self::ALIAS . '_v4']));
        $this->manager()->dropIndex(self::ALIAS . '_v4');
    }

    public function testDroppingAMissingIndexIsANoOp(): void
    {
        $this->indices->expects($this->never())->method('delete');
        $this->manager()->dropIndex(self::ALIAS . '_v4');
    }

    public function testOrphansOfDeletedStoreViewsAreDroppedAgainstTheCompleteStoreList(): void
    {
        $this->storeIds = [1, 2, 3];
        $this->existing([
            self::ALIAS . '_v1' => [self::ALIAS],
            'magento2_thedevkitchen_cmssearch_3_v1' => ['magento2_thedevkitchen_cmssearch_3'],
            'magento2_thedevkitchen_cmssearch_9_v2' => ['magento2_thedevkitchen_cmssearch_9'],
            'magento2_product_9_v1' => [],
            'magento2_thedevkitchen_cmssearch_9' => [],
        ]);
        $this->indices->expects($this->once())->method('delete')->with(self::req(['index' => 'magento2_thedevkitchen_cmssearch_9_v2']));
        $this->assertSame(['magento2_thedevkitchen_cmssearch_9_v2'], $this->manager()->dropOrphans());
    }

    public function testOrphanCleanupWithoutAnyStoreViewIsRefused(): void
    {
        $this->storeIds = [];
        $this->existing([self::ALIAS . '_v1' => [self::ALIAS]]);
        $this->indices->expects($this->never())->method('delete');
        $this->expectException(IndexingException::class);
        $this->manager()->dropOrphans();
    }
}
