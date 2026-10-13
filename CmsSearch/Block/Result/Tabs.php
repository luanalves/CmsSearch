<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Block\Result;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Thedevkitchen\CmsSearch\Model\Search\ProductCount;

/**
 * Minimal bridge for the tab bar template: the Products count needs the page layout (spec 3.1 Block/Result/Tabs).
 */
class Tabs extends Template
{
    public function __construct(
        Context $context,
        private readonly ProductCount $productCount,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProductCount(bool $productsTabActive): ?int
    {
        return $this->productCount->count($this->getLayout(), $productsTabActive);
    }
}
