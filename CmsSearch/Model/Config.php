<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Module settings (Stores > Configuration > Thedevkitchen > CMS Search). No hardcoded values besides config.xml.
 */
class Config
{
    public const XML_PATH_MAX_ATTEMPTS = 'thedevkitchen_cmssearch/queue/max_attempts';
    public const DEFAULT_MAX_ATTEMPTS = 3;
    public const MIN_MAX_ATTEMPTS = 1;
    public const MAX_MAX_ATTEMPTS = 10;

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
}
