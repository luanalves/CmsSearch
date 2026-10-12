<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

class PageClassification extends AbstractDb
{
    protected $_isPkAutoIncrement = false;

    protected function _construct(): void
    {
        $this->_init(Tables::PAGE_CLASSIFICATION, 'page_id');
    }

    /**
     * @return array{type_id: int, term_id: int}|null
     */
    public function fetchRow(int $pageId): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getMainTable(), ['type_id', 'term_id'])->where('page_id = ?', $pageId)
        );
        return $row ? ['type_id' => (int) $row['type_id'], 'term_id' => (int) $row['term_id']] : null;
    }

    public function replace(int $pageId, int $typeId, int $termId): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            ['page_id' => $pageId, 'type_id' => $typeId, 'term_id' => $termId],
            ['type_id', 'term_id']
        );
    }

    public function remove(int $pageId): void
    {
        $this->getConnection()->delete($this->getMainTable(), ['page_id = ?' => $pageId]);
    }
}
