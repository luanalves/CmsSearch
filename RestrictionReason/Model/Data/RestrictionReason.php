<?php

declare(strict_types=1);

namespace Thedevkitchen\RestrictionReason\Model\Data;

use Magento\Framework\DataObject;
use Thedevkitchen\RestrictionReason\Api\Data\RestrictionReasonInterface;

class RestrictionReason extends DataObject implements RestrictionReasonInterface
{
    public function getWebsiteId(): int
    {
        return (int) $this->getData('website_id');
    }

    public function getIsActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function getMode(): int
    {
        return (int) $this->getData('mode');
    }

    public function getReason(): string
    {
        return (string) $this->getData('reason');
    }
}
