<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;

/**
 * Store views the CMS search indexes (one index each); the admin store (0) never has an index.
 */
class StoreContextProvider
{
    public const XML_PATH_LOCALE = 'general/locale/code';
    public const XML_PATH_HOME_PAGE = 'web/default/cms_home_page';
    private const DEFAULT_LOCALE = 'en_US';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return list<StoreContext> every store view, ordered by id
     */
    public function all(): array
    {
        $contexts = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $context = $this->fromStore($store);
            if ($context !== null) {
                $contexts[$context->getStoreId()] = $context;
            }
        }
        ksort($contexts);
        return array_values($contexts);
    }

    public function get(int $storeId): ?StoreContext
    {
        if ($storeId <= 0) {
            return null;
        }
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            return null;
        }
        return $this->fromStore($store);
    }

    private function fromStore(StoreInterface $store): ?StoreContext
    {
        $storeId = (int) $store->getId();
        if ($storeId <= 0 || !$store instanceof Store) {
            return null;
        }
        $locale = trim((string) $this->scopeConfig->getValue(self::XML_PATH_LOCALE, ScopeInterface::SCOPE_STORE, $storeId));
        $home = (string) $this->scopeConfig->getValue(self::XML_PATH_HOME_PAGE, ScopeInterface::SCOPE_STORE, $storeId);
        $secure = (bool) $store->isFrontUrlSecure();
        return new StoreContext(
            $storeId,
            (int) $store->getWebsiteId(),
            (string) $store->getCode(),
            $locale !== '' ? $locale : self::DEFAULT_LOCALE,
            (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $secure),
            (string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, $secure),
            explode('|', $home, 2)[0]
        );
    }
}
