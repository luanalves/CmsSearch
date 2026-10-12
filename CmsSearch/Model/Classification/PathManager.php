<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Classification;

use Magento\Framework\Exception\InputException;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;

/**
 * Owns the materialized path ("1/4/9") and level of classification terms.
 */
class PathManager
{
    /** Deepest level allowed (0 = root): 10 levels in total. */
    public const MAX_LEVEL = 9;
    public const MAX_PATH_LENGTH = 255;

    public function __construct(private readonly TermResource $resource)
    {
    }

    /**
     * Places the term under $parentId (null = root) and rewrites the path and level of the term and of all
     * of its descendants. Must run inside the caller's transaction.
     *
     * Known limitation: no row locking (FOR UPDATE), so concurrent moves are not serialized; this is an
     * admin-only, low-frequency operation.
     *
     * @param string $oldPath stored path before the change, '' for a term that was just inserted
     * @return array{path: string, level: int} the new path and level of the term
     * @throws InputException missing parent, or a result deeper or longer than allowed (nothing is written)
     */
    public function relocate(int $termId, ?int $parentId, string $oldPath): array
    {
        if ($parentId === null) {
            $newPath = (string) $termId;
            $newLevel = 0;
        } else {
            $parent = $this->resource->getStoredState($parentId);
            if ($parent === null) {
                throw new InputException(__('The parent term does not exist.'));
            }
            if ($oldPath !== '' && ($parent['path'] === $oldPath || str_starts_with($parent['path'], $oldPath . '/'))) {
                throw new InputException(__('A term cannot be moved under itself or under one of its descendants.'));
            }
            $newPath = $parent['path'] . '/' . $termId;
            $newLevel = $parent['level'] + 1;
        }

        if ($oldPath === '') {
            $updates = [$termId => ['path' => $newPath, 'level' => $newLevel]];
        } else {
            $rows = $this->resource->fetchSubtree($oldPath);
            $ownLevel = null;
            foreach ($rows as $row) {
                if ($row['term_id'] === $termId) {
                    $ownLevel = $row['level'];
                }
            }
            if ($ownLevel === null) {
                throw new \LogicException(sprintf('Term %d is not stored with path "%s".', $termId, $oldPath));
            }
            $updates = $this->rebase($rows, $oldPath, $newPath, $newLevel - $ownLevel);
        }

        $this->assertWithinLimits($updates);
        foreach ($updates as $id => $update) {
            $this->resource->updatePathLevel($id, $update['path'], $update['level']);
        }
        return $updates[$termId];
    }

    /**
     * Pure path arithmetic: replaces the $oldPath prefix of every row by $newPath and shifts the level.
     *
     * @param list<array{term_id:int, path:string, level:int}> $rows
     * @return array<int, array{path: string, level: int}> keyed by term id
     */
    public function rebase(array $rows, string $oldPath, string $newPath, int $levelDelta): array
    {
        $result = [];
        foreach ($rows as $row) {
            $path = $row['path'];
            if ($path !== $oldPath && !str_starts_with($path, $oldPath . '/')) {
                throw new \LogicException(sprintf('Path "%s" is outside the subtree "%s".', $path, $oldPath));
            }
            $result[(int) $row['term_id']] = [
                'path' => $newPath . substr($path, strlen($oldPath)),
                'level' => (int) $row['level'] + $levelDelta,
            ];
        }
        return $result;
    }

    /**
     * @param array<int, array{path: string, level: int}> $updates
     */
    private function assertWithinLimits(array $updates): void
    {
        foreach ($updates as $update) {
            if ($update['level'] > self::MAX_LEVEL) {
                throw new InputException(
                    __('The classification tree can have at most %1 levels.', self::MAX_LEVEL + 1)
                );
            }
            if (strlen($update['path']) > self::MAX_PATH_LENGTH) {
                throw new InputException(__('The classification tree is too deep for this move.'));
            }
        }
    }
}
