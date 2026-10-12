<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Block\Adminhtml\ContentType\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\AuthorizationInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\AbstractContentType;

class GenericButton
{
    public function __construct(
        private readonly Context $context,
        private readonly AuthorizationInterface $authorization
    )
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

    public function isAllowed(string $resource): bool
    {
        return $this->authorization->isAllowed($resource);
    }

    /**
     * Saving needs the create right for a new type and the edit right for an existing one.
     */
    public function canSave(): bool
    {
        return $this->isAllowed(
            $this->getTypeId() === null ? AbstractContentType::RESOURCE_CREATE : AbstractContentType::RESOURCE_EDIT
        );
    }
}
