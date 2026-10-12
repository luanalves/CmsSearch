<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Queue;

use PHPUnit\Framework\TestCase;

/**
 * TS-35: nothing but the consumer (and the operator CLI) may run the reindex processor or write to the CMS search
 * index, so no admin/REST request can reindex synchronously.
 */
class AsyncOnlyContractTest extends TestCase
{
    /**
     * @param list<string> $needles
     * @return list<string> module PHP files (tests excluded) that mention any needle, relative to the module root
     */
    private function usersOf(array $needles, string $skipPrefix = ''): array
    {
        $module = dirname(__DIR__, 3);
        $users = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($module) + 1);
            if (!str_ends_with($relative, '.php') || str_starts_with($relative, 'Test/')
                || ($skipPrefix !== '' && str_starts_with($relative, $skipPrefix))) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            foreach ($needles as $needle) {
                if (str_contains($code, $needle)) {
                    $users[] = $relative;
                    break;
                }
            }
        }
        sort($users);
        return $users;
    }

    public function testOnlyTheConsumerHandlerUsesTheProcessor(): void
    {
        $this->assertSame(
            [
                'Model/Queue/PageReindexProcessor.php',
                'Model/Queue/ReindexHandler.php',
                'Model/Queue/ReindexProcessorInterface.php',
            ],
            $this->usersOf(['ReindexProcessorInterface', 'PageReindexProcessor', 'ReindexHandler'])
        );
    }

    public function testOnlyTheConsumerAndTheCliUseTheIndexWriters(): void
    {
        $this->assertSame(
            ['Model/Queue/PageReindexProcessor.php'],
            $this->usersOf(['PageIndexer', 'FullReindexer', 'IndexManager'], 'Model/Indexer/')
        );
    }
}
