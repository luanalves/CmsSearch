<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Block\Adminhtml\ContentType\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $typeId = $this->getTypeId();
        if ($typeId === null) {
            return [];
        }
        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', %s)",
                __('Are you sure you want to delete this content type?'),
                $this->getUrl('*/*/delete'),
                json_encode(['data' => ['type_id' => $typeId]])
            ),
            'sort_order' => 20,
        ];
    }
}
