<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference\Data;

/**
 * "Which hosts of one type" a widget placement means: all of them, some ids, anchor / non-anchor categories, products
 * of some types, the home page, or CMS pages by their layout handle id.
 */
final class HostSelector
{
    public const MODE_ALL = 'all';
    public const MODE_IDS = 'ids';
    public const MODE_ANCHOR = 'anchor';
    public const MODE_NON_ANCHOR = 'non_anchor';
    public const MODE_PRODUCT_TYPE = 'product_type';
    public const MODE_HOME = 'home';
    public const MODE_PAGE_HANDLE = 'page_handle';

    /**
     * @param list<int|string> $values ids (MODE_IDS), product type ids (MODE_PRODUCT_TYPE) or handle ids (MODE_PAGE_HANDLE)
     */
    public function __construct(
        private readonly string $hostType,
        private readonly string $mode,
        private readonly array $values = []
    ) {
    }

    public function getHostType(): string
    {
        return $this->hostType;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * @return list<int|string>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    public function key(): string
    {
        return $this->hostType . ':' . $this->mode . ':' . implode(',', $this->values);
    }
}
