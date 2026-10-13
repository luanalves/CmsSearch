<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;

/**
 * Links of the search result page (D11). The Products tab links to "?q=" only: the native, cacheable URL. CMS tabs
 * add tab, then cls and p when needed; switching tabs drops the filter and the page.
 */
class ResultUrl
{
    public const ROUTE = 'catalogsearch/result';

    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request
    ) {
    }

    public function build(string $queryText, ?string $tab = null, ?string $termCode = null, int $page = 1): string
    {
        $query = ['q' => $queryText];
        if ($tab !== null && $tab !== TabResolver::PRODUCTS) {
            $query[TabResolver::PARAM_TAB] = $tab;
            if ($termCode !== null) {
                $query[TabResolver::PARAM_TERM] = $termCode;
            }
            if ($page > 1) {
                $query[TabResolver::PARAM_PAGE] = (string) $page;
            }
        }
        return $this->urlBuilder->getUrl(self::ROUTE, ['_query' => $query, '_secure' => (bool) $this->request->isSecure()]);
    }
}
