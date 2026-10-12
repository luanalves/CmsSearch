<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\PageClassificationInterface;
use Thedevkitchen\CmsSearch\Api\PageClassificationRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\PageClassification\AssignmentValidator;
use Thedevkitchen\CmsSearch\Model\ResourceModel\PageClassification as LinkResource;

/**
 * Single write path of the Page link (Plan 3 hooks its reindex message here).
 */
class PageClassificationRepository implements PageClassificationRepositoryInterface
{
    public function __construct(
        private readonly LinkResource $resource,
        private readonly AssignmentValidator $validator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getByPageId(int $pageId): PageClassificationInterface
    {
        $row = $this->resource->fetchRow($pageId);
        if ($row === null) {
            throw new NoSuchEntityException(__('The CMS page with id "%1" has no search classification.', $pageId));
        }
        return new PageClassification($pageId, $row['type_id'], $row['term_id']);
    }

    public function assign(int $pageId, ?int $typeId, ?int $termId, array $storeIds): void
    {
        if ($pageId <= 0) {
            throw new InputException(__('The CMS page id is not valid.'));
        }
        $row = $this->resource->fetchRow($pageId);
        $current = $row === null ? null : new PageClassification($pageId, $row['type_id'], $row['term_id']);
        $this->validator->validate($typeId, $termId, array_map('intval', $storeIds), $current);

        try {
            if ($typeId === null || $termId === null) { // the validator guarantees both are null here
                $this->resource->remove($pageId);
                return;
            }
            $this->resource->replace($pageId, $typeId, $termId);
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            throw new CouldNotSaveException(__('Could not save the search classification of the page.'), $exception);
        }
    }

    public function unassign(int $pageId): void
    {
        try {
            $this->resource->remove($pageId);
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            throw new CouldNotSaveException(__('Could not save the search classification of the page.'), $exception);
        }
    }
}
