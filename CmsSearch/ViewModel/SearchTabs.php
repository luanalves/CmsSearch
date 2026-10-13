<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\ResultUrl;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;

/**
 * Tab bar of the search result page (role=tablist). Hidden when the module is off for the store view or when only
 * the native Products tab exists (index unavailable, no active content type).
 */
class SearchTabs implements ArgumentInterface
{
    public const PANEL_ID = 'cmssearch-panel';

    public function __construct(
        private readonly SearchState $state,
        private readonly ResultUrl $url
    ) {
    }

    public static function tabId(string $code): string
    {
        return 'cmssearch-tab-' . $code;
    }

    public function isVisible(): bool
    {
        return $this->state->isEnabled() && count($this->state->getTabs()) > 1;
    }

    public function isProductsTabActive(): bool
    {
        return !$this->state->isCmsTabActive();
    }

    public function getPanelId(): string
    {
        return self::PANEL_ID;
    }

    public function getActiveTabId(): string
    {
        return self::tabId($this->state->getActiveTab()->getCode());
    }

    /**
     * @param int|null $productCount count of the Products tab (read by the block from the layout)
     * @return list<array{code: string, label: string, count: ?int, url: string, active: bool, id: string}>
     */
    public function getTabs(?int $productCount): array
    {
        $active = $this->state->getActiveTab()->getCode();
        $tabs = [];
        foreach ($this->state->getTabs() as $tab) {
            $tabs[] = [
                'code' => $tab->getCode(),
                'label' => $tab->getLabel(),
                'count' => $tab->getKind() === Tab::KIND_PRODUCTS ? $productCount : $tab->getCount(),
                'url' => $this->url->build($this->state->getQueryText(), $tab->getCode()),
                'active' => $tab->getCode() === $active,
                'id' => self::tabId($tab->getCode()),
            ];
        }
        return $tabs;
    }
}
