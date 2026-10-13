<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Observer;

use Magento\Banner\Model\ResourceModel\Banner as BannerResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;

/**
 * Dynamic Blocks linked to a cart or catalog price rule are excluded from the index (BlockSource::isExcluded, Plan 6
 * review decision). Such a link is also edited from the RULE side (Marketing > Cart / Catalog Price Rules > Related
 * Dynamic Blocks, bound by Magento_Banner's adminhtml observers on <prefix>_save_after) and removed with the rule (FK
 * CASCADE); none of that fires a banner event. This observer asks for a dynamic_block/save reindex of every banner whose
 * link may have changed:
 * - <prefix>_save_before / _delete_before: the banner ids linked in the database BEFORE the write are remembered per
 *   rule (an existing rule only);
 * - <prefix>_save_after: those, plus the banners the rule carries (related_banners, when the admin grid sent them),
 *   plus the links in the database now (whatever the observer order with Magento_Banner);
 * - <prefix>_delete_after: the remembered ones (the links went with the rule).
 * Prefixes: salesrule_rule (cart price rules), catalogrule_rule (catalog price rules); both rule models are written
 * through the EntityManager, whose bridge dispatches these model events inside the transaction, so the publisher
 * sends after the outermost COMMIT, merged per banner. Never throws: a lookup failure is logged (class only) and the
 * rule write goes on.
 */
class PublishRuleBannerReindex implements ObserverInterface, ResetAfterRequestInterface
{
    private const PREFIXES = ['salesrule_rule', 'catalogrule_rule'];

    /** @var array<string, list<int>> "<prefix>#<rule id>" => banner ids linked before the write */
    private array $before = [];

    public function __construct(
        private readonly ReindexPublisherInterface $publisher,
        private readonly BannerResource $bannerResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $name = (string) $event->getName();
        $prefix = null;
        foreach (self::PREFIXES as $candidate) {
            if (str_starts_with($name, $candidate . '_')) {
                $prefix = $candidate;
            }
        }
        $rule = $event->getData('rule') ?? $event->getData('data_object');
        if ($prefix === null || !$rule instanceof AbstractModel) {
            return;
        }
        $ruleId = (int) $rule->getId();
        $phase = substr($name, strlen($prefix) + 1);
        try {
            switch ($phase) {
                case 'save_before':
                case 'delete_before':
                    if ($ruleId > 0) {
                        $this->before[$prefix . '#' . $ruleId] = $this->linked($prefix, $ruleId);
                    }
                    return;
                case 'save_after':
                    $ids = array_merge(
                        $this->take($prefix, $ruleId),
                        self::ids($rule->getData('related_banners')),
                        $ruleId > 0 ? $this->linked($prefix, $ruleId) : []
                    );
                    break;
                case 'delete_after':
                    $ids = $this->take($prefix, $ruleId);
                    break;
                default:
                    return;
            }
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                'Dynamic Blocks of %s %d not looked up: %s',
                $prefix,
                $ruleId,
                get_class($exception)
            ));
            return;
        }
        foreach (array_values(array_unique($ids)) as $bannerId) {
            $this->publisher->publish(
                ReindexMessageInterface::ENTITY_DYNAMIC_BLOCK,
                $bannerId,
                ReindexMessageInterface::ACTION_SAVE
            );
        }
    }

    public function _resetState(): void
    {
        $this->before = [];
    }

    /**
     * @return list<int>
     */
    private function linked(string $prefix, int $ruleId): array
    {
        return self::ids($prefix === 'salesrule_rule'
            ? $this->bannerResource->getRelatedBannersBySalesRuleId($ruleId)
            : $this->bannerResource->getRelatedBannersByCatalogRuleId($ruleId));
    }

    /**
     * @return list<int>
     */
    private function take(string $prefix, int $ruleId): array
    {
        $key = $prefix . '#' . $ruleId;
        $ids = $this->before[$key] ?? [];
        unset($this->before[$key]);
        return $ids;
    }

    /**
     * @return list<int> positive ids of a banner id list (the admin grid sends a list of ids)
     */
    private static function ids(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }
}
