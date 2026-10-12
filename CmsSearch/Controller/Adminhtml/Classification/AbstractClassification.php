<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification;

use Magento\Backend\App\Action;

abstract class AbstractClassification extends Action
{
    public const RESOURCE_VIEW = 'Thedevkitchen_CmsSearch::classification';
    public const RESOURCE_MANAGE = 'Thedevkitchen_CmsSearch::classification_manage';

    public const ADMIN_RESOURCE = self::RESOURCE_VIEW;
}
