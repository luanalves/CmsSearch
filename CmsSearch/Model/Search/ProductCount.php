<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\CatalogSearch\Block\Result;
use Magento\Framework\View\LayoutInterface;
use Psr\Log\LoggerInterface;

/**
 * Counter of the Products tab without disturbing the native listing (plan decision 2):
 * - Products tab: the native result block is rendered first through the layout (its output is cached by element
 *   name and reused where it belongs), then its own count is read: no second product search.
 * - CMS tab (listing and layered navigation removed): the search layer collection is counted (one product search).
 * Never throws: an unknown count is shown without a number.
 *
 * Seam: decision 2 is verified on the real page later (Task 10). If it does not hold, the stated fallback (read
 * search_query.num_results) replaces this class with a di.xml preference; consumers depend on this class only.
 */
class ProductCount
{
    public const RESULT_BLOCK = 'search.result';

    public function __construct(
        private readonly LayerResolver $layerResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function count(LayoutInterface $layout, bool $productsTabActive): ?int
    {
        try {
            if ($productsTabActive) {
                $block = $layout->getBlock(self::RESULT_BLOCK);
                if (!$block instanceof Result) {
                    return null;
                }
                $layout->renderElement(self::RESULT_BLOCK);
                $count = $block->getData('result_count');
                return $count === null ? (int) $block->getResultCount() : (int) $count;
            }
            return (int) $this->layerResolver->get()->getProductCollection()->getSize();
        } catch (\Throwable $exception) {
            try {
                $this->logger->warning(sprintf('Products count unavailable on the search result page (%s).', get_class($exception)));
            } catch (\Throwable) {
                // an unwritable log must not break the page
            }
            return null;
        }
    }
}
