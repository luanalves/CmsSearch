<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;

/**
 * Request parameters of the search result page (D11: ?q=&tab=&cls=&p=). Anything unexpected falls back silently:
 * unknown tab -> Products, invalid term -> no filter, invalid page -> 1 (TS-39, never a 500).
 */
class TabResolver
{
    public const PRODUCTS = 'produtos';
    public const CATEGORIES = 'categorias';
    public const PARAM_TAB = 'tab';
    public const PARAM_TERM = 'cls';
    public const PARAM_PAGE = 'p';
    /** OpenSearch index.max_result_window (from + size). */
    public const MAX_RESULT_WINDOW = 10000;

    /** \z, not $: a trailing newline must not pass. */
    private const CODE_PATTERN = '/^[a-z0-9_]{1,64}\z/';

    public function readTab(mixed $raw): string
    {
        return is_string($raw) && preg_match(self::CODE_PATTERN, $raw) === 1 ? $raw : self::PRODUCTS;
    }

    public function readTermCode(mixed $raw): ?string
    {
        return is_string($raw) && preg_match(self::CODE_PATTERN, $raw) === 1 ? $raw : null;
    }

    public function readPage(mixed $raw): int
    {
        return is_string($raw) && preg_match('/^[1-9][0-9]{0,3}\z/', $raw) === 1 ? (int) $raw : 1;
    }

    /** The fixed tabs win: a content type with one of these codes never gets a tab. */
    public function isReserved(string $code): bool
    {
        return $code === self::PRODUCTS || $code === self::CATEGORIES;
    }

    public function clampPage(int $page, int $pageSize): int
    {
        $last = max(1, intdiv(self::MAX_RESULT_WINDOW, max(1, $pageSize)));
        return min(max(1, $page), $last);
    }

    /**
     * @param list<Tab> $tabs the available tabs, the products tab included
     */
    public function resolve(string $requested, array $tabs): Tab
    {
        $products = null;
        $match = null;
        foreach ($tabs as $tab) {
            if ($products === null && $tab->getKind() === Tab::KIND_PRODUCTS) {
                $products = $tab;
            }
            if ($match === null && $tab->getCode() === $requested) {
                $match = $tab;
            }
        }
        if ($products === null) {
            throw new \InvalidArgumentException('The tab list must contain the products tab.');
        }
        return $match ?? $products;
    }
}
