<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ContentType;

use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;

class DeleteGuard
{
    public function assertDeletable(ContentTypeInterface $type): void
    {
    }
}
