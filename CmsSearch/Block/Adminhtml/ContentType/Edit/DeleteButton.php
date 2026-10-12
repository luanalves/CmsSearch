<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Block\Adminhtml\ContentType\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\AbstractContentType;

class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $typeId = $this->getTypeId();
        if ($typeId === null || !$this->isAllowed(AbstractContentType::RESOURCE_DELETE)) {
            return [];
        }
        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf(
                'deleteConfirm(%s, %s, %s)',
                json_encode((string) __('Are you sure you want to delete this content type?'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP),
                json_encode($this->getUrl('*/*/delete'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP),
                json_encode(['data' => ['type_id' => $typeId]], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)
            ),
            'sort_order' => 20,
        ];
    }
}
