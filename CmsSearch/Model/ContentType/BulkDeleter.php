<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ContentType;

use Magento\Framework\Exception\LocalizedException;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;

class BulkDeleter
{
    public function __construct(private readonly ContentTypeRepositoryInterface $repository)
    {
    }

    /**
     * Deletes the free types and reports, per item, the ones that could not be deleted
     * (linked Pages, already gone, delete failure) without aborting the batch.
     *
     * @param array<int|string> $typeIds
     * @return array{deleted: int, blocked: string[]}
     */
    public function delete(array $typeIds): array
    {
        $deleted = 0;
        $blocked = [];
        foreach (array_unique(array_map('intval', $typeIds)) as $typeId) {
            try {
                $this->repository->deleteById($typeId);
                $deleted++;
            } catch (LocalizedException $exception) {
                $blocked[] = $exception->getMessage();
            }
        }
        return ['deleted' => $deleted, 'blocked' => $blocked];
    }
}
