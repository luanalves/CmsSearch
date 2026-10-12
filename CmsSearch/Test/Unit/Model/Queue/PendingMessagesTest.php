<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Queue\PendingMessages;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;

class PendingMessagesTest extends TestCase
{
    /** @param ReindexMessageInterface[] $messages @return list<array{0: string, 1: int, 2: string, 3: int}> */
    private static function summary(array $messages): array
    {
        return array_map(
            static fn (ReindexMessageInterface $m): array => [$m->getEntityType(), $m->getEntityId(), $m->getAction(), $m->getTimestamp()],
            $messages
        );
    }

    public function testTheFirstRequestPerKeyWinsAndTheOrderIsKept(): void
    {
        $pending = new PendingMessages();
        $pending->add('c1', new ReindexMessage('page', 7, 'save', 1));
        $pending->add('c1', new ReindexMessage('page', 8, 'save', 2));
        $pending->add('c1', new ReindexMessage('page', 7, 'save', 3));
        $pending->add('c1', new ReindexMessage('page', 7, 'delete', 4));
        $this->assertSame(
            [['page', 7, 'save', 1], ['page', 8, 'save', 2], ['page', 7, 'delete', 4]],
            self::summary($pending->take('c1'))
        );
    }

    public function testTakeEmptiesTheList(): void
    {
        $pending = new PendingMessages();
        $pending->add('c1', new ReindexMessage('page', 7, 'save', 1));
        $pending->take('c1');
        $this->assertSame([], $pending->take('c1'));
    }

    public function testConnectionsAreSeparate(): void
    {
        $pending = new PendingMessages();
        $pending->add('c1', new ReindexMessage('page', 7, 'save', 1));
        $pending->add('c2', new ReindexMessage('page', 8, 'save', 2));
        $this->assertSame([['page', 8, 'save', 2]], self::summary($pending->take('c2')));
        $this->assertSame([['page', 7, 'save', 1]], self::summary($pending->take('c1')));
    }

    public function testDiscardDropsEverythingOfTheConnection(): void
    {
        $pending = new PendingMessages();
        $pending->add('c1', new ReindexMessage('page', 7, 'save', 1));
        $pending->add('c2', new ReindexMessage('page', 8, 'save', 2));
        $pending->discard('c1');
        $this->assertSame([], $pending->take('c1'));
        $this->assertCount(1, $pending->take('c2'));
    }
}
