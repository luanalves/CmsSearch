<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Classification;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

class UsageCounter
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function countChildren(int $termId): int
    {
        $connection = $this->resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(Tables::CLASSIFICATION_TERM), [new Expression('COUNT(*)')])
                ->where('parent_id = ?', $termId)
        );
    }

    public function countPages(int $termId): int
    {
        $connection = $this->resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(Tables::PAGE_CLASSIFICATION), [new Expression('COUNT(*)')])
                ->where('term_id = ?', $termId)
        );
    }

    /**
     * @param int[] $termIds
     * @return array<int, int> term id => number of linked Pages (0 for every requested id without links)
     */
    public function countPagesByTerm(array $termIds): array
    {
        $termIds = array_values(array_unique(array_map('intval', $termIds)));
        if (!$termIds) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(Tables::PAGE_CLASSIFICATION), ['term_id', new Expression('COUNT(*)')])
                ->where('term_id IN (?)', $termIds)
                ->group('term_id')
        );
        $counts = array_fill_keys($termIds, 0);
        foreach ($pairs as $termId => $count) {
            $counts[(int) $termId] = (int) $count;
        }
        return $counts;
    }

    /**
     * @return array<int, array{page_id: string, title: string, identifier: string}> orphans: title '#<id>', identifier ''
     */
    public function firstPages(int $termId, int $limit): array
    {
        $connection = $this->resource->getConnection();
        // cms_page has one row per Staging version; group by page_id to list each Page once.
        $select = $connection->select()
            ->from(['pc' => $this->resource->getTableName(Tables::PAGE_CLASSIFICATION)], ['page_id'])
            ->joinLeft(
                ['p' => $this->resource->getTableName('cms_page')],
                'p.page_id = pc.page_id',
                ['title' => new Expression('MAX(p.title)'), 'identifier' => new Expression('MAX(p.identifier)')]
            )
            ->where('pc.term_id = ?', $termId)
            ->group('pc.page_id')
            ->order('pc.page_id ASC')
            ->limit($limit);
        $pages = $connection->fetchAll($select);
        foreach ($pages as &$page) {
            if ($page['title'] === null && $page['identifier'] === null) {
                $page['title'] = '#' . $page['page_id'];
                $page['identifier'] = '';
            }
        }
        return $pages;
    }
}
