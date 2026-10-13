<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Plugin\Staging;

use Magento\Staging\Model\StagingApplier;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Queue\SentMessageLog;
use Thedevkitchen\CmsSearch\Plugin\Staging\OpenSentMessageLog;

class OpenSentMessageLogTest extends TestCase
{
    public function testTheLogIsOpenOnlyWhileTheApplierRuns(): void
    {
        $log = new SentMessageLog();
        $plugin = new OpenSentMessageLog($log);
        $inside = null;
        $plugin->aroundExecute($this->createMock(StagingApplier::class), function () use ($log, &$inside) {
            $log->record(new ReindexMessage('block', 7, 'save', 1760000000));
            $inside = $log->wasSent('block', 7);
            return null;
        });
        $this->assertTrue($inside);
        $this->assertFalse($log->wasSent('block', 7), 'closed (and emptied) after the run');
    }

    public function testAFailingRunStillClosesTheLog(): void
    {
        $log = new SentMessageLog();
        try {
            (new OpenSentMessageLog($log))->aroundExecute($this->createMock(StagingApplier::class), function () use ($log): void {
                $log->record(new ReindexMessage('block', 7, 'save', 1760000000));
                throw new \RuntimeException('apply failed');
            });
            $this->fail('the failure must propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('apply failed', $exception->getMessage());
        }
        $this->assertFalse($log->wasSent('block', 7));
    }

    public function testANestedRunDoesNotCloseTheOuterRunsLog(): void
    {
        $log = new SentMessageLog();
        $plugin = new OpenSentMessageLog($log);
        $applier = $this->createMock(StagingApplier::class);
        $afterInner = null;
        $afterOuter = null;
        $plugin->aroundExecute($applier, function () use ($plugin, $applier, $log, &$afterInner) {
            $log->record(new ReindexMessage('block', 7, 'save', 1760000000));
            $plugin->aroundExecute($applier, static fn () => null);
            $afterInner = $log->wasSent('block', 7);
            return null;
        });
        $this->assertTrue($afterInner, 'the inner run must leave the outer log open with its entries');
        $this->assertFalse($log->wasSent('block', 7), 'the outermost run closes it');
        // and the log is reusable afterwards
        $plugin->aroundExecute($applier, function () use ($log, &$afterOuter) {
            $log->record(new ReindexMessage('block', 8, 'save', 1760000000));
            $afterOuter = $log->wasSent('block', 8);
            return null;
        });
        $this->assertTrue($afterOuter);
    }

    public function testAFailingInnerRunKeepsTheOuterLogOpen(): void
    {
        $log = new SentMessageLog();
        $plugin = new OpenSentMessageLog($log);
        $applier = $this->createMock(StagingApplier::class);
        $afterInner = null;
        $plugin->aroundExecute($applier, function () use ($plugin, $applier, $log, &$afterInner) {
            $log->record(new ReindexMessage('block', 7, 'save', 1760000000));
            try {
                $plugin->aroundExecute($applier, static function (): void {
                    throw new \RuntimeException('inner');
                });
            } catch (\RuntimeException) {
            }
            $afterInner = $log->wasSent('block', 7);
            return null;
        });
        $this->assertTrue($afterInner);
        $this->assertFalse($log->wasSent('block', 7));
    }
}
