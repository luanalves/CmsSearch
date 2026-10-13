<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Queue;

use PHPUnit\Framework\TestCase;

/**
 * D10 / TS-35: code that runs in a web request or inside a save transaction (admin, REST, storefront, observers,
 * plugins, Staging hooks, the publisher) never touches the reference graph, the CMS index or the product block text:
 * it only publishes messages.
 */
class ReferenceWritersContractTest extends TestCase
{
    private const REQUEST_SIDE = [
        'Api', 'Block', 'Controller', 'Observer', 'Plugin', 'Ui', 'ViewModel', 'Model/Staging',
        'Model/Queue/ReindexPublisher.php', 'Model/Queue/MessageSender.php', 'Model/Queue/PendingMessages.php',
    ];
    private const WRITERS = [
        'HostReindexer', 'CategoryIndexer', 'ProductBlockTextWriter', 'ReferenceStore', 'BlockReferenceResolver',
        'BlockTextResolver', 'PageIndexer', 'IndexManager', 'FullReindexer', 'HostReindexProcessor',
    ];

    public function testNoRequestSideCodeUsesAWriter(): void
    {
        $module = dirname(__DIR__, 3);
        $offenders = [];
        $scanned = 0;
        foreach (self::REQUEST_SIDE as $path) {
            $full = $module . '/' . $path;
            $files = is_file($full) ? [$full] : [];
            if (is_dir($full)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    $files[] = (string) $file;
                }
            }
            foreach ($files as $file) {
                if (!str_ends_with($file, '.php')) {
                    continue;
                }
                $scanned++;
                $code = (string) file_get_contents($file);
                foreach (self::WRITERS as $writer) {
                    if (preg_match('/\b' . $writer . '\b/', $code) === 1) {
                        $offenders[] = substr($file, strlen($module) + 1) . ': ' . $writer;
                    }
                }
            }
        }
        $this->assertGreaterThan(20, $scanned);
        $this->assertSame([], $offenders);
    }
}
