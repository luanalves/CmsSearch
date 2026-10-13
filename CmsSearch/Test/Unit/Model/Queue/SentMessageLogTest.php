<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Queue\SentMessageLog;

class SentMessageLogTest extends TestCase
{
    private static function message(string $type, int $id, ?int $storeId = null, ?int $websiteId = null): ReindexMessage
    {
        return new ReindexMessage($type, $id, 'save', 1760000000, $storeId, $websiteId);
    }

    public function testNothingIsRememberedOutsideAStagingRun(): void
    {
        $log = new SentMessageLog();
        $log->record(self::message('block', 7));
        $this->assertFalse($log->wasSent('block', 7));
    }

    public function testAnUnscopedMessageSentDuringTheRunIsRememberedByTypeAndId(): void
    {
        $log = new SentMessageLog();
        $log->open();
        $log->record(self::message('block', 7));
        $this->assertTrue($log->wasSent('block', 7));
        $this->assertFalse($log->wasSent('block', 8));
        $this->assertFalse($log->wasSent('page', 7));
    }

    public function testStoreOrWebsiteScopedMessagesDoNotCount(): void
    {
        // a message for one store view does not reindex the others: it can never replace an unscoped one
        $log = new SentMessageLog();
        $log->open();
        $log->record(self::message('page', 5, 2));
        $log->record(self::message('page', 6, null, 1));
        $this->assertFalse($log->wasSent('page', 5));
        $this->assertFalse($log->wasSent('page', 6));
    }

    public function testClosingReopeningOrResettingForgetsEverything(): void
    {
        $log = new SentMessageLog();
        $this->assertInstanceOf(ResetAfterRequestInterface::class, $log);
        foreach (['close', 'open', '_resetState'] as $method) {
            $log->open();
            $log->record(self::message('block', 7));
            $log->{$method}();
            $this->assertFalse($log->wasSent('block', 7), $method);
        }
    }
}
