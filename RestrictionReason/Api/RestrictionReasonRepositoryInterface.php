<?php

declare(strict_types=1);

namespace Thedevkitchen\RestrictionReason\Api;

use Magento\Framework\Exception\NoSuchEntityException;
use Thedevkitchen\RestrictionReason\Api\Data\RestrictionReasonInterface;

/**
 * @api
 */
interface RestrictionReasonRepositoryInterface
{
    /**
     * Get the restriction state and reason of a website.
     *
     * @param int $websiteId
     * @return \Thedevkitchen\RestrictionReason\Api\Data\RestrictionReasonInterface
     * @throws NoSuchEntityException
     */
    public function getByWebsiteId(int $websiteId): RestrictionReasonInterface;
}
