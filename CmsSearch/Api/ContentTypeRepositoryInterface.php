<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeSearchResultsInterface;
use Thedevkitchen\CmsSearch\Exception\ContentTypeInUseException;

interface ContentTypeRepositoryInterface
{
    /**
     * @throws InputException invalid code, changed code or missing default label
     * @throws AlreadyExistsException code already used
     * @throws CouldNotSaveException
     */
    public function save(ContentTypeInterface $type): ContentTypeInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $typeId): ContentTypeInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getByCode(string $code): ContentTypeInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): ContentTypeSearchResultsInterface;

    /**
     * @throws ContentTypeInUseException when CMS Pages are linked to the type
     * @throws CouldNotDeleteException
     */
    public function delete(ContentTypeInterface $type): bool;

    /**
     * @throws NoSuchEntityException
     * @throws ContentTypeInUseException when CMS Pages are linked to the type
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $typeId): bool;
}
