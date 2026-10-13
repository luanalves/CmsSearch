<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api;

use Thedevkitchen\CmsSearch\Exception\SearchUnavailableException;
use Thedevkitchen\CmsSearch\Model\Search\Data\CmsResult;
use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;

/**
 * Read side of the CMS search index (spec 5.6): one request per result page returns the counters of every tab, the
 * requested page of the active CMS tab and its classification facet counts. Never writes. The index searched is the
 * one of the CURRENT store view; a request built for another store view is refused.
 */
interface CmsSearchQueryInterface
{
    /**
     * @throws SearchUnavailableException the store view has no index yet, or the engine failed or timed out
     */
    public function search(SearchRequest $request): CmsResult;
}
