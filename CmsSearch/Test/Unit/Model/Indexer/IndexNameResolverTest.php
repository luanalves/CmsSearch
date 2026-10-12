<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Elasticsearch\Model\Config as SearchConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexNameResolver;

class IndexNameResolverTest extends TestCase
{
    private function resolver(?string $prefix = 'magento2'): IndexNameResolver
    {
        $config = $this->createMock(SearchConfig::class);
        $config->method('getIndexPrefix')->willReturn($prefix);
        return new IndexNameResolver($config);
    }

    public function testNamesFollowThePrefixNamespaceStoreVersionConvention(): void
    {
        $resolver = $this->resolver();
        $this->assertSame('magento2_thedevkitchen_cmssearch_2', $resolver->getAlias(2));
        $this->assertSame('magento2_thedevkitchen_cmssearch_2_v7', $resolver->getVersionName(2, 7));
        $this->assertSame('magento2_thedevkitchen_cmssearch_*', $resolver->getPattern());
    }

    public function testOwnVersionNamesAreParsed(): void
    {
        $this->assertSame(['store_id' => 12, 'version' => 3], $this->resolver()->parse('magento2_thedevkitchen_cmssearch_12_v3'));
    }

    /** @return array<string, array{0: string}> */
    public static function foreignNames(): array
    {
        return [
            'native product index' => ['magento2_product_2_v59'],
            'another prefix' => ['other_thedevkitchen_cmssearch_2_v1'],
            'the alias itself' => ['magento2_thedevkitchen_cmssearch_2'],
            'version 0' => ['magento2_thedevkitchen_cmssearch_2_v0'],
            'store 0' => ['magento2_thedevkitchen_cmssearch_0_v1'],
            'suffix after the version' => ['magento2_thedevkitchen_cmssearch_2_v1_old'],
            'leading zero store' => ['magento2_thedevkitchen_cmssearch_02_v1'],
        ];
    }

    #[DataProvider('foreignNames')]
    public function testForeignIndicesAreNeverRecognised(string $index): void
    {
        $this->assertNull($this->resolver()->parse($index));
    }

    /** @return array<string, array{0: ?string}> */
    public static function invalidPrefixes(): array
    {
        return ['empty' => [''], 'spaces and symbols' => ['bad prefix!']];
    }

    #[DataProvider('invalidPrefixes')]
    public function testAnUnusablePrefixIsAConfigurationError(?string $prefix): void
    {
        $this->expectException(IndexingException::class);
        $this->resolver($prefix)->getAlias(1);
    }

    public function testThePrefixIsLowercasedLikeOpenSearchRequires(): void
    {
        $this->assertSame('shop_thedevkitchen_cmssearch_1', $this->resolver(' Shop ')->getAlias(1));
    }
}
