<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api;

/**
 * Asks for an asynchronous CMS Search reindex (D10). Never reindexes synchronously and never throws: a queue problem
 * must not break the caller's save (TS-37); it is logged instead.
 *
 * Inside a database transaction the request is held and sent only after the OUTERMOST commit (dropped on rollback),
 * so the consumer never reads uncommitted state; identical requests in one transaction are sent once. Outside a
 * transaction the request is sent immediately.
 */
interface ReindexPublisherInterface
{
    /**
     * @param string $entityType one of ReindexMessageInterface::ENTITY_*
     * @param string $action one of ReindexMessageInterface::ACTION_* allowed for the entity type
     */
    public function publish(
        string $entityType,
        int $entityId,
        string $action,
        ?int $storeId = null,
        ?int $websiteId = null
    ): void;
}
