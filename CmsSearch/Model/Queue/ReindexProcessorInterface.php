<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;

/**
 * Does the reindex work for one message. Bound to PageReindexProcessor in etc/di.xml (Plan 4); Plan 6 extends it
 * to blocks, products and categories.
 *
 * Contract for implementations:
 * - idempotent: re-read the CURRENT database state; the same message twice, or messages out of order, must end in
 *   the same index (edit then delete ends removed; delete then re-create ends present);
 * - the action is a hint ("delete" may name a page that still exists, e.g. after a Staging version was removed);
 * - a page link may be stale: intersect the term's website with the page's websites at index time;
 * - throw InvalidReindexMessageException for a request that can never succeed (straight to the DLQ, no retry);
 * - any other exception or PHP error counts as transient: retried up to the configured cap, then dead-lettered.
 */
interface ReindexProcessorInterface
{
    public function process(ReindexMessageInterface $message): void;
}
