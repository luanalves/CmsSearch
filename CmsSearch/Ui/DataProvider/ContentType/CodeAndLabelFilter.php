<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Ui\DataProvider\ContentType;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\View\Element\UiComponent\DataProvider\FilterApplierInterface;

/**
 * Keyword search of the listing: matches the code or the default label.
 */
class CodeAndLabelFilter implements FilterApplierInterface
{
    public function apply(\Magento\Framework\Data\Collection $collection, Filter $filter): void
    {
        $value = trim((string) $filter->getValue(), "% \t\n\r");
        if ($value === '' || !$collection instanceof AbstractDb) {
            return;
        }
        // Escape LIKE wildcards so the keyword is matched literally.
        $like = $collection->getConnection()->quote('%' . addcslashes($value, '\\%_') . '%');
        $collection->getSelect()->where(sprintf('(main_table.code LIKE %1$s OR lbl.label LIKE %1$s)', $like));
    }
}
