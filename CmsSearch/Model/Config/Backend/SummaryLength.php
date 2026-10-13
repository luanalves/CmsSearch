<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Config\Backend;

use Thedevkitchen\CmsSearch\Model\Config;

class SummaryLength extends BoundedInteger
{
    protected function minimum(): int
    {
        return Config::MIN_SUMMARY_LENGTH;
    }

    protected function maximum(): int
    {
        return Config::MAX_SUMMARY_LENGTH;
    }

    protected function fieldLabel(): string
    {
        return (string) __('Summary Length');
    }
}
