<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Exception\IndexingException;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\HostReindexer;
use Thedevkitchen\CmsSearch\Model\Indexer\StoreContextProvider;
use Thedevkitchen\CmsSearch\Model\Queue\HostReindexProcessor;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexProcessorInterface;
use Thedevkitchen\CmsSearch\Model\Reference\AffectedHosts;

class HostReindexProcessorTest extends TestCase
{
    private AffectedHosts&MockObject $affected;
    private StoreContextProvider&MockObject $stores;
    private HostReindexer&MockObject $reindexer;
    private LoggerInterface&MockObject $logger;
    /** @var list<array{0: list<int>, 1: list<int>}> host ids and store ids of every reindex() call */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->affected = $this->createMock(AffectedHosts::class);
        $this->stores = $this->createMock(StoreContextProvider::class);
        $this->reindexer = $this->createMock(HostReindexer::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->calls = [];
    }

    private static function store(int $id, int $websiteId = 1): StoreContext
    {
        return new StoreContext($id, $websiteId, 's' . $id, 'en_US', 'https://s/', 'https://s/media/', 'home');
    }

    /** @return array{host_type: string, host_id: int, store_id: ?int} */
    private static function host(string $type, int $id, ?int $storeId = null): array
    {
        return ['host_type' => $type, 'host_id' => $id, 'store_id' => $storeId];
    }

    private function processor(): HostReindexProcessor
    {
        return new HostReindexProcessor($this->affected, $this->stores, $this->reindexer, $this->logger);
    }

    private function recordReindex(): void
    {
        $this->reindexer->method('reindex')->willReturnCallback(function (array $hosts, array $stores): array {
            $this->calls[] = [array_column($hosts, 'host_id'), array_map(static fn (StoreContext $s): int => $s->getStoreId(), $stores)];
            return ['page' => count($hosts) * count($stores), 'category' => 1, 'product' => 2];
        });
    }

    public function testItIsTheQueueProcessor(): void
    {
        $this->assertInstanceOf(ReindexProcessorInterface::class, $this->processor());
    }

    public function testAMessageWithoutStoreReachesEveryStoreView(): void
    {
        $hosts = [self::host('page', 5)];
        $this->affected->method('resolve')->willReturn([$hosts]);
        $this->stores->method('all')->willReturn([self::store(1), self::store(2)]);
        $this->stores->expects($this->never())->method('get');
        $this->reindexer->expects($this->once())->method('reindex')->with($hosts, [self::store(1), self::store(2)])
            ->willReturn(['page' => 2, 'category' => 0, 'product' => 0]);
        $this->processor()->process(new ReindexMessage('block', 12, 'save', 1760000000));
    }

    public function testAStoreScopedMessageOnlyTouchesThatStoreView(): void
    {
        $hosts = [self::host('product', 7)];
        $this->affected->method('resolve')->willReturn([$hosts]);
        $this->stores->method('get')->with(3)->willReturn(self::store(3));
        $this->stores->expects($this->never())->method('all');
        $this->reindexer->expects($this->once())->method('reindex')->with($hosts, [self::store(3)])
            ->willReturn(['page' => 0, 'category' => 0, 'product' => 1]);
        $this->processor()->process(new ReindexMessage('product', 7, 'save', 1760000000, 3));
    }

    public function testATermMessageOnlyTouchesTheStoreViewsOfTheTermsWebsite(): void
    {
        $this->affected->method('resolve')->willReturn([[self::host('page', 4)]]);
        $this->stores->method('all')->willReturn([self::store(1, 1), self::store(2, 2), self::store(3, 2), self::store(5, 3)]);
        $this->recordReindex();
        $this->processor()->process(new ReindexMessage('classification_term', 9, 'save', 1, null, 2));
        $this->assertSame([[[4], [2, 3]]], $this->calls);
    }

    public function testAStoreViewOutsideTheMessageWebsiteIsNotTouched(): void
    {
        $this->affected->method('resolve')->willReturn([[self::host('page', 4)]]);
        $this->stores->method('get')->with(1)->willReturn(self::store(1, 1));
        $this->reindexer->expects($this->never())->method('reindex');
        $this->processor()->process(new ReindexMessage('classification_term', 9, 'save', 1, 1, 2));
    }

    public function testEveryChunkIsReindexedAndTheStoreViewsAreReadOnce(): void
    {
        $this->affected->method('resolve')->willReturn((static function (): \Generator {
            yield [self::host('page', 1), self::host('page', 2)];
            yield [self::host('page', 5)];
        })());
        $this->stores->expects($this->once())->method('all')->willReturn([self::store(1), self::store(2)]);
        $this->recordReindex();
        $this->processor()->process(new ReindexMessage('content_type', 5, 'save', 1));
        $this->assertSame([[[1, 2], [1, 2]], [[5], [1, 2]]], $this->calls);
    }

    public function testNoHostOrADeletedStoreViewIsASuccessfulNoOp(): void
    {
        $this->affected->method('resolve')->willReturnOnConsecutiveCalls([], [[self::host('page', 5)]], [[]]);
        $this->stores->method('all')->willReturn([self::store(1)]);
        $this->stores->method('get')->willReturn(null);
        $this->reindexer->expects($this->never())->method('reindex');
        $this->processor()->process(new ReindexMessage('dynamic_block', 3, 'delete', 1760000000));
        $this->processor()->process(new ReindexMessage('page', 5, 'save', 1760000000, 9));
        $this->processor()->process(new ReindexMessage('widget_instance', 18, 'delete', 1760000000));
    }

    public function testWithoutAnyStoreViewTheHostsAreNotEvenRead(): void
    {
        $this->affected->method('resolve')->willReturn((static function (): \Generator {
            throw new \RuntimeException('must not be iterated');
            yield []; // @phpstan-ignore-line unreachable, makes this a generator
        })());
        $this->stores->method('get')->willReturn(null);
        $this->reindexer->expects($this->never())->method('reindex');
        $this->processor()->process(new ReindexMessage('block', 12, 'save', 1, 99));
    }

    public function testFailuresPropagateSoTheHandlerDecides(): void
    {
        $this->stores->method('all')->willReturn([self::store(1)]);
        $this->affected->method('resolve')->willReturnCallback(static function (ReindexMessage $message): array {
            if ($message->getEntityType() === 'category') {
                throw new InvalidReindexMessageException('permanent');
            }
            return [[self::host('page', 5)]];
        });
        $this->reindexer->method('reindex')->willThrowException(new IndexingException('opensearch down'));
        $this->logger->expects($this->never())->method($this->anything());
        foreach ([['page', IndexingException::class, 'opensearch down'], ['category', InvalidReindexMessageException::class, 'permanent']] as [$type, $class, $error]) {
            try {
                $this->processor()->process(new ReindexMessage($type, 5, 'save', 1760000000));
                $this->fail('expected ' . $error);
            } catch (\Throwable $exception) {
                $this->assertInstanceOf($class, $exception);
                $this->assertSame($error, $exception->getMessage());
            }
        }
    }

    public function testReadFailuresOfTheStoreViewsPropagateUnchanged(): void
    {
        $failure = new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        $this->affected->method('resolve')->willReturn([[self::host('page', 5)]]);
        $this->stores->method('all')->willThrowException($failure);
        $this->reindexer->expects($this->never())->method('reindex');
        try {
            $this->processor()->process(new ReindexMessage('page', 5, 'save', 1));
            $this->fail('the failure must propagate');
        } catch (\Throwable $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testOneDebugLineWithIdsAndCountsOnlyIsLoggedPerMessage(): void
    {
        $this->affected->method('resolve')->willReturn([[self::host('page', 1), self::host('page', 2)], [self::host('product', 5)]]);
        $this->stores->method('all')->willReturn([self::store(1), self::store(2)]);
        $this->recordReindex();
        $this->logger->expects($this->never())->method('info');
        $this->logger->expects($this->once())->method('debug')->with(
            'reindexed block 12 (save): 3 host(s), 2 store view(s); refreshed 6 page(s), 2 category(ies), 4 product(s)'
        );
        $this->processor()->process(new ReindexMessage('block', 12, 'save', 1));
    }

    public function testANoOpMessageAlsoLogsItsSingleDebugLine(): void
    {
        $this->affected->method('resolve')->willReturn([]);
        $this->stores->method('all')->willReturn([self::store(1)]);
        $this->logger->expects($this->once())->method('debug')->with(
            'reindexed widget_instance 18 (delete): 0 host(s), 1 store view(s); refreshed 0 page(s), 0 category(ies), 0 product(s)'
        );
        $this->processor()->process(new ReindexMessage('widget_instance', 18, 'delete', 1));
    }

    /**
     * The processor runs inside the consumer: it must never save through repositories or models (that would loop
     * through the after-commit flush plugin and publish again) nor publish. Its only collaborators are the host
     * resolver, the store views, the host reindexer and the logger.
     */
    public function testTheProcessorCanOnlyResolveAndReindex(): void
    {
        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            (new \ReflectionMethod(HostReindexProcessor::class, '__construct'))->getParameters()
        );
        $this->assertSame([AffectedHosts::class, StoreContextProvider::class, HostReindexer::class, LoggerInterface::class], $types);
        foreach ([HostReindexProcessor::class, HostReindexer::class, AffectedHosts::class] as $class) {
            $code = (string) file_get_contents((string) (new \ReflectionClass($class))->getFileName());
            foreach (['Repository', 'Publisher', 'MessageSender', 'ReindexMessage(', '->save(', '->delete(', 'ObjectManager'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "$class must not use $forbidden");
            }
        }
    }
}
