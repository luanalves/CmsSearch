<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Exception;

/**
 * The last allowed attempt of a reindex message failed (or its retry could not be published). It reaches the
 * framework consumer, which rejects the message with requeue=false; the queue's dead-letter exchange moves it to
 * thedevkitchen.cmssearch.reindex.dlq.
 */
final class ReindexFailedException extends \RuntimeException
{
}
