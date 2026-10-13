<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Staging\Model\VersionHistoryInterface;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockSource;

/**
 * The blocks a store view displays for a set of references, with the storefront's own rules:
 * - CMS block (Magento\Cms\Model\ResourceModel\Block::_getLoadSelect with a store): active, assigned to the store view
 *   or to All Store Views, the store view's own block first; numeric values by block_id, others by identifier;
 * - Dynamic Block (Magento\Banner\Model\ResourceModel\Banner::getStoreContent): enabled, content of the store view
 *   else of All Store Views, empty content shows nothing; its customer segments are attached (D7), and a banner linked
 *   to a cart or catalog price rule is flagged rule-restricted: Magento\Banner\Model\Banner\Data::getFixedBanners()
 *   shows it only when the rule applies to the visitor, so it is excluded like a segment-restricted one (beyond the
 *   literal D7: visitor-dependent content that may carry coupon codes).
 * cms_block is a staged table: the Staging select renderer adds the current-version filter to every select on it, so
 * a scheduled version is never read before it starts. The renderer compares against the Staging version cached in
 * VersionHistory, which a long-lived consumer would keep forever: it is reloaded before every read of cms_block.
 * Queries are chunked (ids / identifiers / banners per chunk), never one per reference.
 */
class BlockReader
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly VersionHistoryInterface $versionHistory,
        private readonly int $chunkSize = 1000
    ) {
    }

    /**
     * @param list<BlockRef> $refs
     * @return array<string, BlockSource> BlockRef::key() => displayed block; refs that show nothing are absent
     */
    public function read(array $refs, int $storeId): array
    {
        $ids = [];
        $identifiers = [];
        $bannerIds = [];
        foreach ($refs as $ref) {
            if ($ref->getKind() === BlockRef::KIND_DYNAMIC_BLOCK) {
                $bannerIds[$ref->getId()] = true;
            } elseif ($ref->isByIdentifier()) {
                $identifiers[mb_strtolower($ref->getIdentifier())] ??= $ref->getIdentifier();
            } else {
                $ids[$ref->getId()] = true;
            }
        }
        $result = [];
        foreach ($this->chunks(array_keys($ids)) as $chunk) {
            foreach ($this->cmsBlocks('b.block_id IN (?)', $chunk, $storeId) as $source) {
                $result[(new BlockRef(BlockRef::KIND_BLOCK, $source->getId()))->key()] ??= $source;
            }
        }
        foreach ($this->chunks(array_values($identifiers)) as $chunk) {
            foreach ($this->cmsBlocks('b.identifier IN (?)', $chunk, $storeId) as $source) {
                $result[(new BlockRef(BlockRef::KIND_BLOCK, 0, $source->getIdentifier()))->key()] ??= $source;
            }
        }
        $contents = [];
        foreach ($this->chunks(array_keys($bannerIds)) as $chunk) {
            $contents += $this->bannerContents($chunk, $storeId);
        }
        foreach ($this->dynamicBlocks($contents) as $source) {
            $result[$source->key()] = $source;
        }
        return $result;
    }

    /** Identifier of the CURRENT version of a CMS block, or null when the block no longer exists. */
    public function identifierOf(int $blockId): ?string
    {
        $this->reloadStagingVersion();
        $connection = $this->resource->getConnection();
        $values = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('cms_block'), ['identifier'])
                ->where('block_id = ?', $blockId)
                ->limit(1)
        );
        $identifier = isset($values[0]) ? (string) $values[0] : '';
        return $identifier === '' ? null : $identifier;
    }

    /**
     * @param list<int|string> $values
     * @return list<BlockSource> the store view's own blocks first
     */
    private function cmsBlocks(string $condition, array $values, int $storeId): array
    {
        $this->reloadStagingVersion();
        $connection = $this->resource->getConnection();
        $linkField = $this->metadataPool->getMetadata(BlockInterface::class)->getLinkField();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['b' => $this->resource->getTableName('cms_block')], ['block_id', 'identifier', 'content'])
                ->join(
                    ['s' => $this->resource->getTableName('cms_block_store')],
                    's.' . $linkField . ' = b.' . $linkField,
                    ['store_id']
                )
                ->where($condition, $values)
                ->where('b.is_active = ?', 1)
                ->where('s.store_id IN (?)', [0, $storeId])
                ->order('s.store_id DESC')
        );
        return array_map(
            static fn (array $row): BlockSource => new BlockSource(
                BlockRef::KIND_BLOCK,
                (int) $row['block_id'],
                (string) $row['identifier'],
                (string) $row['content']
            ),
            $rows
        );
    }

    /**
     * @param list<int> $bannerIds
     * @return array<int, string> banner id => displayed content (store view's own, else All Store Views; blank = absent)
     */
    private function bannerContents(array $bannerIds, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['b' => $this->resource->getTableName('magento_banner')], ['banner_id'])
                ->join(
                    ['c' => $this->resource->getTableName('magento_banner_content')],
                    'c.banner_id = b.banner_id',
                    ['store_id', 'banner_content']
                )
                ->where('b.banner_id IN (?)', $bannerIds)
                ->where('b.is_enabled = ?', 1)
                ->where('c.store_id IN (?)', [0, $storeId])
                ->order('c.store_id DESC')
        );
        $contents = [];
        foreach ($rows as $row) {
            $contents[(int) $row['banner_id']] ??= (string) $row['banner_content'];
        }
        return array_filter($contents, static fn (string $content): bool => trim($content) !== '');
    }

    /**
     * @param array<int, string> $contents banner id => content
     * @return list<BlockSource> every Dynamic Block with the customer segments that restrict it (D7) and its rule flag
     */
    private function dynamicBlocks(array $contents): array
    {
        if ($contents === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $segments = [];
        $ruleLinked = [];
        foreach ($this->chunks(array_keys($contents)) as $chunk) {
            $links = $connection->fetchAll(
                $connection->select()
                    ->from($this->resource->getTableName('magento_banner_customersegment'), ['banner_id', 'segment_id'])
                    ->where('banner_id IN (?)', $chunk)
            );
            foreach ($links as $link) {
                $segments[(int) $link['banner_id']][(int) $link['segment_id']] = true;
            }
            // one query for both rule link tables (UNION), per chunk
            $ruled = $connection->fetchCol(
                $connection->select()->union([
                    $connection->select()
                        ->from($this->resource->getTableName('magento_banner_salesrule'), ['banner_id'])
                        ->where('banner_id IN (?)', $chunk),
                    $connection->select()
                        ->from($this->resource->getTableName('magento_banner_catalogrule'), ['banner_id'])
                        ->where('banner_id IN (?)', $chunk),
                ])
            );
            foreach ($ruled as $bannerId) {
                $ruleLinked[(int) $bannerId] = true;
            }
        }
        $sources = [];
        foreach ($contents as $bannerId => $content) {
            $segmentIds = array_keys($segments[$bannerId] ?? []);
            sort($segmentIds);
            $sources[] = new BlockSource(
                BlockRef::KIND_DYNAMIC_BLOCK,
                $bannerId,
                '',
                $content,
                $segmentIds,
                isset($ruleLinked[$bannerId])
            );
        }
        return $sources;
    }

    /**
     * @param list<int|string> $values
     * @return list<list<int|string>>
     */
    private function chunks(array $values): array
    {
        return $values === [] ? [] : array_chunk($values, max(1, $this->chunkSize));
    }

    private function reloadStagingVersion(): void
    {
        if ($this->versionHistory instanceof ResetAfterRequestInterface) {
            $this->versionHistory->_resetState();
        }
    }
}
