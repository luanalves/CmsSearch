<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType;

use Magento\Backend\App\Action;

abstract class AbstractContentType extends Action
{
    public const RESOURCE_VIEW = 'Thedevkitchen_CmsSearch::content_types';
    public const RESOURCE_CREATE = 'Thedevkitchen_CmsSearch::content_types_create';
    public const RESOURCE_EDIT = 'Thedevkitchen_CmsSearch::content_types_edit';
    public const RESOURCE_DELETE = 'Thedevkitchen_CmsSearch::content_types_delete';

    public const ADMIN_RESOURCE = self::RESOURCE_VIEW;
}
