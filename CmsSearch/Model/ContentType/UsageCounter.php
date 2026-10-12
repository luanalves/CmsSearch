<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ContentType;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

class UsageCounter
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function count(int $typeId): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(Tables::PAGE_CLASSIFICATION), [new Expression('COUNT(*)')])
            ->where('type_id = ?', $typeId);
        return (int) $connection->fetchOne($select);
    }

    /**
     * @return array<int, array{page_id: string, title: string, identifier: string}> orphans: title '#<id>', identifier ''
     */
    public function firstPages(int $typeId, int $limit): array
    {
        $connection = $this->resource->getConnection();
        // cms_page has one row per Staging version; group by page_id to list each Page once.
        $select = $connection->select()
            ->from(['pc' => $this->resource->getTableName(Tables::PAGE_CLASSIFICATION)], ['page_id'])
            ->joinLeft(
                ['p' => $this->resource->getTableName('cms_page')],
                'p.page_id = pc.page_id',
                [
                    'title' => new Expression('MAX(p.title)'),
                    'identifier' => new Expression('MAX(p.identifier)'),
                ]
            )
            ->where('pc.type_id = ?', $typeId)
            ->group('pc.page_id')
            ->order('pc.page_id ASC')
            ->limit($limit);
        $pages = $connection->fetchAll($select);
        // Classification rows whose Page no longer exists are listed by id so count and list agree.
        foreach ($pages as &$page) {
            if ($page['title'] === null && $page['identifier'] === null) {
                $page['title'] = '#' . $page['page_id'];
                $page['identifier'] = '';
            }
        }
        return $pages;
    }
}
