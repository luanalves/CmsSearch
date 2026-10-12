<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Thedevkitchen\CmsSearch\Model\ContentType;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType as ContentTypeResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'type_id';

    protected function _construct(): void
    {
        $this->_init(ContentType::class, ContentTypeResource::class);
    }
}
