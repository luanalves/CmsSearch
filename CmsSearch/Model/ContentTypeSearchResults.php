<?php
declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model;

use Magento\Framework\Api\SearchResults;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterface;

/**
 * Concrete search results type; the framework SearchResults does not implement the module interface.
 */
class ContentTypeSearchResults extends SearchResults implements ContentTypeSearchResultsInterface
{
}
