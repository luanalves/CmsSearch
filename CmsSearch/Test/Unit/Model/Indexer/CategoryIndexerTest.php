<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\CategoryDocumentBuilder;
use Thedevkitchen\CmsSearch\Model\Indexer\CategoryIndexer;
use Thedevkitchen\CmsSearch\Model\Indexer\CategoryPermissionReader;
use Thedevkitchen\CmsSearch\Model\Indexer\CategorySnapshotReader;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\CategorySnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\DocumentBuilder;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexManager;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;
use Thedevkitchen\CmsSearch\Model\Reference\BlockTextResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostEnrichment;

class CategoryIndexerTest extends TestCase
{
    private CategorySnapshotReader&MockObject $reader;
    private BlockTextResolver&MockObject $blockText;
    private IndexManager&MockObject $indexManager;
    private CategoryPermissionReader&MockObject $permissions;
    /** @var list<int> categories the NOT LOGGED IN group may not browse */
    private array $guestDenied = [];
    /** @var list<string> "store website: ids" asked to the permission reader */
    private array $permissionCalls = [];
    /** @var list<array{0: string, 1: list<string>, 2: bool}> */
    private array $bulks = [];
    /** @var array<string, string> document id => text */
    private array $texts = [];

    protected function setUp(): void
    {
        $this->reader = $this->createMock(CategorySnapshotReader::class);
        // root 2: 37 visible, 38 below another root, 39 inactive, 40 missing; store 3 sees the same rows
        $this->reader->method('read')->willReturnCallback(static function (array $ids, int $storeId): array {
            $all = [
                37 => new CategorySnapshot(37, '1/2/37', 2, 'Gear', '<p>Gear</p>', 0, 'PRODUCTS', true, true, 'gear.html', null),
                38 => new CategorySnapshot(38, '1/103/38', 2, 'Other root', '', 0, 'PRODUCTS', false, true, null, null),
                39 => new CategorySnapshot(39, '1/2/39', 2, 'Off', '', 0, 'PRODUCTS', false, false, null, null),
            ];
            return array_intersect_key($all, array_flip($ids));
        });
        $this->blockText = $this->createMock(BlockTextResolver::class);
        $this->permissions = $this->createMock(CategoryPermissionReader::class);
        $this->permissions->method('visibleToGuests')->willReturnCallback(function (array $ids, int $storeId, int $websiteId): array {
            $this->permissionCalls[] = sprintf('%d %d: %s', $storeId, $websiteId, implode(',', $ids));
            return array_values(array_diff($ids, $this->guestDenied));
        });
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->indexManager->method('ensureAlias')->willReturnCallback(static fn (StoreContext $s): string => 'alias_' . $s->getStoreId());
        $this->indexManager->method('bulk')->willReturnCallback(function (string $target, array $operations, bool $incremental): void {
            $this->bulks[] = [$target, array_map(static fn (array $op): string => $op['action'] . ' ' . $op['id'], $operations), $incremental];
            foreach ($operations as $op) {
                if ($op['action'] === 'index') {
                    $this->texts[$op['id']] = $op['document']['text'];
                }
            }
        });
    }

    private function indexer(int $batchSize = 200): CategoryIndexer
    {
        $store = $this->createMock(Store::class);
        $store->method('getRootCategoryId')->willReturn(2);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $extractor = new TextExtractor();
        return new CategoryIndexer(
            $this->reader,
            $this->blockText,
            new CategoryDocumentBuilder($extractor, new DocumentBuilder($extractor, new \Psr\Log\NullLogger())),
            $this->indexManager,
            $storeManager,
            $this->permissions,
            $batchSize
        );
    }

    private static function store(int $id): StoreContext
    {
        return new StoreContext($id, 1, 's' . $id, 'en_US', 'https://s.test/', 'https://s.test/media/', 'home');
    }

    public function testVisibleCategoriesAreUpsertedWithTheirBlockTextOthersDeleted(): void
    {
        $this->blockText->expects($this->once())->method('forHosts')->with('category', [37], 1)
            ->willReturn([37 => new HostEnrichment('Landing', [])]);
        $result = $this->indexer()->reindex([40, 37, 38, 39, 37], [self::store(1)]);
        $this->assertSame(['upserted' => 1, 'deleted' => 3], $result);
        $this->assertSame([['alias_1', ['index category_37', 'delete category_38', 'delete category_39', 'delete category_40'], true]], $this->bulks);
        $this->assertSame(['category_37' => 'Gear Landing'], $this->texts);
        $this->assertSame(['1 1: 37'], $this->permissionCalls, 'only active categories of the tree are checked');
    }

    public function testACategoryGuestsMayNotBrowseIsDeletedAndNeverWritten(): void
    {
        $this->guestDenied = [37];
        $this->blockText->expects($this->never())->method('forHosts');
        $this->assertSame(['upserted' => 0, 'deleted' => 2], $this->indexer()->reindex([37, 38], [self::store(1)]));
        $this->assertSame([['alias_1', ['delete category_37', 'delete category_38'], true]], $this->bulks);
        $this->bulks = [];
        $this->assertSame(['upserted' => 0, 'deleted' => 0], $this->indexer()->reindex([37, 38], [self::store(1)], [1 => 'idx_1_v2']));
        $this->assertSame([], $this->bulks, 'a full rebuild writes nothing for a restricted category');
    }

    public function testPermissionsAreCheckedWithTheWebsiteOfEachStoreView(): void
    {
        $this->indexer()->reindex([37], [self::store(1), new StoreContext(5, 3, 's5', 'pt_BR', 'https://five.test/', 'https://five.test/media/', 'home')]);
        $this->assertSame(['1 1: 37', '5 3: 37'], $this->permissionCalls);
    }

    public function testAFullRebuildWritesOnlyVisibleCategoriesIntoTheTarget(): void
    {
        $this->indexManager->expects($this->never())->method('ensureAlias');
        $result = $this->indexer()->reindex([37, 38], [self::store(1)], [1 => 'idx_1_v2']);
        $this->assertSame(['upserted' => 1, 'deleted' => 0], $result);
        $this->assertSame([['idx_1_v2', ['index category_37'], false]], $this->bulks);
    }

    public function testCategoriesAreReadPerStoreViewAndBatch(): void
    {
        $this->reader->expects($this->exactly(4))->method('read');
        $this->indexer(2)->reindex([37, 38, 39], [self::store(1), self::store(3)]);
        $this->assertSame(['alias_1', 'alias_1', 'alias_3', 'alias_3'], array_column($this->bulks, 0));
    }

    public function testAStoreViewWithoutRebuildTargetIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        $this->indexer()->reindex([37], [self::store(1), self::store(3)], [1 => 'idx_1_v2']);
    }
}
