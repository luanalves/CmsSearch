<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model;

use Thedevkitchen\CmsSearch\Api\Data\PageClassificationInterface;

/**
 * Immutable value object of a Page link.
 */
final class PageClassification implements PageClassificationInterface
{
    public function __construct(
        private readonly int $pageId,
        private readonly int $typeId,
        private readonly int $termId
    ) {
    }

    public function getPageId(): int
    {
        return $this->pageId;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getTermId(): int
    {
        return $this->termId;
    }
}
