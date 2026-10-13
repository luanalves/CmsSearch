<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Curl\OperationTimeoutException;
use OpenSearch\Common\Exceptions\Missing404Exception;
use OpenSearch\Common\Exceptions\NoNodesAvailableException;
use OpenSearch\Exception\BadRequestHttpException;
use OpenSearch\Exception\NotFoundHttpException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\CmsSearchQueryInterface;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Exception\SearchUnavailableException;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexNameResolver;
use Thedevkitchen\CmsSearch\Model\Search\CmsSearchQuery;
use Thedevkitchen\CmsSearch\Model\Search\Data\CmsResult;
use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\QueryBuilder;
use Thedevkitchen\CmsSearch\Model\Search\ResponseMapper;

class CmsSearchQueryTest extends TestCase
{
    private const ALIAS = 'magento2_thedevkitchen_cmssearch_2';

    private ClientProvider&MockObject $clients;
    private Client&MockObject $client;
    private IndexNameResolver&MockObject $names;
    private int $currentStoreId = 2;
    private int $timeoutMs = 1500;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->clients = $this->createMock(ClientProvider::class);
        $this->clients->method('get')->willReturn($this->client);
        $this->names = $this->createMock(IndexNameResolver::class);
        $this->names->method('getAlias')->willReturnCallback(
            static fn (int $storeId): string => 'magento2_thedevkitchen_cmssearch_' . $storeId
        );
    }

    private function request(int $storeId = 2): SearchRequest
    {
        return new SearchRequest('milho', $storeId, 2, Tab::KIND_CONTENT, 'news', null, 1, 12);
    }

    private function query(?ResponseMapper $mapper = null): CmsSearchQuery
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn((string) $this->currentStoreId);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->with(null)->willReturn($store);
        $config = $this->createMock(Config::class);
        $config->method('getSearchTimeoutMs')->willReturn($this->timeoutMs);
        return new CmsSearchQuery(
            $this->clients,
            $this->names,
            $stores,
            new QueryBuilder(),
            $mapper ?? new ResponseMapper(),
            $config
        );
    }

    private function unavailable(callable $search): SearchUnavailableException
    {
        try {
            $search();
        } catch (SearchUnavailableException $exception) {
            return $exception;
        }
        $this->fail('SearchUnavailableException expected');
    }

    public function testOneSearchHitsTheAliasWithTheBuiltBodyAndAShortTimeout(): void
    {
        $this->assertInstanceOf(CmsSearchQueryInterface::class, $this->query());
        $this->client->expects($this->once())->method('search')->with($this->callback(function (array $params): bool {
            $this->assertSame(['index', 'body', 'client'], array_keys($params));
            $this->assertSame(self::ALIAS, $params['index']);
            $this->assertEquals((new QueryBuilder())->build($this->request()), $params['body']);
            $this->assertSame(['timeout' => 1.5, 'connect_timeout' => 1.5, 'never_retry' => true], $params['client']);
            return true;
        }))->willReturn(['timed_out' => false, 'hits' => ['total' => ['value' => 0], 'hits' => []]]);
        $this->query()->search($this->request());
    }

    /** @return array<string, array{0: int, 1: float}> */
    public static function timeouts(): array
    {
        return ['minimum' => [1, 0.001], 'whole second' => [1000, 1.0], 'maximum' => [10000, 10.0]];
    }

    #[DataProvider('timeouts')]
    public function testTheConfiguredTimeoutBoundsConnectionAndRequest(int $milliseconds, float $seconds): void
    {
        $this->timeoutMs = $milliseconds;
        $this->client->expects($this->once())->method('search')->with($this->callback(function (array $params) use ($seconds): bool {
            $this->assertSame(['timeout' => $seconds, 'connect_timeout' => $seconds, 'never_retry' => true], $params['client']);
            return true;
        }))->willReturn(['hits' => ['total' => ['value' => 0], 'hits' => []]]);
        $this->query()->search($this->request());
    }

    public function testTheAliasIsTheOneOfTheCurrentStoreView(): void
    {
        $this->currentStoreId = 5;
        $this->client->expects($this->once())->method('search')->with($this->callback(function (array $params): bool {
            $this->assertSame('magento2_thedevkitchen_cmssearch_5', $params['index']);
            return true;
        }))->willReturn(['hits' => ['total' => ['value' => 0], 'hits' => []]]);
        $this->query()->search($this->request(5));
    }

    public function testARequestForAnotherStoreViewIsNeverSent(): void
    {
        $this->currentStoreId = 3;
        $this->client->expects($this->never())->method('search');
        $exception = $this->unavailable(fn () => $this->query()->search($this->request(2)));
        $this->assertFalse($exception->isMissingIndex());
        $this->assertStringNotContainsString('milho', $exception->getMessage());
    }

    public function testTheResponseIsMappedForTheRequest(): void
    {
        $response = ['hits' => ['total' => ['value' => 1], 'hits' => []], 'aggregations' => []];
        $this->client->method('search')->willReturn($response);
        $expected = new CmsResult(['news' => 1], 0, false, 1, [], []);
        $mapper = $this->createMock(ResponseMapper::class);
        $mapper->expects($this->once())->method('map')->with($response, $this->request())->willReturn($expected);
        $this->assertSame($expected, $this->query($mapper)->search($this->request()));
    }

    /** @return array<string, array{0: \Throwable}> */
    public static function missingIndices(): array
    {
        $body = '{"error":{"type":"index_not_found_exception","reason":"no such index [milho]"}}';
        // Missing404Exception is what opensearch-php 2.4.3 throws (spike); it extends NotFoundHttpException.
        return ['legacy class' => [new Missing404Exception($body)], 'current class' => [new NotFoundHttpException($body)]];
    }

    #[DataProvider('missingIndices')]
    public function testAMissingAliasMeansTheStoreViewHasNoIndexYet(\Throwable $notFound): void
    {
        $this->client->method('search')->willThrowException($notFound);
        $exception = $this->unavailable(fn () => $this->query()->search($this->request()));
        $this->assertTrue($exception->isMissingIndex());
        $this->assertStringContainsString(self::ALIAS, $exception->getMessage());
        $this->assertStringNotContainsString('milho', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    /** @return array<string, array{0: \Throwable}> */
    public static function engineFailures(): array
    {
        return [
            'timeout' => [new OperationTimeoutException('Operation timed out after 1500 milliseconds searching milho')],
            'dead node' => [new NoNodesAvailableException('No alive nodes found in your cluster (milho)')],
            'bad request echoing the query' => [new BadRequestHttpException('{"reason":"failed to create query: milho"}')],
            'anything else' => [new \RuntimeException('timed out while searching milho')],
        ];
    }

    #[DataProvider('engineFailures')]
    public function testAnEngineFailureIsReportedWithoutTheSearchedText(\Throwable $failure): void
    {
        $this->client->method('search')->willThrowException($failure);
        $exception = $this->unavailable(fn () => $this->query()->search($this->request()));
        $this->assertFalse($exception->isMissingIndex());
        $this->assertStringContainsString(self::ALIAS, $exception->getMessage());
        $this->assertStringContainsString((new \ReflectionClass($failure))->getShortName(), $exception->getMessage());
        $this->assertStringNotContainsString('milho', $exception->getMessage());
        $this->assertNull($exception->getPrevious(), 'the engine message may quote the searched text');
    }

    public function testAMisconfiguredEngineIsUnavailable(): void
    {
        $this->clients = $this->createMock(ClientProvider::class);
        $this->clients->method('get')->willThrowException(new IndexingException('the search engine is not OpenSearch'));
        $this->client->expects($this->never())->method('search');
        $exception = $this->unavailable(fn () => $this->query()->search($this->request()));
        $this->assertFalse($exception->isMissingIndex());
        $this->assertStringContainsString('IndexingException', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    public function testAnInvalidIndexPrefixIsUnavailable(): void
    {
        $this->names = $this->createMock(IndexNameResolver::class);
        $this->names->method('getAlias')->willThrowException(new IndexingException('prefix is invalid'));
        $this->client->expects($this->never())->method('search');
        $this->assertFalse($this->unavailable(fn () => $this->query()->search($this->request()))->isMissingIndex());
    }

    public function testPartialResultsAfterAnEngineTimeoutAreRejected(): void
    {
        $this->client->method('search')->willReturn(['timed_out' => true, 'hits' => ['total' => ['value' => 3], 'hits' => []]]);
        $this->assertFalse($this->unavailable(fn () => $this->query()->search($this->request()))->isMissingIndex());
    }

    public function testPartialResultsOfFailedShardsAreRejected(): void
    {
        $this->client->method('search')->willReturn([
            'timed_out' => false,
            '_shards' => ['total' => 2, 'successful' => 1, 'failed' => 1],
            'hits' => ['total' => ['value' => 3], 'hits' => []],
        ]);
        $this->assertFalse($this->unavailable(fn () => $this->query()->search($this->request()))->isMissingIndex());
    }

    public function testACompleteAnswerOfEveryShardIsAccepted(): void
    {
        $this->client->method('search')->willReturn([
            'timed_out' => false,
            '_shards' => ['total' => 1, 'successful' => 1, 'skipped' => 0, 'failed' => 0],
            'hits' => ['total' => ['value' => 0], 'hits' => []],
        ]);
        $this->assertSame(0, $this->query()->search($this->request())->getTotal());
    }

    public function testANonArrayAnswerIsRejected(): void
    {
        $this->client->method('search')->willReturn('oops');
        $this->assertFalse($this->unavailable(fn () => $this->query()->search($this->request()))->isMissingIndex());
    }
}
