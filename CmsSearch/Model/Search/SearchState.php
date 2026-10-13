<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Magento\Framework\App\RequestInterface;
use Magento\Search\Helper\Data as SearchHelper;
use Magento\Search\Model\QueryFactoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\CmsSearchQueryInterface;
use Thedevkitchen\CmsSearch\Exception\SearchUnavailableException;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Search\Data\CmsResult;
use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;

/**
 * Everything the search result page needs to know, decided once per request (shared instance): is the module on
 * for this store view, which tabs exist, which one is active, the selected classification term, the page, and the
 * single answer of the CMS index. Uses the search text already normalized by Magento's own flow (QueryFactory).
 * Never throws: any failure leaves the native Products page (TS-41), logged once and without the searched text.
 */
class SearchState
{
    public const MAX_QUERY_LENGTH = 256;

    private bool $resolved = false;
    private bool $enabled = false;
    private string $queryText = '';
    private int $storeId = 0;
    private int $websiteId = 0;
    /** @var list<Tab> */
    private array $tabs = [];
    private ?Tab $active = null;
    private ?CmsResult $result = null;
    /** @var array{term_id: int, code: string, label: string}|null */
    private ?array $term = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $tree = null;
    private int $page = 1;
    private int $pageSize = Config::DEFAULT_PAGE_SIZE;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly QueryFactoryInterface $queryFactory,
        private readonly SearchHelper $searchHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly TabResolver $tabResolver,
        private readonly ContentTypeTabs $contentTypeTabs,
        private readonly ClassificationTerms $terms,
        private readonly CmsSearchQueryInterface $query,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Tabs and CMS results apply to this request (module on for the store view, a usable search text). */
    public function isEnabled(): bool
    {
        $this->resolve();
        return $this->enabled;
    }

    public function getQueryText(): string
    {
        $this->resolve();
        return $this->queryText;
    }

    public function getStoreId(): int
    {
        $this->resolve();
        return $this->storeId;
    }

    public function getWebsiteId(): int
    {
        $this->resolve();
        return $this->websiteId;
    }

    /** @return list<Tab> [] when disabled; only the Products tab when the CMS index cannot answer */
    public function getTabs(): array
    {
        $this->resolve();
        return $this->tabs;
    }

    public function getActiveTab(): Tab
    {
        $this->resolve();
        return $this->active ?? new Tab(TabResolver::PRODUCTS, Tab::KIND_PRODUCTS, '', null);
    }

    public function isCmsTabActive(): bool
    {
        return $this->getActiveTab()->isCms();
    }

    public function getResult(): ?CmsResult
    {
        $this->resolve();
        return $this->result;
    }

    /** @return array{term_id: int, code: string, label: string}|null */
    public function getSelectedTerm(): ?array
    {
        $this->resolve();
        return $this->term;
    }

    /** @return list<array<string, mixed>> the website's effective-active tree on content tabs, else [] */
    public function getTermTree(): array
    {
        if ($this->getActiveTab()->getKind() !== Tab::KIND_CONTENT) { // a content tab is only active with a CMS result
            return [];
        }
        try {
            return $this->tree ??= $this->terms->tree($this->websiteId, $this->storeId);
        } catch (\Throwable $exception) {
            $this->log('error', sprintf(
                'CMS search classification filter hidden for store %d by an unexpected error (%s).',
                $this->storeId,
                get_class($exception)
            ));
            return $this->tree = [];
        }
    }

    public function getPage(): int
    {
        $this->resolve();
        return $this->page;
    }

    public function getPageSize(): int
    {
        $this->resolve();
        return $this->pageSize;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;
        $products = new Tab(TabResolver::PRODUCTS, Tab::KIND_PRODUCTS, '', null);
        $this->active = $products;
        try {
            $store = $this->storeManager->getStore();
            $this->storeId = (int) $store->getId();
            $this->websiteId = (int) $store->getWebsiteId();
            $products = new Tab(TabResolver::PRODUCTS, Tab::KIND_PRODUCTS, $this->config->getProductsLabel($this->storeId), null);
            $this->active = $products;
            if (!$this->config->isEnabled($this->storeId)) {
                return;
            }
            // Already trimmed and cut to catalog/search/max_query_length by QueryFactory; bounded again here (TS-5).
            $this->queryText = trim(mb_substr(trim((string) $this->queryFactory->get()->getQueryText()), 0, self::MAX_QUERY_LENGTH));
            if ($this->queryText === '' || $this->searchHelper->isMinQueryLength()) {
                return;
            }
            $this->enabled = true;
            $this->tabs = [$products];
            $this->pageSize = $this->config->getPageSize($this->storeId);

            $types = array_values(array_filter(
                $this->contentTypeTabs->forStore($this->storeId),
                fn (array $type): bool => !$this->tabResolver->isReserved($type['code'])
            ));
            $requested = $this->tabResolver->readTab($this->request->getParam(TabResolver::PARAM_TAB));
            $kind = Tab::KIND_PRODUCTS;
            if ($requested === TabResolver::CATEGORIES) {
                $kind = Tab::KIND_CATEGORIES;
            } elseif (in_array($requested, array_column($types, 'code'), true)) {
                $kind = Tab::KIND_CONTENT;
            }
            // cls only on content tabs (D4), and only a term of this website's effective-active tree (D6).
            if ($kind === Tab::KIND_CONTENT) {
                $termCode = $this->tabResolver->readTermCode($this->request->getParam(TabResolver::PARAM_TERM));
                $this->term = $termCode === null ? null : $this->terms->findByCode($this->websiteId, $this->storeId, $termCode);
            }
            if ($kind !== Tab::KIND_PRODUCTS) {
                $this->page = $this->tabResolver->clampPage(
                    $this->tabResolver->readPage($this->request->getParam(TabResolver::PARAM_PAGE)),
                    $this->pageSize
                );
            }

            try {
                // The only CMS index request of the page: counters, hits of the active tab and its facets.
                $this->result = $this->query->search(new SearchRequest(
                    $this->queryText,
                    $this->storeId,
                    $this->websiteId,
                    $kind,
                    $kind === Tab::KIND_CONTENT ? $requested : null,
                    $this->term['term_id'] ?? null,
                    $this->page,
                    $this->pageSize
                ));
            } catch (SearchUnavailableException $exception) {
                if (!$exception->isMissingIndex()) {
                    // The message names the alias and the engine exception class only (never the searched text).
                    $this->log('warning', sprintf(
                        'CMS search unavailable for store %d, CMS tabs hidden: %s',
                        $this->storeId,
                        $exception->getMessage()
                    ));
                }
                $this->fallBack($products);
                return;
            }

            if ($this->result->hasCategoryDocuments()) {
                $this->tabs[] = new Tab(
                    TabResolver::CATEGORIES,
                    Tab::KIND_CATEGORIES,
                    $this->config->getCategoriesLabel($this->storeId),
                    $this->result->getCategoryCount()
                );
            }
            foreach ($types as $type) {
                $this->tabs[] = new Tab($type['code'], Tab::KIND_CONTENT, $type['label'], $this->result->getCount($type['code']));
            }
            // A requested content type always has its tab (so a term only survives there); an unavailable
            // categories tab falls back to Products, and so does its page.
            $this->active = $this->tabResolver->resolve($requested, $this->tabs);
            if (!$this->active->isCms()) {
                $this->page = 1;
            }
        } catch (\Throwable $exception) {
            $this->log('error', sprintf(
                'CMS search tabs disabled for store %d by an unexpected error (%s).',
                $this->storeId,
                get_class($exception)
            ));
            $this->fallBack($products);
        }
    }

    /** Only the native Products tab remains (no tab bar is rendered for a single tab). */
    private function fallBack(Tab $products): void
    {
        $this->tabs = $this->enabled ? [$products] : [];
        $this->active = $products;
        $this->result = null;
        $this->term = null;
        $this->page = 1;
    }

    /** A log that cannot be written (e.g. an unwritable var/log) must not break the page either. */
    private function log(string $level, string $message): void
    {
        try {
            $this->logger->{$level}($message);
        } catch (\Throwable) {
            // nothing else to do: the page already shows the native Products tab
        }
    }
}
