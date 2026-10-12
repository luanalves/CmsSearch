<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Model\Indexer\AffectedPages;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;

class AffectedPagesTest extends TestCase
{
    /** @var array<int, array{wheres: list<array{0: string, 1: mixed}>, orWheres: list<array{0: string, 1: mixed}>, limit: ?int, order: list<string>}>> */
    private array $selects = [];
    /** @var list<int> keyset cursors (page_id > ?) of every outer query, in order */
    private array $cursors = [];

    /**
     * An in-memory link table: the fake evaluates "page_id > ?" and LIMIT like the database would, so the keyset
     * progression is really exercised (a wrong cursor or an early stop changes the result).
     *
     * @param list<int> $linkedPageIds ascending page ids that match the filter
     */
    private function resourceWithLinks(array $linkedPageIds): ResourceConnection
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(function () {
            $select = $this->createMock(Select::class);
            $key = spl_object_id($select);
            $this->selects[$key] = ['wheres' => [], 'orWheres' => [], 'limit' => null, 'order' => []];
            $select->method('from')->willReturnSelf();
            $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select, $key) {
                $this->selects[$key]['wheres'][] = [(string) $condition, $value];
                return $select;
            });
            $select->method('orWhere')->willReturnCallback(function ($condition, $value = null) use ($select, $key) {
                $this->selects[$key]['orWheres'][] = [(string) $condition, $value];
                return $select;
            });
            $select->method('limit')->willReturnCallback(function ($count) use ($select, $key) {
                $this->selects[$key]['limit'] = $count;
                return $select;
            });
            $select->method('order')->willReturnCallback(function ($spec) use ($select, $key) {
                $this->selects[$key]['order'][] = (string) $spec;
                return $select;
            });
            return $select;
        });
        $connection->method('fetchCol')->willReturnCallback(function (Select $select) use ($linkedPageIds) {
            $state = $this->selects[spl_object_id($select)];
            $cursor = null;
            foreach ($state['wheres'] as [$condition, $value]) {
                if ($condition === 'page_id > ?') {
                    $cursor = $value;
                }
            }
            $this->assertNotNull($cursor, 'every page query is keyset-paginated on page_id');
            $this->assertSame(['page_id ASC'], $state['order']);
            $this->assertLessThan(20, count($this->cursors), 'a runaway pagination loop');
            $this->cursors[] = $cursor;
            $rows = array_values(array_filter($linkedPageIds, static fn (int $id): bool => $id > $cursor));
            return array_map('strval', array_slice($rows, 0, (int) $state['limit']));
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function resourceWithoutQueries(): ResourceConnection
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('select');
        $connection->expects($this->never())->method('fetchCol');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        return $resource;
    }

    private function resolver(ResourceConnection $resource, ?TermResource $terms = null, int $batch = 1000): AffectedPages
    {
        return new AffectedPages($resource, $terms ?? $this->createMock(TermResource::class), $batch);
    }

    /**
     * @return list<list<int>>
     */
    private function chunks(iterable $chunks): array
    {
        $all = [];
        foreach ($chunks as $chunk) {
            $all[] = $chunk;
        }
        return $all;
    }

    private function termState(int $id, string $path): array
    {
        return ['term_id' => $id, 'website_id' => 2, 'parent_id' => 1, 'code' => 'c', 'path' => $path, 'level' => 1, 'is_active' => false];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pageActions(): array
    {
        return ['save' => ['save'], 'delete' => ['delete'], 'staging' => ['staging']];
    }

    /**
     * @dataProvider pageActions
     */
    public function testAPageMessageAffectsOnlyThatPageWhateverTheActionWithoutAnyQuery(string $action): void
    {
        $chunks = $this->resolver($this->resourceWithoutQueries())->resolve(new ReindexMessage('page', 42, $action, 1));
        $this->assertSame([[42]], $this->chunks($chunks));
    }

    public function testAContentTypeMessageYieldsEveryLinkedPageInKeysetBatches(): void
    {
        $resolver = $this->resolver($this->resourceWithLinks([3, 7, 8, 20, 21]), null, 2);
        $chunks = $this->chunks($resolver->resolve(new ReindexMessage('content_type', 5, 'save', 1)));
        $this->assertSame([[3, 7], [8, 20], [21]], $chunks);
        $this->assertSame([0, 7, 20], $this->cursors);
        $outer = array_values($this->selects);
        $this->assertSame(['type_id = ?', 5], $outer[0]['wheres'][0]);
        $this->assertSame(2, $outer[0]['limit']);
    }

    public function testAnExactMultipleOfTheBatchSizeEndsWithOneEmptyProbeAndNoEmptyChunk(): void
    {
        $resolver = $this->resolver($this->resourceWithLinks([3, 7, 8, 20]), null, 2);
        $chunks = $this->chunks($resolver->resolve(new ReindexMessage('content_type', 5, 'save', 1)));
        $this->assertSame([[3, 7], [8, 20]], $chunks);
        $this->assertSame([0, 7, 20], $this->cursors);
    }

    public function testAShortFirstBatchNeedsNoSecondQuery(): void
    {
        $resolver = $this->resolver($this->resourceWithLinks([3]), null, 2);
        $this->assertSame([[3]], $this->chunks($resolver->resolve(new ReindexMessage('content_type', 5, 'save', 1))));
        $this->assertSame([0], $this->cursors);
    }

    public function testAContentTypeWithoutPagesOrAlreadyDeletedYieldsNothing(): void
    {
        $resolver = $this->resolver($this->resourceWithLinks([]));
        $this->assertSame([], $this->chunks($resolver->resolve(new ReindexMessage('content_type', 5, 'delete', 1))));
    }

    public function testATermMessageUsesTheCurrentPathOfTheTermAsAParameterizedPrefixSubquery(): void
    {
        $terms = $this->createMock(TermResource::class);
        $terms->expects($this->once())->method('getStoredState')->with(4)->willReturn($this->termState(4, '1/4'));
        $terms->expects($this->never())->method('fetchSubtree');
        $resolver = $this->resolver($this->resourceWithLinks([11, 12]), $terms);
        $chunks = $this->chunks($resolver->resolve(new ReindexMessage('classification_term', 4, 'save', 1, null, 2)));
        $this->assertSame([[11, 12]], $chunks);

        $subtree = null;
        foreach ($this->selects as $state) {
            if ($state['orWheres'] !== []) {
                $subtree = $state;
            }
        }
        $this->assertNotNull($subtree, 'a subquery selects the terms of the subtree');
        $this->assertSame([['path = ?', '1/4']], $subtree['wheres']);
        $this->assertSame([['path LIKE ?', '1/4/%']], $subtree['orWheres']);
    }

    public function testASiblingWithTheSameDigitPrefixIsNotPartOfTheSubtree(): void
    {
        $terms = $this->createMock(TermResource::class);
        $terms->method('getStoredState')->willReturn($this->termState(4, '1/4'));
        $this->chunks($this->resolver($this->resourceWithLinks([1]), $terms)->resolve(new ReindexMessage('classification_term', 4, 'save', 1)));
        $likes = [];
        foreach ($this->selects as $state) {
            foreach ($state['orWheres'] as $or) {
                $likes[] = $or[1];
            }
        }
        // "1/4/%" matches 1/4/9 but not 1/40 or 1/40/2; the exact match "1/4" does not match 1/40 either
        $this->assertSame(['1/4/%'], $likes);
    }

    public function testATermSubtreeIsAlsoKeysetBatched(): void
    {
        $terms = $this->createMock(TermResource::class);
        $terms->method('getStoredState')->willReturn($this->termState(4, '1/4'));
        $resolver = $this->resolver($this->resourceWithLinks([1, 2, 3]), $terms, 2);
        $this->assertSame([[1, 2], [3]], $this->chunks($resolver->resolve(new ReindexMessage('classification_term', 4, 'save', 1))));
        $this->assertSame([0, 2], $this->cursors);
    }

    public function testADeletedOrMissingTermAffectsNoPageAndRunsNoQuery(): void
    {
        $terms = $this->createMock(TermResource::class);
        $terms->method('getStoredState')->willReturn(null);
        $resolver = $this->resolver($this->resourceWithoutQueries(), $terms);
        foreach (['delete', 'save'] as $action) {
            $this->assertSame([], $this->chunks($resolver->resolve(new ReindexMessage('classification_term', 4, $action, 1))));
        }
    }

    public function testADeleteActionOfAStillExistingTermIsStillResolvedFromCurrentState(): void
    {
        // the action is only a hint (Staging/other flows): the current state decides
        $terms = $this->createMock(TermResource::class);
        $terms->method('getStoredState')->willReturn($this->termState(4, '1/4'));
        $resolver = $this->resolver($this->resourceWithLinks([5]), $terms);
        $this->assertSame([[5]], $this->chunks($resolver->resolve(new ReindexMessage('classification_term', 4, 'delete', 1))));
    }

    public function testAnEntityTypeThePageIndexerCannotHandleIsPermanentAndFailsEagerly(): void
    {
        $message = $this->createMock(ReindexMessageInterface::class);
        $message->method('getEntityType')->willReturn('block');
        $message->method('getEntityId')->willReturn(3);
        $this->expectException(InvalidReindexMessageException::class);
        $this->expectExceptionMessage('cannot process entity type "block"');
        // not iterated: the permanent error must not be deferred until the first chunk is read
        $this->resolver($this->resourceWithoutQueries())->resolve($message);
    }

    public function testTheBatchSizeIsAtLeastOne(): void
    {
        $resolver = $this->resolver($this->resourceWithLinks([1, 2]), null, 0);
        $this->assertSame([[1], [2]], $this->chunks($resolver->resolve(new ReindexMessage('content_type', 5, 'save', 1))));
    }
}
