<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Block\Adminhtml\Classification\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\AuthorizationInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\AbstractClassification;

class GenericButton
{
    public function __construct(
        private readonly Context $context,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    public function getTermId(): ?int
    {
        $termId = (string) $this->context->getRequest()->getParam('term_id');
        return ctype_digit($termId) && (int) $termId > 0 ? (int) $termId : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getUrl(string $route = '', array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }

    public function canManage(): bool
    {
        return $this->authorization->isAllowed(AbstractClassification::RESOURCE_MANAGE);
    }
}
