<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface;
use Thedevkitchen\CmsSearch\Model\ClassificationTerm;
use Thedevkitchen\CmsSearch\Model\Db\Tables;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'term_id';

    protected function _construct(): void
    {
        $this->_init(ClassificationTerm::class, TermResource::class);
    }

    /**
     * Hydrates the labels of all loaded terms with a single query.
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();
        $ids = array_keys($this->_items);
        if (!$ids) {
            return $this;
        }
        $select = $this->getConnection()->select()
            ->from($this->getTable(Tables::CLASSIFICATION_TERM_LABEL), ['term_id', 'store_id', 'label'])
            ->where('term_id IN (?)', $ids);
        $labels = [];
        foreach ($this->getConnection()->fetchAll($select) as $row) {
            $labels[(int) $row['term_id']][(int) $row['store_id']] = (string) $row['label'];
        }
        foreach ($this->_items as $id => $item) {
            $item->setData(ClassificationTermInterface::LABELS, $labels[(int) $id] ?? []);
        }
        return $this;
    }
}
