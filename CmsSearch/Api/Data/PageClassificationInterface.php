<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Api\Data;

/**
 * Content type and classification term of a CMS Page, keyed by the stable page_id (D9).
 */
interface PageClassificationInterface
{
    public const PAGE_ID = 'page_id';
    public const TYPE_ID = 'type_id';
    public const TERM_ID = 'term_id';

    public function getPageId(): int;

    public function getTypeId(): int;

    public function getTermId(): int;
}
