<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer\Engine;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Elasticsearch\Model\Config as SearchConfig;
use Magento\OpenSearch\Model\SearchClient;
use OpenSearch\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider;

class ClientProviderTest extends TestCase
{
    private function provider(ClientResolver $resolver, mixed $timeout = '15'): ClientProvider
    {
        $config = $this->createMock(SearchConfig::class);
        $config->method('getElasticsearchConfigData')->willReturnMap([['server_timeout', null, $timeout]]);
        return new ClientProvider($resolver, $config);
    }

    private function resolver(string $engine = 'opensearch'): ClientResolver&MockObject
    {
        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('getCurrentEngine')->willReturn($engine);
        return $resolver;
    }

    private function connection(Client $client): SearchClient
    {
        $connection = $this->createMock(SearchClient::class);
        $connection->method('getOpenSearchClient')->willReturn($client);
        return $connection;
    }

    public function testAnOwnClientIsBuiltByTheNativeResolverFromTheNativeConfigurationAndReused(): void
    {
        $client = $this->createMock(Client::class);
        $resolver = $this->resolver();
        $resolver->expects($this->once())->method('create')->willReturn($this->connection($client));
        $provider = $this->provider($resolver);
        $this->assertSame($client, $provider->get());
        $this->assertSame($client, $provider->get());
    }

    public function testResetDiscardsTheClientSoTheNextCallGetsAFreshConnectionPool(): void
    {
        $first = $this->createMock(Client::class);
        $second = $this->createMock(Client::class);
        $resolver = $this->resolver();
        $resolver->expects($this->exactly(2))->method('create')
            ->willReturnOnConsecutiveCalls($this->connection($first), $this->connection($second));
        $provider = $this->provider($resolver);
        $this->assertSame($first, $provider->get());
        $provider->reset();
        $this->assertSame($second, $provider->get());
    }

    public function testAnotherSearchEngineIsAConfigurationError(): void
    {
        $resolver = $this->resolver('elasticsearch7');
        $resolver->expects($this->never())->method('create');
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('CMS search needs the OpenSearch engine (catalog/search/engine = opensearch); the configured engine is elasticsearch7.');
        $this->provider($resolver)->get();
    }

    public function testAClientOfAnotherKindIsAConfigurationError(): void
    {
        $resolver = $this->resolver();
        $resolver->method('create')->willReturn($this->createMock(ClientInterface::class));
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('CMS search needs the OpenSearch engine');
        $this->provider($resolver)->get();
    }

    public function testAnUnusableConnectionIsAnIndexingError(): void
    {
        $resolver = $this->resolver();
        $resolver->method('create')->willThrowException(new \RuntimeException('The search failed because of a search engine misconfiguration.'));
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('The OpenSearch connection is not available: The search failed because of a search engine misconfiguration.');
        $this->provider($resolver)->get();
    }

    /**
     * @return array<string, array{mixed, array{timeout: int, connect_timeout: int}}>
     */
    public static function timeouts(): array
    {
        return [
            'native default 15 s' => ['15', ['timeout' => 60, 'connect_timeout' => 15]],
            'not configured' => [null, ['timeout' => 60, 'connect_timeout' => 15]],
            'zero' => ['0', ['timeout' => 60, 'connect_timeout' => 15]],
            'garbage' => ['abc', ['timeout' => 60, 'connect_timeout' => 15]],
            'slow cluster 120 s' => ['120', ['timeout' => 120, 'connect_timeout' => 60]],
            'absurd value is capped' => ['100000', ['timeout' => 300, 'connect_timeout' => 60]],
        ];
    }

    /**
     * @param array{timeout: int, connect_timeout: int} $expected
     */
    #[DataProvider('timeouts')]
    public function testEveryRequestHasBoundedTimeoutsDerivedFromTheNativeSetting(mixed $configured, array $expected): void
    {
        $this->assertSame($expected, $this->provider($this->resolver(), $configured)->getRequestOptions());
    }
}
