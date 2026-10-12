<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermSearchResultsInterface;
use Thedevkitchen\CmsSearch\Exception\ClassificationTermInUseException;

interface ClassificationTermRepositoryInterface
{
    /**
     * Creates or updates a term. A changed parent moves the term: the path and level of the term and of
     * every descendant are recalculated in the same transaction.
     *
     * @throws InputException invalid code, website, parent, labels or sort order; changed code or website;
     *                        cycle; tree too deep
     * @throws AlreadyExistsException code already used in the website
     * @throws NoSuchEntityException the term being updated no longer exists
     * @throws CouldNotSaveException
     */
    public function save(ClassificationTermInterface $term): ClassificationTermInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $termId): ClassificationTermInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getByCode(int $websiteId, string $code): ClassificationTermInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): ClassificationTermSearchResultsInterface;

    /**
     * @throws ClassificationTermInUseException when the term has child terms or linked CMS Pages
     * @throws CouldNotDeleteException
     */
    public function delete(ClassificationTermInterface $term): bool;

    /**
     * @throws NoSuchEntityException
     * @throws ClassificationTermInUseException when the term has child terms or linked CMS Pages
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $termId): bool;
}
