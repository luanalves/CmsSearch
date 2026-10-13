<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Support;

use Thedevkitchen\CmsSearch\Model\Indexer\Text\ExtractedContent;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;

/**
 * The real TextExtractor that counts its calls per content and fails, like a PCRE error does, for content containing
 * FAIL_MARKER (the real extractor substitutes invalid UTF-8 instead of throwing, so the failure is simulated).
 */
class CountingTextExtractor extends TextExtractor
{
    public const FAIL_MARKER = 'EXTRACTION-FAILS';

    /** @var array<string, int> content => extract() calls */
    public array $calls = [];

    public function extract(string $html): ExtractedContent
    {
        $this->calls[$html] = ($this->calls[$html] ?? 0) + 1;
        if (str_contains($html, self::FAIL_MARKER)) {
            throw new \UnexpectedValueException('Text extraction failed (regular expression error 4).');
        }
        return parent::extract($html);
    }
}
