<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The frontend theme of a store view (store > website > default), which decides which widget instances it shows.
 * Scheduled design changes (design_change) are not considered.
 */
class StoreThemeResolver
{
    public const XML_PATH_THEME = 'design/theme/theme_id';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function themeId(int $storeId): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_THEME, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
