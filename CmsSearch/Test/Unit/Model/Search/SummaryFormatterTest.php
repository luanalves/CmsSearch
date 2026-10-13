<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Search\SummaryFormatter;

class SummaryFormatterTest extends TestCase
{
    public function testAShortSummaryIsKeptAndAZeroLengthGivesNothing(): void
    {
        $this->assertSame('Short text.', (new SummaryFormatter())->format('Short text.', 200));
        $this->assertSame('', (new SummaryFormatter())->format('Short text.', 0));
    }

    public function testWhitespaceIsCollapsed(): void
    {
        $this->assertSame('Line one line two', (new SummaryFormatter())->format("  Line one\n\n  line   two ", 200));
    }

    public function testALongSummaryIsCutAtAWordBoundary(): void
    {
        $this->assertSame(
            'The quick brown fox…',
            (new SummaryFormatter())->format('The quick brown fox jumps over the lazy dog', 20)
        );
    }

    public function testASingleLongWordIsCutHard(): void
    {
        $this->assertSame(str_repeat('a', 10) . '…', (new SummaryFormatter())->format(str_repeat('a', 30), 10));
    }

    public function testMultibyteCharactersAreNeverSplit(): void
    {
        $this->assertSame('Informações…', (new SummaryFormatter())->format('Informações técnicas completas', 12));
    }

    public function testTheWordBoundaryIsAFindInCharactersNotBytes(): void
    {
        $this->assertSame('ãã ãã…', (new SummaryFormatter())->format('ãã ãã ãããã ãã', 9));
    }

    public function testTrailingPunctuationIsDroppedBeforeTheEllipsis(): void
    {
        $this->assertSame('Hello…', (new SummaryFormatter())->format('Hello, world and more', 6));
    }

    public function testNegativeLengthGivesNothing(): void
    {
        $this->assertSame('', (new SummaryFormatter())->format('Short text.', -5));
    }

    public function testTextExactlyAsLongAsTheLimitIsKept(): void
    {
        $this->assertSame('abcdefghij', (new SummaryFormatter())->format('abcdefghij', 10));
        $this->assertSame('abcde…', (new SummaryFormatter())->format('abcdefghijk', 5));
    }

    public function testMultibyteTextExactlyAsLongAsTheLimitIsKept(): void
    {
        $this->assertSame('ãéíõü', (new SummaryFormatter())->format('ãéíõü', 5));
        $this->assertSame('ãéíõ…', (new SummaryFormatter())->format('ãéíõüç', 4));
    }

    public function testFourByteCharactersAreNeverSplit(): void
    {
        $text = str_repeat("\u{1F600}", 8);
        $out = (new SummaryFormatter())->format($text, 3);
        $this->assertSame(str_repeat("\u{1F600}", 3) . '…', $out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function testNoBreakAndIdeographicSpacesAreCollapsed(): void
    {
        $this->assertSame('a b c', (new SummaryFormatter())->format("a\u{00A0}\u{00A0}b\u{3000}c", 50));
    }

    public function testControlCharactersAreRemoved(): void
    {
        $this->assertSame('a b', (new SummaryFormatter())->format("a\0\x07 b", 50));
        $this->assertSame('ab', (new SummaryFormatter())->format("a\0b", 50));
    }

    public function testInvalidUtf8GivesAValidString(): void
    {
        $this->assertSame('', (new SummaryFormatter())->format("ok \xff\xfe broken", 50));
        $this->assertSame('', (new SummaryFormatter())->format("\xff\xfe", 50));
    }

    public function testMarkupIsKeptAsPlainTextForTheTemplateToEscape(): void
    {
        $this->assertSame('a <b> & "c"', (new SummaryFormatter())->format('a <b> & "c"', 50));
    }

    public function testTheCutNeverLeavesTrailingSpace(): void
    {
        $this->assertSame('abcd…', (new SummaryFormatter())->format('abcd efgh', 5));
    }

    public function testEarlyWordBoundaryBelowHalfIsIgnored(): void
    {
        // the only space is before half the limit: hard cut keeps more text
        $this->assertSame('ab cdefghijklmnopq…', (new SummaryFormatter())->format('ab cdefghijklmnopqrstuvwxyz', 18));
    }

    public function testTheResultNeverExceedsTheLimitPlusTheEllipsis(): void
    {
        foreach ([1, 2, 7, 50, 199] as $length) {
            $out = (new SummaryFormatter())->format(str_repeat('palavra longa ', 100), $length);
            $this->assertLessThanOrEqual($length + 1, mb_strlen($out));
        }
    }

    public function testASummaryOfOnlyWhitespaceIsEmpty(): void
    {
        $this->assertSame('', (new SummaryFormatter())->format(" \n\t ", 50));
    }
}
