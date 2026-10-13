<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Config\Backend;

use Thedevkitchen\CmsSearch\Model\Config;

class PageSize extends BoundedInteger
{
    protected function minimum(): int
    {
        return Config::MIN_PAGE_SIZE;
    }

    protected function maximum(): int
    {
        return Config::MAX_PAGE_SIZE;
    }

    protected function fieldLabel(): string
    {
        return (string) __('Results per Page');
    }
}
