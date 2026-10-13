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
                $this->assertSame($action, (new ReindexMessage($type, 1, $action, 1))->getAction());
                $count++;
            }
        }
        $this->assertSame(20, $count);
    }

    /** @return array<string, array{0: array<int, mixed>, 1: string}> */
    public static function invalid(): array
    {
        return [
            'unknown entity type' => [['blog', 1, 'save', 1], 'Unknown entity type "blog"'],
            'staging is only for pages (type)' => [['content_type', 1, 'staging', 1], 'not valid for entity type'],
            'staging is only for pages (term)' => [['classification_term', 1, 'staging', 1], 'not valid for entity type'],
            'empty action' => [['page', 1, '', 1], 'not valid for entity type'],
            'zero id' => [['page', 0, 'save', 1], 'entity id must be a positive integer'],
            'negative id' => [['page', -3, 'save', 1], 'entity id must be a positive integer'],
            'store 0' => [['page', 1, 'save', 1, 0], 'store id must be a positive integer'],
            'negative store' => [['page', 1, 'save', 1, -1], 'store id must be a positive integer'],
            'website 0' => [['page', 1, 'save', 1, null, 0], 'website id must be a positive integer'],
            'zero timestamp' => [['page', 1, 'save', 0], 'timestamp must be between 1 and 4102444800'],
            'negative timestamp' => [['page', 1, 'save', -1], 'timestamp must be between 1 and 4102444800'],
            'timestamp above the ceiling' => [['page', 1, 'save', 4102444801], 'timestamp must be between'],
            'timestamp PHP_INT_MAX' => [['page', 1, 'save', PHP_INT_MAX], 'timestamp must be between'],
            'id PHP_INT_MAX' => [['content_type', PHP_INT_MAX, 'save', 1], 'at most 4294967295'],
            'id above the general cap' => [['classification_term', 4294967296, 'save', 1], 'at most 4294967295'],
            'page id above the page cap' => [['page', 32768, 'save', 1], 'at most 32767'],
            'store above the cap' => [['page', 1, 'save', 1, 65536], 'store id must be a positive integer of at most 65535'],
            'store PHP_INT_MAX' => [['page', 1, 'save', 1, PHP_INT_MAX], 'store id must be'],
            'website above the cap' => [['page', 1, 'save', 1, null, 65536], 'website id must be a positive integer of at most 65535'],
            'website PHP_INT_MAX' => [['page', 1, 'save', 1, null, PHP_INT_MAX], 'website id must be'],
            'negative attempt' => [['page', 1, 'save', 1, null, null, -1], 'attempt must be between 0 and 100'],
            'attempt above the bound' => [['page', 1, 'save', 1, null, null, 101], 'attempt must be between 0 and 100'],
            'block id above the block cap' => [['block', 32768, 'save', 1], 'at most 32767'],
            'staging is not valid for dynamic blocks' => [['dynamic_block', 1, 'staging', 1], 'not valid for entity type'],
            'staging is not valid for widget instances' => [['widget_instance', 1, 'staging', 1], 'not valid for entity type'],
            'product id above the general cap' => [['product', 4294967296, 'save', 1], 'at most 4294967295'],
            'category id zero' => [['category', 0, 'delete', 1], 'entity id must be a positive integer'],
        ];
    }

    public function testTheUpperBoundsAreAccepted(): void
    {
        $this->assertSame(32767, (new ReindexMessage('page', 32767, 'save', 4102444800, 65535, 65535, 100))->getEntityId());
        $this->assertSame(4294967295, (new ReindexMessage('content_type', 4294967295, 'save', 1))->getEntityId());
        $this->assertSame(4294967295, (new ReindexMessage('classification_term', 4294967295, 'save', 1))->getEntityId());
        $this->assertSame(1, (new ReindexMessage('page', 1, 'save', 1))->getTimestamp());
    }

    public function testCallerStringsAreNeverEchoedUnbounded(): void
    {
        $evil = "x\nINJECTED" . str_repeat('A', 5000);
        foreach ([[$evil, 'save'], ['page', $evil]] as [$type, $action]) {
            try {
                new ReindexMessage($type, 1, $action, 1);
                $this->fail('Expected an exception');
            } catch (InvalidReindexMessageException $e) {
                $this->assertLessThan(200, strlen($e->getMessage()));
                $this->assertStringNotContainsString("\n", $e->getMessage());
                $this->assertStringNotContainsString(str_repeat('A', 41), $e->getMessage());
            }
        }
    }

    #[DataProvider('invalid')]
    public function testInvalidRequestsAreRejected(array $arguments, string $message): void
    {
        $this->expectException(InvalidReindexMessageException::class);
        $this->expectExceptionMessage($message);
        new ReindexMessage(...$arguments);
    }

    public function testThePlan6EntityTypesAndTheirActions(): void
    {
        $this->assertSame([
            'page' => ['save', 'delete', 'staging'],
            'content_type' => ['save', 'delete'],
            'classification_term' => ['save', 'delete'],
            'block' => ['save', 'delete', 'staging'],
            'dynamic_block' => ['save', 'delete'],
            'widget_instance' => ['save', 'delete'],
            'product' => ['save', 'delete', 'staging'],
            'category' => ['save', 'delete', 'staging'],
        ], Message::ALLOWED_ACTIONS);
        $this->assertSame(
            ['block', 'dynamic_block', 'widget_instance', 'product', 'category'],
            [Message::ENTITY_BLOCK, Message::ENTITY_DYNAMIC_BLOCK, Message::ENTITY_WIDGET_INSTANCE, Message::ENTITY_PRODUCT, Message::ENTITY_CATEGORY]
        );
    }

    public function testTheUpperBoundsOfThePlan6TypesAreAccepted(): void
    {
        $this->assertSame(32767, ReindexMessage::MAX_BLOCK_ID);
        $this->assertSame(32767, (new ReindexMessage('block', 32767, 'staging', 1))->getEntityId());
        foreach (['dynamic_block', 'widget_instance', 'product', 'category'] as $type) {
            $this->assertSame(4294967295, (new ReindexMessage($type, 4294967295, 'delete', 1, 65535))->getEntityId(), $type);
        }
    }

    public function testTheKeyDiffersPerEntityType(): void
    {
        $keys = [];
        foreach (['block', 'dynamic_block', 'widget_instance', 'product', 'category'] as $type) {
            $keys[] = (new ReindexMessage($type, 5, 'save', 1))->getKey();
        }
        $this->assertCount(5, array_unique($keys));
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
