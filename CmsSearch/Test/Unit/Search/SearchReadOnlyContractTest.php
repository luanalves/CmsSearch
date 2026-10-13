<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexNameResolver;
use Thedevkitchen\CmsSearch\Model\Search\CmsSearchQuery;

/**
 * The storefront search side only reads the CMS index: no write API of the OpenSearch client, no index writer of
 * the indexer, no reindex publisher, and none of the write side's client handling (client reset/retry, the long
 * catalog request timeouts). Paths are checked as they appear (later tasks add files here).
 */
class SearchReadOnlyContractTest extends TestCase
{
    private const READ_SIDE = [
        'Api/CmsSearchQueryInterface.php',
        'Model/Search',
        'Block/Result',
        'ViewModel/SearchTabs.php',
        'ViewModel/ResultCards.php',
        'ViewModel/ClassificationFilter.php',
        'Observer/AddCmsTabHandle.php',
    ];

    private const FORBIDDEN = [
        '->bulk(', '->index(', '->delete(', '->deleteByQuery(', '->updateByQuery(', '->update(', '->indices(',
        '->reindex(', '->reset(', 'getRequestOptions',
        'IndexManager', 'PageIndexer', 'FullReindexer', 'ReindexPublisherInterface', 'MessageSender',
    ];

    /** The only classes of the write-side namespaces the query may depend on. */
    private const ALLOWED_INDEXER_DEPENDENCIES = [ClientProvider::class, IndexNameResolver::class];

    /** @return array<string, string> module-relative path => code */
    private function files(): array
    {
        $module = dirname(__DIR__, 3);
        $files = [];
        foreach (self::READ_SIDE as $path) {
            $full = $module . '/' . $path;
            if (is_file($full)) {
                $files[$path] = (string) file_get_contents($full);
            } elseif (is_dir($full)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (str_ends_with($file->getPathname(), '.php')) {
                        $files[substr($file->getPathname(), strlen($module) + 1)] = (string) file_get_contents($file->getPathname());
                    }
                }
            }
        }
        ksort($files);
        return $files;
    }

    public function testTheSearchSideNeverWritesToTheIndexOrTheQueue(): void
    {
        $files = $this->files();
        $this->assertArrayHasKey('Model/Search/CmsSearchQuery.php', $files);
        $this->assertArrayHasKey('Api/CmsSearchQueryInterface.php', $files);
        foreach ($files as $path => $code) {
            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString($needle, $code, "$path must not use $needle");
            }
        }
    }

    public function testTheQueryMakesExactlyOneSearchCall(): void
    {
        $code = $this->files()['Model/Search/CmsSearchQuery.php'] ?? '';
        $this->assertSame(1, substr_count($code, '->search('));
    }

    public function testTheQueryDependsOnNoWriteSideService(): void
    {
        $constructor = (new \ReflectionClass(CmsSearchQuery::class))->getConstructor();
        $this->assertNotNull($constructor);
        $checked = 0;
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type, '$' . $parameter->getName() . ' must have one class type');
            $class = $type->getName();
            if (str_starts_with($class, 'Thedevkitchen\\CmsSearch\\Model\\Indexer\\')) {
                $this->assertContains($class, self::ALLOWED_INDEXER_DEPENDENCIES, "the query must not depend on $class");
            }
            $this->assertStringStartsNotWith('Thedevkitchen\\CmsSearch\\Model\\Queue\\', $class);
            $this->assertStringStartsNotWith('Magento\\Framework\\MessageQueue\\', $class);
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }
}
