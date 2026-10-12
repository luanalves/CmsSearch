<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType\Grid;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->addFilterToMap('type_id', 'main_table.type_id');
        $this->addFilterToMap('code', 'main_table.code');
        $this->addFilterToMap('default_label', 'lbl.label');

        $this->getSelect()
            ->joinLeft(
                ['lbl' => $this->getTable(Tables::CONTENT_TYPE_LABEL)],
                'lbl.type_id = main_table.type_id AND lbl.store_id = 0',
                ['default_label' => 'label']
            )
            ->columns([
                'pages_count' => new Expression(sprintf(
                    '(SELECT COUNT(pc.page_id) FROM %s AS pc WHERE pc.type_id = main_table.type_id)',
                    $this->getTable(Tables::PAGE_CLASSIFICATION)
                )),
            ]);
        return $this;
    }
}
