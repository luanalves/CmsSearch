<?php

declare(strict_types=1);

namespace Thedevkitchen\RestrictionReason\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Thedevkitchen\RestrictionReason\Api\Data\RestrictionReasonInterface;
use Thedevkitchen\RestrictionReason\Api\RestrictionReasonRepositoryInterface;
use Thedevkitchen\RestrictionReason\Model\Config\Paths;
use Thedevkitchen\RestrictionReason\Model\Data\RestrictionReason;

class RestrictionReasonRepository implements RestrictionReasonRepositoryInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getByWebsiteId(int $websiteId): RestrictionReasonInterface
    {
        if ($websiteId <= 0) {
            // Website id 0 is the admin pseudo-website, not a real storefront website.
            throw new NoSuchEntityException(__('The website with id "%1" does not exist.', $websiteId));
        }

        $scopeId = (int) $this->storeManager->getWebsite($websiteId)->getId();
        $scope = ScopeInterface::SCOPE_WEBSITE;

        return new RestrictionReason([
            'website_id' => $scopeId,
            'is_active' => $this->scopeConfig->isSetFlag(Paths::IS_ACTIVE, $scope, $scopeId),
            'mode' => (int) $this->scopeConfig->getValue(Paths::MODE, $scope, $scopeId),
            'reason' => (string) $this->scopeConfig->getValue(Paths::REASON, $scope, $scopeId),
        ]);
    }
}
