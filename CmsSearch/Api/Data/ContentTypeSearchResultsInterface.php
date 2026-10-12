<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface ContentTypeSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return ContentTypeInterface[]
     */
    public function getItems();

    /**
     * @param ContentTypeInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
