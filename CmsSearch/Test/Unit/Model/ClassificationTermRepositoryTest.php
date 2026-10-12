<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermSearchResultsInterfaceFactory;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Exception\ClassificationTermInUseException;
use Thedevkitchen\CmsSearch\Model\Classification\DeleteGuard;
use Thedevkitchen\CmsSearch\Model\Classification\PathManager;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\ClassificationTerm;
use Thedevkitchen\CmsSearch\Model\ClassificationTermFactory;
use Thedevkitchen\CmsSearch\Model\ClassificationTermRepository;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm\CollectionFactory;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ClassificationTermBuilder;

class ClassificationTermRepositoryTest extends TestCase
{
    private TermResource&MockObject $resource;
    private AdapterInterface&MockObject $connection;
    private PathManager&MockObject $pathManager;
    private DeleteGuard&MockObject $deleteGuard;
    private LoggerInterface&MockObject $logger;
    private ReindexPublisherInterface&MockObject $publisher;
    private ClassificationTermRepository $repository;
    /** @var array<int, array<string, mixed>> stored states by term id */
    private array $stored = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resource = $this->createMock(TermResource::class);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getStoredState')->willReturnCallback(fn (int $id) => $this->stored[$id] ?? null);
        $this->pathManager = $this->createMock(PathManager::class);
        $this->deleteGuard = $this->createMock(DeleteGuard::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->publisher = $this->createMock(ReindexPublisherInterface::class);

        // Websites 2 and 3; store views 2 (website 2) and 5, 6 (website 3).
        $websites = [];
        foreach ([2, 3] as $id) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $websites[] = $website;
        }
        $stores = [];
        foreach ([2 => 2, 5 => 3, 6 => 3] as $storeId => $websiteId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getStores')->willReturn($stores);

        $this->repository = new ClassificationTermRepository(
            $this->resource,
            $this->createMock(ClassificationTermFactory::class),
            $this->createMock(CollectionFactory::class),
            $this->createMock(CollectionProcessorInterface::class),
            $this->createMock(ClassificationTermSearchResultsInterfaceFactory::class),
            $this->pathManager,
            $this->deleteGuard,
            new TreeBuilder($this->resource),
            $storeManager,
            $this->logger,
            $this->publisher
        );
    }

    private function term(?int $id, int $websiteId = 2, string $code = 'agro', ?int $parentId = null, array $labels = [0 => 'Agro']): ClassificationTerm
    {
        return ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), $id, $websiteId, $code, $parentId, $labels);
    }

    private function store(int $id, int $websiteId, string $code, ?int $parentId, string $path, int $level): void
    {
        $this->stored[$id] = ['term_id' => $id, 'website_id' => $websiteId, 'parent_id' => $parentId, 'code' => $code,
            'path' => $path, 'level' => $level, 'is_active' => true];
    }

    private function expectRejected(ClassificationTermInterface $term, string $exception, string $messagePart): void
    {
        $this->resource->expects($this->never())->method('save');
        try {
            $this->repository->save($term);
            $this->fail("expected $exception");
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertStringContainsString($messagePart, $thrown->getMessage());
        }
    }

    public function testInvalidCodeIsRejected(): void
    {
        $this->expectRejected($this->term(null, 2, 'Agro Code'), InputException::class, 'code must have 1-64 characters');
    }

    public function testUnknownWebsiteIsRejected(): void
    {
        $this->expectRejected($this->term(null, 9), InputException::class, 'existing website');
    }

    public function testCodeMustBeUniqueInTheWebsite(): void
    {
        $this->resource->method('codeExists')->with(2, 'agro', null)->willReturn(true);
        $this->expectRejected($this->term(null), AlreadyExistsException::class, 'already exists in this website');
    }

    public function testCodeCannotChange(): void
    {
        $this->store(4, 2, 'agro_old', null, '4', 0);
        $this->expectRejected($this->term(4), InputException::class, 'cannot be changed');
    }

    public function testWebsiteCannotChange(): void
    {
        $this->store(4, 3, 'agro', null, '4', 0);
        $this->expectRejected($this->term(4, 2), InputException::class, 'website of a classification term cannot be changed');
    }

    public function testUpdatingAMissingTermFails(): void
    {
        $this->expectRejected($this->term(4), NoSuchEntityException::class, 'does not exist');
    }

    public function testDefaultLabelIsRequired(): void
    {
        $this->expectRejected($this->term(null, 2, 'agro', null, [2 => 'Agro']), InputException::class, 'default label is required');
    }

    public function testLabelsOnlyForStoreViewsOfTheTermsWebsite(): void
    {
        $this->expectRejected($this->term(null, 2, 'agro', null, [0 => 'Agro', 5 => 'Agro W2']), InputException::class, 'store views of the website');
    }

    public function testLabelLengthIsBounded(): void
    {
        $this->expectRejected($this->term(null, 2, 'agro', null, [0 => str_repeat('a', 256)]), InputException::class, 'at most 255');
    }

    public function testSortOrderIsBounded(): void
    {
        $this->expectRejected($this->term(null)->setSortOrder(65536), InputException::class, 'between 0 and 65535');
    }

    public function testParentMustExist(): void
    {
        $this->expectRejected($this->term(null, 2, 'agro', 99), InputException::class, 'parent term does not exist');
    }

    public function testParentMustBeInTheSameWebsite(): void
    {
        $this->store(7, 3, 'tv', null, '7', 0);
        $this->expectRejected($this->term(null, 2, 'agro', 7), InputException::class, 'same website');
    }

    public function testATermCannotMoveUnderItsOwnDescendant(): void
    {
        $this->store(4, 2, 'agro', 1, '1/4', 1);
        $this->store(9, 2, 'milho', 4, '1/4/9', 2);
        $this->expectRejected($this->term(4, 2, 'agro', 9), InputException::class, 'under itself or one of its descendants');
    }

    public function testATermCannotBeItsOwnParent(): void
    {
        $this->store(4, 2, 'agro', null, '4', 0);
        $this->expectRejected($this->term(4, 2, 'agro', 4), InputException::class, 'under itself');
    }

    public function testCreatingARootTermPlacesItInsideOneTransaction(): void
    {
        $calls = [];
        $this->connection->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'begin';
            return $this->connection;
        });
        $this->connection->method('commit')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'commit';
            return $this->connection;
        });
        $this->connection->expects($this->never())->method('rollBack');
        $term = $this->term(null)->setData('path', '9/9/9');
        $this->resource->expects($this->once())->method('save')->willReturnCallback(
            function (ClassificationTerm $saved) use (&$calls) {
                $calls[] = 'save:' . $saved->getPath();
                $saved->setTermId(15);
                return $this->resource;
            }
        );
        $this->pathManager->expects($this->once())->method('relocate')->with(15, null, '')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'relocate';
                return ['path' => '15', 'level' => 0];
            });

        $saved = $this->repository->save($term);

        $this->assertSame(['begin', 'save:', 'relocate', 'commit'], $calls);
        $this->assertSame('15', $saved->getPath());
        $this->assertSame(0, $saved->getLevel());
    }

    public function testAnUpdateWithoutParentChangeKeepsTheStoredPath(): void
    {
        $this->store(4, 2, 'agro', 1, '1/4', 1);
        $this->store(9, 2, 'milho', 4, '1/4/9', 2);
        $term = $this->term(9, 2, 'milho', 4)->setData('path', '666')->setData('level', 7);
        $this->resource->expects($this->once())->method('save')->willReturnCallback(
            function (ClassificationTerm $saved) {
                $this->assertSame('1/4/9', $saved->getPath());
                $this->assertSame(2, $saved->getLevel());
                return $this->resource;
            }
        );
        $this->pathManager->expects($this->never())->method('relocate');
        $this->repository->save($term);
    }

    public function testChangingTheParentMovesTheSubtree(): void
    {
        $this->store(4, 2, 'agro', 1, '1/4', 1);
        $this->store(7, 2, 'tv', null, '7', 0);
        $this->resource->method('save')->willReturnSelf();
        $this->pathManager->expects($this->once())->method('relocate')->with(4, 7, '1/4')
            ->willReturn(['path' => '7/4', 'level' => 1]);
        $saved = $this->repository->save($this->term(4, 2, 'agro', 7));
        $this->assertSame('7/4', $saved->getPath());
    }

    public function testARejectedMoveRollsBackAndKeepsTheOriginalException(): void
    {
        $this->store(4, 2, 'agro', 1, '1/4', 1);
        $this->store(7, 2, 'tv', null, '7', 0);
        $this->resource->method('save')->willReturnSelf();
        $tooDeep = new InputException(__('too deep'));
        $this->pathManager->method('relocate')->willThrowException($tooDeep);
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');
        try {
            $this->repository->save($this->term(4, 2, 'agro', 7));
            $this->fail('expected InputException');
        } catch (InputException $exception) {
            $this->assertSame($tooDeep, $exception);
        }
    }

    public function testAnUnexpectedErrorIsLoggedAndHidden(): void
    {
        $this->resource->method('save')->willThrowException(new \RuntimeException('SQLSTATE[23000] secret'));
        $this->connection->expects($this->once())->method('rollBack');
        $this->logger->expects($this->once())->method('error');
        try {
            $this->repository->save($this->term(null));
            $this->fail('expected CouldNotSaveException');
        } catch (CouldNotSaveException $exception) {
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }
    }

    public function testAnObjectThatIsNotAModelIsRejected(): void
    {
        $this->expectException(InputException::class);
        $this->repository->save($this->createMock(ClassificationTermInterface::class));
    }

    public function testDeleteAsksTheGuardFirst(): void
    {
        $term = $this->term(4);
        $this->deleteGuard->method('assertDeletable')->with($term)
            ->willThrowException(new ClassificationTermInUseException(__('in use')));
        $this->resource->expects($this->never())->method('delete');
        $this->expectException(ClassificationTermInUseException::class);
        $this->repository->delete($term);
    }

    public function testDeleteOfAFreeTerm(): void
    {
        $term = $this->term(4);
        $this->deleteGuard->expects($this->once())->method('assertDeletable')->with($term);
        $this->resource->expects($this->once())->method('delete')->with($term);
        $this->assertTrue($this->repository->delete($term));
    }

    public function testASavePublishesOneMessageInsideTheTransactionBeforeTheCommit(): void
    {
        $calls = [];
        $this->connection->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'begin';
            return $this->connection;
        });
        $this->connection->method('commit')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'commit';
            return $this->connection;
        });
        $this->resource->method('save')->willReturnCallback(function (ClassificationTerm $saved) use (&$calls) {
            $calls[] = 'save';
            $saved->setTermId(15);
            return $this->resource;
        });
        $this->pathManager->method('relocate')->willReturn(['path' => '15', 'level' => 0]);
        $this->publisher->expects($this->once())->method('publish')->with('classification_term', 15, 'save', null, 2)
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'publish';
            });
        $this->repository->save($this->term(null));
        $this->assertSame(['begin', 'save', 'publish', 'commit'], $calls);
    }

    public function testAMoveOfASubtreePublishesOneMessageForTheMovedTermOnly(): void
    {
        $this->store(4, 2, 'agro', 1, '1/4', 1);
        $this->store(7, 2, 'tv', null, '7', 0);
        $this->resource->method('save')->willReturnSelf();
        $this->pathManager->method('relocate')->willReturn(['path' => '7/4', 'level' => 1]);
        $this->publisher->expects($this->once())->method('publish')->with('classification_term', 4, 'save', null, 2);
        $this->repository->save($this->term(4, 2, 'agro', 7));
    }

    public function testARejectedMovePublishesNothing(): void
    {
        $this->store(4, 2, 'agro', 1, '1/4', 1);
        $this->store(7, 2, 'tv', null, '7', 0);
        $this->resource->method('save')->willReturnSelf();
        $this->pathManager->method('relocate')->willThrowException(new InputException(__('too deep')));
        $this->publisher->expects($this->never())->method('publish');
        $this->expectException(InputException::class);
        $this->repository->save($this->term(4, 2, 'agro', 7));
    }

    public function testDeletePublishesTheTermAndItsWebsite(): void
    {
        $term = $this->term(4);
        $this->resource->expects($this->once())->method('delete')->with($term);
        $this->publisher->expects($this->once())->method('publish')->with('classification_term', 4, 'delete', null, 2);
        $this->repository->delete($term);
    }

    public function testABlockedDeletePublishesNothing(): void
    {
        $term = $this->term(4);
        $this->deleteGuard->method('assertDeletable')->willThrowException(new ClassificationTermInUseException(__('in use')));
        $this->publisher->expects($this->never())->method('publish');
        $this->expectException(ClassificationTermInUseException::class);
        $this->repository->delete($term);
    }

    public function testAFailedDeletePublishesNothing(): void
    {
        $this->resource->method('delete')->willThrowException(new \RuntimeException('db'));
        $this->publisher->expects($this->never())->method('publish');
        $this->expectException(\Magento\Framework\Exception\CouldNotDeleteException::class);
        $this->repository->delete($this->term(4));
    }
}
