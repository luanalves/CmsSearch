<?php

declare(strict_types=1);

namespace Thedevkitchen\RestrictionReason\Api\Data;

/**
 * Website restriction state plus the recorded reason.
 *
 * @api
 */
interface RestrictionReasonInterface
{
    /**
     * @return int
     */
    public function getWebsiteId(): int;

    /**
     * Whether native Website Restrictions is enabled for the website.
     *
     * @return bool
     */
    public function getIsActive(): bool;

    /**
     * Native restriction mode (0 = website closed, 1 = login only, 2 = login and register).
     *
     * @return int
     */
    public function getMode(): int;

    /**
     * @return string
     */
    public function getReason(): string;
}
