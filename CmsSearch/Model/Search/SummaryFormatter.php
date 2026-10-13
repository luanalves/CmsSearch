<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Search;

/**
 * Display summary of a card: whitespace collapsed, cut at a word boundary (multibyte safe) with an ellipsis.
 */
class SummaryFormatter
{
    public const ELLIPSIS = '…';

    public function format(string $summary, int $length): string
    {
        if ($length < 1) {
            return '';
        }
        if (!mb_check_encoding($summary, 'UTF-8')) {
            return '';
        }
        // drop control characters (NUL...) that are not whitespace, then collapse every whitespace run
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\P{Cc}\s]/u', '', $summary)));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= intdiv($length, 2)) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, " \t,;:.-") . self::ELLIPSIS;
    }
}
