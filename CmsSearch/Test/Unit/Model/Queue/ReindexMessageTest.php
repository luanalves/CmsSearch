<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\MessageQueue\ConnectionLostException;
use Magento\Framework\MessageQueue\MessageLockException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface as Message;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;

class ReindexMessageTest extends TestCase
{
    public function testGettersAndTheWireShape(): void
    {
        $message = new ReindexMessage(Message::ENTITY_CLASSIFICATION_TERM, 9, Message::ACTION_SAVE, 1760000000, 3, 2, 1);
        $this->assertSame(
            ['classification_term', 9, 'save', 3, 2, 1760000000, 1],
            [$message->getEntityType(), $message->getEntityId(), $message->getAction(), $message->getStoreId(),
                $message->getWebsiteId(), $message->getTimestamp(), $message->getAttempt()]
        );
        $this->assertSame(
            ['entity_type' => 'classification_term', 'entity_id' => 9, 'action' => 'save', 'store_id' => 3,
                'website_id' => 2, 'timestamp' => 1760000000, 'attempt' => 1],
            $message->toArray()
        );
    }

    public function testWithAttemptReturnsAChangedCopy(): void
    {
        $first = new ReindexMessage(Message::ENTITY_PAGE, 7, Message::ACTION_SAVE, 1760000000);
        $second = $first->withAttempt(2);
        $this->assertNotSame($first, $second);
        $this->assertSame(0, $first->getAttempt());
        $this->assertSame(2, $second->getAttempt());
        $this->assertSame(array_merge($first->toArray(), ['attempt' => 2]), $second->toArray());
    }

    public function testTheKeyIgnoresOnlyTimeAndAttempt(): void
    {
        $base = new ReindexMessage(Message::ENTITY_PAGE, 7, Message::ACTION_SAVE, 1760000000);
        $this->assertSame(
            $base->getKey(),
            (new ReindexMessage(Message::ENTITY_PAGE, 7, Message::ACTION_SAVE, 1, null, null, 2))->getKey()
        );
        $keys = array_map(static fn (Message $message): string => $message->getKey(), [
            $base,
            new ReindexMessage(Message::ENTITY_PAGE, 8, Message::ACTION_SAVE, 1760000000),
            new ReindexMessage(Message::ENTITY_PAGE, 7, Message::ACTION_DELETE, 1760000000),
            new ReindexMessage(Message::ENTITY_PAGE, 7, Message::ACTION_SAVE, 1760000000, 1),
            new ReindexMessage(Message::ENTITY_PAGE, 7, Message::ACTION_SAVE, 1760000000, null, 1),
            new ReindexMessage(Message::ENTITY_CONTENT_TYPE, 7, Message::ACTION_SAVE, 1760000000),
        ]);
        $this->assertCount(6, array_unique($keys));
    }

    public function testEveryDeclaredCombinationIsAccepted(): void
    {
        $count = 0;
        foreach (Message::ALLOWED_ACTIONS as $type => $actions) {
            foreach ($actions as $action) {
                $this->assertSame($action, (new ReindexMessage($type, 1, $action, 0))->getAction());
                $count++;
            }
        }
        $this->assertSame(7, $count);
    }

    /** @return array<string, array{0: array<int, mixed>, 1: string}> */
    public static function invalid(): array
    {
        return [
            'unknown entity type' => [['blog', 1, 'save', 0], 'Unknown entity type "blog"'],
            'staging is only for pages (type)' => [['content_type', 1, 'staging', 0], 'not valid for entity type'],
            'staging is only for pages (term)' => [['classification_term', 1, 'staging', 0], 'not valid for entity type'],
            'empty action' => [['page', 1, '', 0], 'not valid for entity type'],
            'zero id' => [['page', 0, 'save', 0], 'entity id must be a positive integer'],
            'negative id' => [['page', -3, 'save', 0], 'entity id must be a positive integer'],
            'store 0' => [['page', 1, 'save', 0, 0], 'store id must be a positive integer'],
            'negative store' => [['page', 1, 'save', 0, -1], 'store id must be a positive integer'],
            'website 0' => [['page', 1, 'save', 0, null, 0], 'website id must be a positive integer'],
            'negative timestamp' => [['page', 1, 'save', -1], 'timestamp must not be negative'],
            'negative attempt' => [['page', 1, 'save', 0, null, null, -1], 'attempt must be between 0 and 100'],
            'attempt above the bound' => [['page', 1, 'save', 0, null, null, 101], 'attempt must be between 0 and 100'],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidRequestsAreRejected(array $arguments, string $message): void
    {
        $this->expectException(InvalidReindexMessageException::class);
        $this->expectExceptionMessage($message);
        new ReindexMessage(...$arguments);
    }

    public function testTheExceptionIsOneTheFrameworkConsumerRejectsToTheDlq(): void
    {
        // Consumer::process() acknowledges (drops) NotFoundException and MessageLockException, leaves
        // ConnectionLostException unacknowledged, and rejects every other \Exception with requeue=false (-> DLX).
        $this->assertTrue(is_a(InvalidReindexMessageException::class, \Exception::class, true));
        foreach ([NotFoundException::class, MessageLockException::class, ConnectionLostException::class] as $dropped) {
            $this->assertFalse(is_a(InvalidReindexMessageException::class, $dropped, true), $dropped);
        }
    }
}
