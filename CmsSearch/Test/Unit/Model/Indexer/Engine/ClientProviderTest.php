<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer\Engine;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Elasticsearch\Model\Config as SearchConfig;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\OpenSearch\Model\SearchClient;
use OpenSearch\Client;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider;

class ClientProviderTest extends TestCase
{
    private function provider(ConnectionManager $manager, mixed $timeout = '15'): ClientProvider
    {
        $config = $this->createMock(SearchConfig::class);
        $config->method('getElasticsearchConfigData')->willReturnMap([['server_timeout', null, $timeout]]);
        return new ClientProvider($manager, $config);
    }

    public function testTheLowLevelClientOfTheNativeOpenSearchConnectionIsUsed(): void
    {
        $client = $this->createMock(Client::class);
        $connection = $this->createMock(SearchClient::class);
        $connection->method('getOpenSearchClient')->willReturn($client);
        $manager = $this->createMock(ConnectionManager::class);
        $manager->expects($this->once())->method('getConnection')->willReturn($connection);
        $this->assertSame($client, $this->provider($manager)->get());
    }

    public function testAnotherSearchEngineIsAConfigurationError(): void
    {
        $manager = $this->createMock(ConnectionManager::class);
        $manager->method('getConnection')->willReturn($this->createMock(ClientInterface::class));
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('CMS search needs the OpenSearch engine');
        $this->provider($manager)->get();
    }

    public function testAnUnreachableConnectionIsAnIndexingError(): void
    {
        $manager = $this->createMock(ConnectionManager::class);
        $manager->method('getConnection')->willThrowException(new \RuntimeException('Search client is not set.'));
        $this->expectException(IndexingException::class);
        $this->expectExceptionMessage('The OpenSearch connection is not available: Search client is not set.');
        $this->provider($manager)->get();
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
     * @dataProvider timeouts
     * @param array{timeout: int, connect_timeout: int} $expected
     */
    public function testEveryRequestHasBoundedTimeoutsDerivedFromTheNativeSetting(mixed $configured, array $expected): void
    {
        $this->assertSame($expected, $this->provider($this->createMock(ConnectionManager::class), $configured)->getRequestOptions());
    }
}
