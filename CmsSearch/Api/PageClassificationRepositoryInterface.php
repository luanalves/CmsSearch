<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Thedevkitchen\CmsSearch\Api\Data\PageClassificationInterface;

interface PageClassificationRepositoryInterface
{
    /**
     * @throws NoSuchEntityException when the Page has no type/term
     */
    public function getByPageId(int $pageId): PageClassificationInterface;

    /**
     * Replaces the type and term of the Page. Both null removes the link (the Page is then simply not indexed).
     *
     * The caller supplies the Page's store views and is responsible for them being the Page's REAL store views: the
     * ones stored or being saved with the Page (Page::getStores(), see PageWebsites::storeIdsOf()), never values
     * taken from request input independently of the Page, otherwise the website check below can be bypassed.
     *
     * @param int[] $storeIds store views of the Page (0 = All Store Views); the term must belong to one of their websites
     * @throws InputException only one of the two given, unknown or inactive type/term, term of another website
     * @throws CouldNotSaveException
     */
    public function assign(int $pageId, ?int $typeId, ?int $termId, array $storeIds): void;

    /**
     * @throws CouldNotSaveException
     */
    public function unassign(int $pageId): void;
}
