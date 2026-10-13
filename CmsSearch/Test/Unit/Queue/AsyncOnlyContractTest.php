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
     * @param list<string> $skip module-relative files not scanned (the classes the needles name)
     * @param bool $codeOnly ignore comments and docblocks (a class mentioned in prose is not a dependency)
     * @return list<string> module PHP files (tests excluded) that mention any needle, relative to the module root
     */
    private function usersOf(array $needles, array $skip = [], bool $codeOnly = false): array
    {
        $module = dirname(__DIR__, 3);
        $users = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($module) + 1);
            if (!str_ends_with($relative, '.php') || str_starts_with($relative, 'Test/') || in_array($relative, $skip, true)) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if ($codeOnly) {
                $code = implode('', array_map(
                    static fn ($token): string => is_array($token)
                        ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                        : $token,
                    token_get_all($code)
                ));
            }
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
                'Model/Queue/HostReindexProcessor.php',
                'Model/Queue/ReindexHandler.php',
                'Model/Queue/ReindexProcessorInterface.php',
            ],
            $this->usersOf(['ReindexProcessorInterface', 'HostReindexProcessor', 'PageReindexProcessor', 'ReindexHandler'])
        );
    }

    /**
     * Only the writer classes themselves are skipped (PageIndexer and CategoryIndexer use IndexManager, FullReindexer
     * all of them): any other class, inside Model/Indexer/ too, that starts depending on a writer must be added here on
     * purpose.
     */
    public function testOnlyTheConsumerAndTheCliUseTheIndexWriters(): void
    {
        $this->assertSame(
            ['Console/Command/ReindexCommand.php', 'Model/Indexer/HostReindexer.php', 'Model/Queue/HostReindexProcessor.php'],
            $this->usersOf(
                ['PageIndexer', 'CategoryIndexer', 'FullReindexer', 'IndexManager', 'HostReindexer'],
                [
                    'Model/Indexer/PageIndexer.php',
                    'Model/Indexer/CategoryIndexer.php',
                    'Model/Indexer/FullReindexer.php',
                    'Model/Indexer/IndexManager.php',
                ],
                true
            )
        );
    }
}
