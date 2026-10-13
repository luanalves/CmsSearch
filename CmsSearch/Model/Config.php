<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Module settings (Stores > Configuration > Thedevkitchen > CMS Search). No hardcoded values besides config.xml.
 * Every getter falls back to its default for an unusable stored value instead of stopping the consumer or breaking
 * the search result page.
 */
class Config
{
    public const XML_PATH_MAX_ATTEMPTS = 'thedevkitchen_cmssearch/queue/max_attempts';
    public const DEFAULT_MAX_ATTEMPTS = 3;
    public const MIN_MAX_ATTEMPTS = 1;
    public const MAX_MAX_ATTEMPTS = 10;

    public const XML_PATH_ENABLED = 'thedevkitchen_cmssearch/general/enabled';
    public const XML_PATH_PRODUCTS_LABEL = 'thedevkitchen_cmssearch/tabs/products_label';
    public const XML_PATH_CATEGORIES_LABEL = 'thedevkitchen_cmssearch/tabs/categories_label';
    public const XML_PATH_PAGE_SIZE = 'thedevkitchen_cmssearch/results/page_size';
    public const XML_PATH_SUMMARY_LENGTH = 'thedevkitchen_cmssearch/results/summary_length';
    public const XML_PATH_SEARCH_TIMEOUT_MS = 'thedevkitchen_cmssearch/results/search_timeout_ms';

    public const MAX_LABEL_LENGTH = 64;
    public const DEFAULT_PAGE_SIZE = 12;
    public const MIN_PAGE_SIZE = 1;
    public const MAX_PAGE_SIZE = 60;
    public const DEFAULT_SUMMARY_LENGTH = 200;
    public const MIN_SUMMARY_LENGTH = 50;
    public const MAX_SUMMARY_LENGTH = 300;
    public const DEFAULT_SEARCH_TIMEOUT_MS = 1500;
    public const MIN_SEARCH_TIMEOUT_MS = 1;
    public const MAX_SEARCH_TIMEOUT_MS = 10000;

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * Total processing attempts of one reindex message, the first try included (D10). Global scope: the consumer
     * runs outside any store. An unusable stored value falls back to the default instead of stopping the consumer.
     */
    public function getMaxAttempts(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_MAX_ATTEMPTS);
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]{1,2}$/', trim($value)) !== 1) {
            return self::DEFAULT_MAX_ATTEMPTS;
        }
        $attempts = (int) trim($value);
        return $attempts >= self::MIN_MAX_ATTEMPTS && $attempts <= self::MAX_MAX_ATTEMPTS
            ? $attempts
            : self::DEFAULT_MAX_ATTEMPTS;
    }

    /** Tabs, counters and CMS results on the search result page of this store view (No: the native page). */
    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getProductsLabel(int $storeId): string
    {
        return $this->label(self::XML_PATH_PRODUCTS_LABEL, $storeId, (string) __('Products'));
    }

    public function getCategoriesLabel(int $storeId): string
    {
        return $this->label(self::XML_PATH_CATEGORIES_LABEL, $storeId, (string) __('Categories'));
    }

    /** Cards per page on the CMS tabs. */
    public function getPageSize(int $storeId): int
    {
        return $this->boundedInt(
            $this->scopeConfig->getValue(self::XML_PATH_PAGE_SIZE, ScopeInterface::SCOPE_STORE, $storeId),
            self::DEFAULT_PAGE_SIZE,
            self::MIN_PAGE_SIZE,
            self::MAX_PAGE_SIZE
        );
    }

    /** Characters of the card summary (cut at a word boundary). */
    public function getSummaryLength(int $storeId): int
    {
        return $this->boundedInt(
            $this->scopeConfig->getValue(self::XML_PATH_SUMMARY_LENGTH, ScopeInterface::SCOPE_STORE, $storeId),
            self::DEFAULT_SUMMARY_LENGTH,
            self::MIN_SUMMARY_LENGTH,
            self::MAX_SUMMARY_LENGTH
        );
    }

    /** Request and connect timeout of the CMS search request (operator setting, config.xml only). */
    public function getSearchTimeoutMs(): int
    {
        return $this->boundedInt(
            $this->scopeConfig->getValue(self::XML_PATH_SEARCH_TIMEOUT_MS),
            self::DEFAULT_SEARCH_TIMEOUT_MS,
            self::MIN_SEARCH_TIMEOUT_MS,
            self::MAX_SEARCH_TIMEOUT_MS
        );
    }

    private function boundedInt(mixed $value, int $default, int $min, int $max): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]{1,6}$/', trim($value)) !== 1) {
            return $default;
        }
        $number = (int) trim($value);
        return $number >= $min && $number <= $max ? $number : $default;
    }

    private function label(string $path, int $storeId, string $fallback): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        $label = is_string($value) ? trim($value) : '';
        return $label === '' ? $fallback : mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }
}
