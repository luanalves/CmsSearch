<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Block\Adminhtml\Classification\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    private const JSON = JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;

    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $termId = $this->getTermId();
        if ($termId === null || !$this->canManage()) {
            return [];
        }
        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf(
                'deleteConfirm(%s, %s, %s)',
                json_encode((string) __('Are you sure you want to delete this classification term?'), self::JSON),
                json_encode($this->getUrl('*/*/delete'), self::JSON),
                json_encode(['data' => ['term_id' => $termId]], self::JSON)
            ),
            'sort_order' => 20,
        ];
    }
}
