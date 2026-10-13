<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\CatalogSearch\Block\Result;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\View\LayoutInterface;
use Psr\Log\LoggerInterface;

/**
 * Counter of the Products tab without disturbing the native listing (plan decision 2):
 * - Products tab: the native result block is rendered first through the layout (its output is cached by element
 *   name and reused where it belongs), then its own count is read: no second product search.
 * - CMS tab (listing and layered navigation removed): the search layer collection is counted (one product search).
 * Never throws: an unknown count is shown without a number.
 * Implements ResetAfterRequestInterface (memo per layout).
 *
 * Seam: decision 2 is verified on the real page later (Task 10). If it does not hold, the stated fallback (read
 * search_query.num_results) replaces this class with a di.xml preference; consumers depend on this class only.
 */
class ProductCount implements ResetAfterRequestInterface
{
    public const RESULT_BLOCK = 'search.result';

    /** Layout of the request the counts below belong to. */
    private ?LayoutInterface $layout = null;
    /** @var array<int, int|null> 1 = Products tab active, 0 = CMS tab => count */
    private array $counts = [];

    public function __construct(
        private readonly LayerResolver $layerResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Single render: the count of a layout is computed once per tab kind, so the native listing is rendered at most
     * once by this module however many times the tab bar asks (the layout also caches the element's HTML).
     */
    public function count(LayoutInterface $layout, bool $productsTabActive): ?int
    {
        if ($this->layout !== $layout) {
            $this->layout = $layout;
            $this->counts = [];
        }
        $key = $productsTabActive ? 1 : 0;
        if (!array_key_exists($key, $this->counts)) {
            $this->counts[$key] = $this->compute($layout, $productsTabActive);
        }
        return $this->counts[$key];
    }

    public function _resetState(): void
    {
        $this->layout = null;
        $this->counts = [];
    }

    private function compute(LayoutInterface $layout, bool $productsTabActive): ?int
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
