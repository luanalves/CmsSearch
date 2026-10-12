<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Exception;

/**
 * The CMS search index could not be written or the search engine is misconfigured. Transient for the reindex handler
 * (retried up to the configured cap, then dead-lettered). Messages never contain editorial content.
 */
class IndexingException extends \RuntimeException
{
}
