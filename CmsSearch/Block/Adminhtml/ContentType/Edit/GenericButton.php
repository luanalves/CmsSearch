<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Block\Adminhtml\ContentType\Edit;

use Magento\Backend\Block\Widget\Context;

class GenericButton
{
    public function __construct(private readonly Context $context)
    {
    }

    public function getTypeId(): ?int
    {
        $typeId = (int) $this->context->getRequest()->getParam('type_id');
        return $typeId > 0 ? $typeId : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getUrl(string $route = '', array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }
}
