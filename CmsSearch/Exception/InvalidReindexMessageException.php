<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Exception;

/**
 * Permanent failure: the reindex message can never be processed (unreadable payload, unknown entity type...).
 * The consumer handler lets it reach the framework at once, which rejects the message to the dead-letter queue
 * without any retry.
 */
final class InvalidReindexMessageException extends \InvalidArgumentException
{
}
