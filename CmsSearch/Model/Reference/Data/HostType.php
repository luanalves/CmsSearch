<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * The only three hosts that publish block content on the storefront (D3).
 */
final class HostType
{
    public const PAGE = 'page';
    public const CATEGORY = 'category';
    public const PRODUCT = 'product';
    /** Processing order: CMS documents first, then product block text. */
    public const ALL = [self::PAGE, self::CATEGORY, self::PRODUCT];
}
