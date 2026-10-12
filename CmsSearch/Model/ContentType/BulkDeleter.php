<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ContentType;

use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Exception\ContentTypeInUseException;

class BulkDeleter
{
    public function __construct(private readonly ContentTypeRepositoryInterface $repository)
    {
    }

    /**
     * Deletes the free types and reports the ones blocked by linked Pages.
     *
     * @param array<int|string> $typeIds
     * @return array{deleted: int, blocked: string[]}
     */
    public function delete(array $typeIds): array
    {
        $deleted = 0;
        $blocked = [];
        foreach ($typeIds as $typeId) {
            try {
                $this->repository->deleteById((int) $typeId);
                $deleted++;
            } catch (ContentTypeInUseException $exception) {
                $blocked[] = $exception->getMessage();
            }
        }
        return ['deleted' => $deleted, 'blocked' => $blocked];
    }
}
