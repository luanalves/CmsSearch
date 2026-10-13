<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Exception;

/**
 * The CMS search index cannot answer: the result page falls back to the native Products page (TS-41). Messages
 * never contain the searched text, and no engine exception is chained (its message may quote the query).
 */
class SearchUnavailableException extends \RuntimeException
{
    private bool $missingIndex = false;

    public static function missingIndex(string $alias, ?\Throwable $previous = null): self
    {
        $exception = new self(sprintf('The CMS search index %s does not exist yet.', $alias), 0, $previous);
        $exception->missingIndex = true;
        return $exception;
    }

    /** Expected state of a store view before its first indexed page: not an error, not logged. */
    public function isMissingIndex(): bool
    {
        return $this->missingIndex;
    }
}
