<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Queue;

use PHPUnit\Framework\TestCase;

/**
 * TS-35: nothing but the consumer handler may run the reindex processor, so no admin/REST request can reindex
 * synchronously. Plan 4 adds its processor class to the allow-list.
 */
class AsyncOnlyContractTest extends TestCase
{
    public function testOnlyTheConsumerHandlerUsesTheProcessor(): void
    {
        $module = dirname(__DIR__, 3);
        $allowed = [
            'Model/Queue/NullReindexProcessor.php',
            'Model/Queue/ReindexHandler.php',
            'Model/Queue/ReindexProcessorInterface.php',
        ];
        $users = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($module) + 1);
            if (!str_ends_with($relative, '.php') || str_starts_with($relative, 'Test/')) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (str_contains($code, 'ReindexProcessorInterface') || str_contains($code, 'ReindexHandler')) {
                $users[] = $relative;
            }
        }
        sort($users);
        $this->assertSame($allowed, $users);
    }
}
