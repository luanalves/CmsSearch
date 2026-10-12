<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Text;

final class ExtractedContent
{
    /**
     * @param list<Directive> $directives
     */
    public function __construct(
        private readonly string $text,
        private readonly array $directives,
        private readonly ?string $firstImage,
        private readonly bool $truncated = false
    ) {
    }

    /** Plain text, whitespace normalized, directives removed. */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return list<Directive> in order of appearance, duplicates removed
     */
    public function getDirectives(): array
    {
        return $this->directives;
    }

    /** Raw src of the first visible <img> of the content (may be a {{media}} directive). */
    public function getFirstImage(): ?string
    {
        return $this->firstImage;
    }

    /** True when the input exceeded TextExtractor::MAX_INPUT_BYTES or the text exceeded TextExtractor::MAX_TEXT_CHARS. */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }
}
