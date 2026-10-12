<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Elasticsearch\Model\Config as SearchConfig;
use OpenSearch\Client;
use OpenSearch\Exception\BadRequestHttpException;
use OpenSearch\Exception\NotFoundHttpException;
use OpenSearch\Namespaces\IndicesNamespace;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
    private StoreContext $store;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->indices = $this->createMock(IndicesNamespace::class);
        $this->client->method('indices')->willReturn($this->indices);
        $this->store = new StoreContext(2, 2, 'w1', 'pt_BR', 'https://w1.test/', 'https://w1.test/media/', 'home');
    }

    private function manager(): IndexManager
    {
        $provider = $this->createMock(ClientProvider::class);
        $provider->method('get')->willReturn($this->client);
        $provider->method('getRequestOptions')->willReturn(self::CLIENT);
        $config = $this->createMock(SearchConfig::class);
        $config->method('getIndexPrefix')->willReturn('magento2');
        return new IndexManager($provider, new IndexNameResolver($config), new IndexDefinition());
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
        $response = [];
        foreach ($indices as $index => $aliases) {
            $response[$index] = ['aliases' => array_fill_keys($aliases, [])];
        }
        $this->indices->method('getAlias')->with(self::req(['index' => self::PATTERN]))->willReturn($response);
    }

    public function testAnExistingAliasIsUsedAsIs(): void
    {
        $this->indices->method('existsAlias')->with(self::req(['name' => self::ALIAS]))->willReturn(true);
        $this->indices->expects($this->never())->method('create');
        $this->assertSame(self::ALIAS, $this->manager()->ensureAlias($this->store));
    }

    public function testTheFirstIndexIsCreatedTogetherWithItsAlias(): void
    {
        $this->indices->method('existsAlias')->willReturn(false);
        $this->existing([]);
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
        $this->existing([]);
        $this->indices->method('create')->willThrowException(new BadRequestHttpException('resource_already_exists_exception'));
        $this->assertSame(self::ALIAS, $this->manager()->ensureAlias($this->store));
    }

    public function testACreationFailureWithoutAliasIsAnIndexingError(): void
    {
        $this->indices->method('existsAlias')->willReturn(false);
        $this->existing([]);
        $this->indices->method('create')->willThrowException(new \RuntimeException('No alive nodes'));
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('Could not create the CMS search index magento2_thedevkitchen_cmssearch_2_v1: RuntimeException: No alive nodes');
        $this->manager()->ensureAlias($this->store);
    }

    public function testAnUnreachableClusterIsAnIndexingError(): void
    {
        $this->indices->method('existsAlias')->willThrowException(new \RuntimeException('No alive nodes found in your cluster'));
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('OpenSearch request failed (alias check magento2_thedevkitchen_cmssearch_2): RuntimeException: No alive nodes found in your cluster');
        $this->manager()->ensureAlias($this->store);
    }

    public function testAFullRebuildIndexIsCreatedWithoutAlias(): void
    {
        $this->existing([self::ALIAS . '_v3' => [self::ALIAS]]);
        $this->indices->expects($this->once())->method('create')->willReturnCallback(function (array $params): array {
            $this->assertSame(self::ALIAS . '_v4', $params['index']);
            $this->assertArrayNotHasKey('aliases', $params['body']);
            return [];
        });
        $this->assertSame(self::ALIAS . '_v4', $this->manager()->createIndex($this->store));
    }

    public function testIncrementalBulkWritesThroughTheAliasAndWaitsForRefresh(): void
    {
        $this->client->expects($this->once())->method('bulk')->with(self::req([
            'index' => self::ALIAS,
            'body' => [
                ['index' => ['_id' => 'page_5']],
                ['title' => 'A'],
                ['delete' => ['_id' => 'page_6']],
            ],
            'refresh' => 'wait_for',
            'require_alias' => 'true',
        ]))->willReturn(['errors' => false, 'items' => []]);
        $this->manager()->bulk(self::ALIAS, [
            ['action' => 'index', 'id' => 'page_5', 'document' => ['title' => 'A']],
            ['action' => 'delete', 'id' => 'page_6'],
        ], true);
    }

    public function testFullRebuildBulkWritesIntoTheNewIndexWithoutRefresh(): void
    {
        $this->client->expects($this->once())->method('bulk')->with(self::req([
            'index' => self::ALIAS . '_v4',
            'body' => [['index' => ['_id' => 'page_5']], ['title' => 'A']],
        ]))->willReturn(['errors' => false]);
        $this->manager()->bulk(self::ALIAS . '_v4', [['action' => 'index', 'id' => 'page_5', 'document' => ['title' => 'A']]], false);
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

    /**
     * @dataProvider invalidOperations
     * @param array<string, mixed> $operation
     */
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
        $this->expectExceptionMessage('1 of 2 bulk operation(s) failed on magento2_thedevkitchen_cmssearch_2; first: index page_5: cluster_block_exception');
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
        $this->expectExceptionMessage('1 of 1 bulk operation(s) failed on magento2_thedevkitchen_cmssearch_2; first: delete page_6: index_not_found_exception no such index');
        $this->manager()->bulk(self::ALIAS, [['action' => 'delete', 'id' => 'page_6']], true);
    }

    public function testFailedItemsMakeTheBulkFail(): void
    {
        $this->client->method('bulk')->willReturn(['errors' => true, 'items' => [
            ['index' => ['_id' => 'page_5', 'status' => 201, 'result' => 'created']],
            ['index' => ['_id' => 'page_6', 'status' => 400, 'error' => ['type' => 'strict_dynamic_mapping_exception', 'reason' => 'mapping set to strict']]],
        ]]);
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('1 of 2 bulk operation(s) failed on magento2_thedevkitchen_cmssearch_2; first: index page_6: strict_dynamic_mapping_exception mapping set to strict');
        $this->manager()->bulk(self::ALIAS, [
            ['action' => 'index', 'id' => 'page_5', 'document' => ['title' => 'A']],
            ['action' => 'index', 'id' => 'page_6', 'document' => ['bad' => 1]],
        ], true);
    }

    public function testBulkErrorsNeverQuoteDocumentContent(): void
    {
        // real OpenSearch 2.12 reasons quote the field value ("Preview of field's value") and caused_by repeats it
        $this->client->method('bulk')->willReturn(['errors' => true, 'items' => [
            ['index' => ['_id' => 'page_3', 'status' => 400, 'error' => [
                'type' => 'mapper_parsing_exception',
                'reason' => "failed to parse field [updated_at] of type [date] in document with id 'page_3'. Preview of field's value: 'SECRET PAGE CONTENT'",
                'caused_by' => ['type' => 'illegal_argument_exception', 'reason' => 'failed to parse date field [SECRET PAGE CONTENT]'],
            ]]],
        ]]);
        try {
            $this->manager()->bulk(self::ALIAS, [['action' => 'index', 'id' => 'page_3', 'document' => ['updated_at' => 'SECRET PAGE CONTENT']]], true);
            $this->fail('IndexingException expected');
        } catch (IndexingException $exception) {
            $this->assertStringNotContainsString('SECRET', $exception->getMessage());
            $this->assertStringContainsString("first: index page_3: mapper_parsing_exception failed to parse field [updated_at] of type [date] in document with id 'page_3'.", $exception->getMessage());
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
            $this->assertInstanceOf(BadRequestHttpException::class, $exception->getPrevious());
        }
    }

    public function testAnErrorFlagWithoutAnyFailedItemIsStillAnError(): void
    {
        $this->client->method('bulk')->willReturn(['errors' => true, 'items' => []]);
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('bulk request on magento2_thedevkitchen_cmssearch_2 reported errors without a failed item');
        $this->manager()->bulk(self::ALIAS, [['action' => 'delete', 'id' => 'page_6']], true);
    }

    public function testTheAliasSwitchIsAtomicAndRemovesOnlyThisStoresOldVersions(): void
    {
        $this->existing([
            self::ALIAS . '_v3' => [self::ALIAS],
            self::ALIAS . '_v2' => [],
            self::ALIAS . '_v4' => [],
            'magento2_thedevkitchen_cmssearch_3_v1' => ['magento2_thedevkitchen_cmssearch_3'],
        ]);
        $calls = [];
        $this->indices->expects($this->once())->method('updateAliases')->with(self::req(['body' => ['actions' => [
            ['remove' => ['index' => self::ALIAS . '_v3', 'alias' => self::ALIAS]],
            ['add' => ['index' => self::ALIAS . '_v4', 'alias' => self::ALIAS]],
        ]]]))->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'swap';
            return ['acknowledged' => true];
        });
        $this->indices->method('delete')->willReturnCallback(function (array $params) use (&$calls): array {
            $this->assertSame(self::CLIENT, $params['client']);
            $calls[] = $params['index'];
            return [];
        });
        $this->assertSame([self::ALIAS . '_v2', self::ALIAS . '_v3'], $this->manager()->switchAlias($this->store, self::ALIAS . '_v4'));
        $this->assertSame(['swap', self::ALIAS . '_v2', self::ALIAS . '_v3'], $calls);
    }

    public function testAFailedSwitchDeletesNothing(): void
    {
        $this->existing([self::ALIAS . '_v3' => [self::ALIAS], self::ALIAS . '_v4' => []]);
        $this->indices->method('updateAliases')->willThrowException(new \RuntimeException('timeout'));
        $this->indices->expects($this->never())->method('delete');
        $this->expectException(IndexingException::class);
        $this->manager()->switchAlias($this->store, self::ALIAS . '_v4');
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

    public function testOrphansOfDeletedStoreViewsAreDropped(): void
    {
        $this->existing([
            self::ALIAS . '_v1' => [self::ALIAS],
            'magento2_thedevkitchen_cmssearch_9_v2' => ['magento2_thedevkitchen_cmssearch_9'],
            'magento2_product_9_v1' => [],
            'magento2_thedevkitchen_cmssearch_9' => [],
        ]);
        $this->indices->expects($this->once())->method('delete')->with(self::req(['index' => 'magento2_thedevkitchen_cmssearch_9_v2']));
        $this->assertSame(['magento2_thedevkitchen_cmssearch_9_v2'], $this->manager()->dropOrphans([1, 2, 3]));
    }

    public function testOrphanCleanupWithoutAnyExistingStoreViewIsRefused(): void
    {
        $this->indices->expects($this->never())->method('delete');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->dropOrphans([]);
    }

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

    public function testOurIndexIsDroppedByItsExactName(): void
    {
        $this->indices->expects($this->once())->method('delete')->with(self::req(['index' => self::ALIAS . '_v3']));
        $this->manager()->dropIndex(self::ALIAS . '_v3');
    }

    public function testRefreshTargetsTheGivenIndex(): void
    {
        $this->indices->expects($this->once())->method('refresh')->with(self::req(['index' => self::ALIAS . '_v4']));
        $this->manager()->refresh(self::ALIAS . '_v4');
    }

    public function testNoIndexAtAllStartsAtVersionOne(): void
    {
        $this->indices->method('existsAlias')->willReturn(false);
        $this->indices->method('getAlias')->willThrowException(new NotFoundHttpException('no such index'));
        $this->indices->expects($this->once())->method('create')
            ->with($this->callback(static fn (array $params): bool => $params['index'] === self::ALIAS . '_v1'));
        $this->manager()->ensureAlias($this->store);
    }
}
