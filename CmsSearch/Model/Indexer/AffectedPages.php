<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Model\Db\Tables;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;

/**
 * The CMS pages whose documents a reindex message may change, from the CURRENT database state (the message carries no
 * list): a page (any action, Staging included) -> itself; a content type -> its linked pages; a classification term ->
 * the pages linked to any term of its current subtree (moves and deactivations affect the whole branch; one message
 * per moved term, Plan 3). A missing type or term is not an error (nothing references it): an empty result.
 *
 * Page ids are yielded in ascending chunks of at most $batchSize (keyset pagination on the page_id primary key of the
 * link table), so memory stays bounded however many pages a type or a branch has. The subtree is a subquery on the
 * term path ("path = ? OR path LIKE 'path/%'": the trailing slash keeps 1/4 apart from 1/40), never an id list. Store
 * view and website scoping belong to the caller; this class only answers "which pages".
 */
class AffectedPages
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly TermResource $termResource,
        private readonly int $batchSize = 1000
    ) {
    }

    /**
     * @return iterable<list<int>> ascending, unique, non-empty chunks of page ids
     * @throws InvalidReindexMessageException for an entity type this indexer can never process (thrown here, not on
     *     the first iteration)
     */
    public function resolve(ReindexMessageInterface $message): iterable
    {
        $id = $message->getEntityId();
        return match ($message->getEntityType()) {
            ReindexMessageInterface::ENTITY_PAGE => (static function () use ($id): \Generator {
                yield [$id];
            })(),
            ReindexMessageInterface::ENTITY_CONTENT_TYPE => $this->pagesOfType($id),
            ReindexMessageInterface::ENTITY_CLASSIFICATION_TERM => $this->pagesOfSubtree($id),
            default => throw new InvalidReindexMessageException(sprintf(
                'The CMS page indexer cannot process entity type "%s".',
                substr($message->getEntityType(), 0, 40)
            )),
        };
    }

    /**
     * @return \Generator<int, list<int>>
     */
    private function pagesOfType(int $typeId): \Generator
    {
        $connection = $this->resource->getConnection();
        yield from $this->keyset(
            fn (): Select => $connection->select()
                ->from($this->resource->getTableName(Tables::PAGE_CLASSIFICATION), 'page_id')
                ->where('type_id = ?', $typeId)
        );
    }

    /**
     * @return \Generator<int, list<int>>
     */
    private function pagesOfSubtree(int $termId): \Generator
    {
        $term = $this->termResource->getStoredState($termId);
        if ($term === null) {
            return; // deleted: a term with linked pages cannot be deleted, so no document references it
        }
        $connection = $this->resource->getConnection();
        $path = $term['path'];
        yield from $this->keyset(
            fn (): Select => $connection->select()
                ->from($this->resource->getTableName(Tables::PAGE_CLASSIFICATION), 'page_id')
                ->where('term_id IN (?)', $connection->select()
                    ->from($this->resource->getTableName(Tables::CLASSIFICATION_TERM), 'term_id')
                    ->where('path = ?', $path)
                    // paths contain only digits and '/': no LIKE wildcard escaping needed
                    ->orWhere('path LIKE ?', $path . '/%'))
        );
    }

    /**
     * @param callable(): Select $filtered a fresh select of page_id already filtered
     * @return \Generator<int, list<int>>
     */
    private function keyset(callable $filtered): \Generator
    {
        $batch = max(1, $this->batchSize);
        $connection = $this->resource->getConnection();
        $cursor = 0;
        do {
            $pageIds = array_map('intval', $connection->fetchCol(
                $filtered()->where('page_id > ?', $cursor)->order('page_id ASC')->limit($batch)
            ));
            if ($pageIds === []) {
                return;
            }
            yield $pageIds;
            $cursor = (int) end($pageIds);
        } while (count($pageIds) === $batch);
    }
}
