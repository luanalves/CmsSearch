<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;
use Thedevkitchen\CmsSearch\Model\ContentType;
use Thedevkitchen\CmsSearch\Model\Db\Tables;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType as ContentTypeResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'type_id';

    protected function _construct(): void
    {
        $this->_init(ContentType::class, ContentTypeResource::class);
    }

    protected function _afterLoad()
    {
        parent::_afterLoad();
        $ids = array_keys($this->_items);
        if (!$ids) {
            return $this;
        }

        $select = $this->getConnection()->select()
            ->from($this->getTable(Tables::CONTENT_TYPE_LABEL), ['type_id', 'store_id', 'label'])
            ->where('type_id IN (?)', $ids);
        $labels = [];
        foreach ($this->getConnection()->fetchAll($select) as $row) {
            $labels[(int) $row['type_id']][(int) $row['store_id']] = (string) $row['label'];
        }
        foreach ($this->_items as $id => $item) {
            $item->setData(ContentTypeInterface::LABELS, $labels[(int) $id] ?? []);
        }
        return $this;
    }
}
