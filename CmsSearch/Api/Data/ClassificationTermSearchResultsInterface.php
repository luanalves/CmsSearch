<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface ClassificationTermSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return ClassificationTermInterface[]
     */
    public function getItems();

    /**
     * @param ClassificationTermInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
