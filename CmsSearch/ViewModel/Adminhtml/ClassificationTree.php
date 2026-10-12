<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\AbstractClassification;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\Classification\UsageCounter;

/**
 * Data of the server-rendered classification tree (Content > CMS Search > Classification).
 */
class ClassificationTree implements ArgumentInterface
{
    private const ROUTE = 'thedevkitchen_cmssearch/classification/';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly TreeBuilder $treeBuilder,
        private readonly UsageCounter $usageCounter,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function getWebsites(): array
    {
        $websites = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websites[] = ['id' => (int) $website->getId(), 'name' => (string) $website->getName()];
        }
        usort($websites, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $websites;
    }

    public function getSelectedWebsiteId(): int
    {
        $ids = array_column($this->getWebsites(), 'id');
        $requested = (string) $this->request->getParam('website_id');
        if (ctype_digit($requested) && in_array((int) $requested, $ids, true)) {
            return (int) $requested;
        }
        return $ids[0] ?? 0;
    }

    /**
     * @return list<array<string, mixed>> flattened tree rows (see TreeBuilder::flatten()) plus "pages"
     */
    public function getRows(): array
    {
        $websiteId = $this->getSelectedWebsiteId();
        if ($websiteId === 0) {
            return [];
        }
        $rows = $this->treeBuilder->flatten($this->treeBuilder->build($websiteId));
        if (!$rows) {
            return [];
        }
        $counts = $this->usageCounter->countPagesByTerm(array_column($rows, 'term_id'));
        foreach ($rows as &$row) {
            $row['pages'] = $counts[$row['term_id']] ?? 0;
        }
        return $rows;
    }

    public function getSwitchUrl(int $websiteId): string
    {
        return $this->urlBuilder->getUrl(self::ROUTE . 'index', ['website_id' => $websiteId]);
    }

    public function getEditUrl(int $termId): string
    {
        return $this->urlBuilder->getUrl(self::ROUTE . 'edit', ['term_id' => $termId]);
    }

    public function getNewUrl(?int $parentId): string
    {
        $params = ['website_id' => $this->getSelectedWebsiteId()];
        if ($parentId !== null) {
            $params['parent_id'] = $parentId;
        }
        return $this->urlBuilder->getUrl(self::ROUTE . 'new', $params);
    }

    public function canManage(): bool
    {
        return $this->authorization->isAllowed(AbstractClassification::RESOURCE_MANAGE);
    }
}
