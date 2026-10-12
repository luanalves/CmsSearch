<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Model\Indexer\AffectedPages;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\PageIndexer;
use Thedevkitchen\CmsSearch\Model\Indexer\ShadowedPageReader;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;
use Thedevkitchen\CmsSearch\Model\Queue\PageReindexProcessor;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexProcessorInterface;

class PageReindexProcessorTest extends TestCase
{
    private AffectedPages&MockObject $affected;
    private StoreContextProvider&MockObject $stores;
    private PageIndexer&MockObject $indexer;
    private ShadowedPageReader&MockObject $shadows;
    private LoggerInterface&MockObject $logger;
    /** @var list<array{0: list<int>, 1: list<int>}> page ids and store ids of every reindex() call */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->affected = $this->createMock(AffectedPages::class);
        $this->stores = $this->createMock(StoreContextProvider::class);
        $this->indexer = $this->createMock(PageIndexer::class);
        $this->shadows = $this->createMock(ShadowedPageReader::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->calls = [];
    }

    private function store(int $id, int $websiteId = 2): StoreContext
    {
        return new StoreContext($id, $websiteId, 's' . $id, 'pt_BR', 'https://s.test/', 'https://s.test/media/', 'home');
    }

    private function processor(): PageReindexProcessor
    {
        return new PageReindexProcessor($this->affected, $this->stores, $this->indexer, $this->shadows, $this->logger);
    }

    /** Records every reindex() call; each call reports one upsert per page and one delete per store view. */
    private function recordReindex(): void
    {
        $this->indexer->method('reindex')->willReturnCallback(function (array $pageIds, array $stores, array $targets = []): array {
            $this->assertSame([], $targets, 'the consumer always writes incrementally');
            $this->calls[] = [$pageIds, array_map(static fn (StoreContext $s): int => $s->getStoreId(), $stores)];
            return ['upserted' => count($pageIds), 'deleted' => count($stores)];
        });
    }

    public function testItIsTheQueueProcessor(): void
    {
        $this->assertInstanceOf(ReindexProcessorInterface::class, $this->processor());
    }

    public function testAMessageWithoutStoreReindexesTheAffectedPagesInEveryStoreView(): void
    {
        $message = new ReindexMessage('content_type', 5, 'save', 1);
        $this->affected->method('resolve')->with($message)->willReturn([[3, 7]]);
        $all = [$this->store(1, 1), $this->store(2)];
        $this->stores->method('all')->willReturn($all);
        $this->stores->expects($this->never())->method('get');
        $this->indexer->expects($this->once())->method('reindex')->with([3, 7], $all, [])
            ->willReturn(['upserted' => 1, 'deleted' => 3]);
        $this->processor()->process($message);
    }

    public function testAStoreScopedMessageOnlyTouchesThatStoreView(): void
    {
        $message = new ReindexMessage('page', 7, 'save', 1, 3);
        $this->affected->method('resolve')->willReturn([[7]]);
        $this->shadows->method('siblingsOf')->willReturn([]);
        $store = $this->store(3);
        $this->stores->method('get')->with(3)->willReturn($store);
        $this->stores->expects($this->never())->method('all');
        $this->indexer->expects($this->once())->method('reindex')->with([7], [$store], [])
            ->willReturn(['upserted' => 1, 'deleted' => 0]);
        $this->processor()->process($message);
    }

    public function testATermMessageOnlyTouchesTheStoreViewsOfTheTermsWebsite(): void
    {
        $this->affected->method('resolve')->willReturn([[4]]);
        $this->stores->method('all')->willReturn([$this->store(1, 1), $this->store(2, 2), $this->store(3, 2), $this->store(5, 3)]);
        $this->recordReindex();
        $this->processor()->process(new ReindexMessage('classification_term', 9, 'save', 1, null, 2));
        $this->assertSame([[[4], [2, 3]]], $this->calls);
    }

    public function testAStoreViewOutsideTheMessageWebsiteIsNotTouched(): void
    {
        $this->affected->method('resolve')->willReturn([[4]]);
        $this->stores->method('get')->with(1)->willReturn($this->store(1, 1));
        $this->indexer->expects($this->never())->method('reindex');
        $this->processor()->process(new ReindexMessage('classification_term', 9, 'save', 1, 1, 2));
    }

    public function testEveryChunkOfAffectedPagesIsReindexedAndTheStoreViewsAreReadOnce(): void
    {
        $this->affected->method('resolve')->willReturn((static function (): \Generator {
            yield [1, 2];
            yield [5];
        })());
        $this->stores->expects($this->once())->method('all')->willReturn([$this->store(1), $this->store(2)]);
        $this->recordReindex();
        $this->processor()->process(new ReindexMessage('content_type', 5, 'save', 1));
        $this->assertSame([[[1, 2], [1, 2]], [[5], [1, 2]]], $this->calls);
    }

    public function testChunksAreStreamedNotCollectedFirst(): void
    {
        // a content type with many pages: the first chunk is written before the next one is even read
        $this->affected->method('resolve')->willReturn((static function (): \Generator {
            yield [1, 2];
            throw new \RuntimeException('database gone while reading the next chunk');
        })());
        $this->stores->method('all')->willReturn([$this->store(1)]);
        $this->recordReindex();
        try {
            $this->processor()->process(new ReindexMessage('content_type', 5, 'save', 1));
            $this->fail('the read failure must propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('database gone while reading the next chunk', $exception->getMessage());
        }
        $this->assertSame([[[1, 2], [1]]], $this->calls);
    }

    public function testThePageOfAllStoreViewsThatAChangedPageShadowsIsReindexedToo(): void
    {
        // page 11 (`home`, store view 2) was saved (e.g. disabled): page 2 (`home`, store 0) is served or hidden in
        // store view 2 by it. (A deleted page has no row left to name siblings: the producer covers that case.)
        $this->affected->method('resolve')->willReturn([[11]]);
        $this->shadows->expects($this->once())->method('siblingsOf')->with([11])->willReturn([2]);
        $this->stores->method('all')->willReturn([$this->store(1, 1), $this->store(2)]);
        $this->recordReindex();
        $this->processor()->process(new ReindexMessage('page', 11, 'save', 1));
        $this->assertSame([[[2, 11], [1, 2]]], $this->calls);
    }

    public function testShadowSiblingsAreNotDuplicated(): void
    {
        $this->affected->method('resolve')->willReturn([[7]]);
        $this->shadows->method('siblingsOf')->willReturn([7, 3, 3]);
        $this->stores->method('all')->willReturn([$this->store(1)]);
        $this->recordReindex();
        $this->processor()->process(new ReindexMessage('page', 7, 'save', 1));
        $this->assertSame([[[3, 7], [1]]], $this->calls);
    }

    /**
     * @return array<string, array{0: ReindexMessage}>
     */
    public static function typeAndTermMessages(): array
    {
        return [
            'content type' => [new ReindexMessage('content_type', 5, 'save', 1)],
            'classification term' => [new ReindexMessage('classification_term', 9, 'save', 1, null, 2)],
        ];
    }

    /** A type or a term never changes which page wins a URL key: no sibling lookup for their (possibly many) pages. */
    #[DataProvider('typeAndTermMessages')]
    public function testTypeAndTermMessagesDoNotLookForShadowSiblings(ReindexMessage $message): void
    {
        $this->affected->method('resolve')->willReturn([[3, 7]]);
        $this->stores->method('all')->willReturn([$this->store(1)]);
        $this->shadows->expects($this->never())->method('siblingsOf');
        $this->recordReindex();
        $this->processor()->process($message);
        $this->assertSame([[[3, 7], [1]]], $this->calls);
    }

    public function testADeletedStoreViewOrNoAffectedPageIsASuccessfulNoOp(): void
    {
        $this->affected->method('resolve')->willReturnOnConsecutiveCalls([[7]], []);
        $this->stores->method('get')->willReturn(null);
        $this->stores->method('all')->willReturn([$this->store(1)]);
        $this->indexer->expects($this->never())->method('reindex');
        $this->processor()->process(new ReindexMessage('page', 7, 'save', 1, 99));
        $this->processor()->process(new ReindexMessage('content_type', 5, 'delete', 1));
    }

    public function testWithoutAnyStoreViewTheAffectedPagesAreNotEvenRead(): void
    {
        $this->affected->method('resolve')->willReturn((static function (): \Generator {
            throw new \RuntimeException('must not be iterated');
            yield [1]; // @phpstan-ignore-line unreachable, makes this a generator
        })());
        $this->stores->method('get')->willReturn(null);
        $this->shadows->expects($this->never())->method('siblingsOf');
        $this->indexer->expects($this->never())->method('reindex');
        $this->processor()->process(new ReindexMessage('content_type', 5, 'save', 1, 99));
    }

    public function testAnUnprocessableMessageStaysPermanent(): void
    {
        $this->affected->method('resolve')->willThrowException(new InvalidReindexMessageException('no'));
        $this->expectException(InvalidReindexMessageException::class);
        $this->processor()->process(new ReindexMessage('page', 7, 'save', 1));
    }

    public function testAnIndexFailurePropagatesSoTheHandlerRetries(): void
    {
        $this->affected->method('resolve')->willReturn([[7]]);
        $this->stores->method('all')->willReturn([$this->store(1)]);
        $this->indexer->method('reindex')->willThrowException(new IndexingException('No alive nodes'));
        $this->logger->expects($this->never())->method($this->anything());
        $this->expectException(IndexingException::class);
        $this->processor()->process(new ReindexMessage('page', 7, 'save', 1));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function transientFailures(): array
    {
        return [
            'affected pages' => ['resolve'],
            'store views' => ['all'],
            'shadow siblings' => ['siblingsOf'],
        ];
    }

    /** A read failure is transient (retried), never turned into a permanent InvalidReindexMessageException. */
    #[DataProvider('transientFailures')]
    public function testReadFailuresPropagateUnchanged(string $failing): void
    {
        $failure = new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        $this->affected->method('resolve')->willReturnCallback(static function () use ($failing, $failure): array {
            if ($failing === 'resolve') {
                throw $failure;
            }
            return [[7]];
        });
        $this->stores->method('all')->willReturnCallback(function () use ($failing, $failure): array {
            if ($failing === 'all') {
                throw $failure;
            }
            return [$this->store(1)];
        });
        $this->shadows->method('siblingsOf')->willReturnCallback(static function () use ($failing, $failure): array {
            if ($failing === 'siblingsOf') {
                throw $failure;
            }
            return [];
        });
        $this->indexer->expects($this->never())->method('reindex');
        try {
            $this->processor()->process(new ReindexMessage('page', 7, 'save', 1));
            $this->fail('the failure must propagate');
        } catch (\Throwable $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testOneDebugLineWithIdsAndCountsOnlyIsLoggedPerMessage(): void
    {
        $this->affected->method('resolve')->willReturn([[1, 2], [5]]);
        $this->stores->method('all')->willReturn([$this->store(1), $this->store(2)]);
        $this->recordReindex();
        $this->logger->expects($this->never())->method('info');
        $this->logger->expects($this->once())->method('debug')->with(
            'reindexed content_type 5 (save): 3 page(s), 2 store view(s), 3 upserted, 4 deleted'
        );
        $this->processor()->process(new ReindexMessage('content_type', 5, 'save', 1));
    }

    public function testANoOpMessageAlsoLogsItsSingleDebugLine(): void
    {
        $this->affected->method('resolve')->willReturn([]);
        $this->stores->method('all')->willReturn([$this->store(1)]);
        $this->logger->expects($this->once())->method('debug')->with(
            'reindexed classification_term 9 (delete): 0 page(s), 1 store view(s), 0 upserted, 0 deleted'
        );
        $this->processor()->process(new ReindexMessage('classification_term', 9, 'delete', 1, null, 2));
    }

    /**
     * The processor runs inside the consumer: it must never save through repositories or models (that would loop
     * through the after-commit flush plugin and publish again) nor publish. Its only collaborators are readers, the
     * indexer and the logger.
     */
    public function testTheProcessorCanOnlyReadAndIndex(): void
    {
        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            (new \ReflectionMethod(PageReindexProcessor::class, '__construct'))->getParameters()
        );
        $this->assertSame(
            [AffectedPages::class, StoreContextProvider::class, PageIndexer::class, ShadowedPageReader::class, LoggerInterface::class],
            $types
        );
        $code = (string) file_get_contents((string) (new \ReflectionClass(PageReindexProcessor::class))->getFileName());
        foreach (['Repository', 'Publisher', 'MessageSender', '->save(', '->delete(', 'ObjectManager'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code);
        }
    }
}
