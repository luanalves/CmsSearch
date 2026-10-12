<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;

/**
 * Plan 3 placeholder: accepts every message and does nothing (no index exists yet). The handler logs each
 * processed message, which is what the queue tests observe.
 */
class NullReindexProcessor implements ReindexProcessorInterface
{
    public function process(ReindexMessageInterface $message): void
    {
    }
}
