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
 * - <prefix>_save_after: only the SYMMETRIC DIFFERENCE between the remembered set and the after-set (banners linked or
 *   unlinked by this write). After-set = related_banners when it is an array (what Magento_Banner binds, whatever the
 *   observer order), otherwise the links in the database now. A new rule has nothing remembered, so every linked
 *   banner is new. A save that changes no link (coupon usage counters via SalesRule Usage Processor, Staging
 *   re-saves) publishes nothing;
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
        switch ($phase) {
            case 'save_before':
            case 'delete_before':
                if ($ruleId > 0) {
                    // An entry whose *_after never comes (failed write) stays until the request ends: bounded by the
                    // number of rules written in the request and harmless (a later write of the rule overwrites it).
                    $this->before[$prefix . '#' . $ruleId] = $this->lookup($prefix, $ruleId, 'remembered');
                }
                return;
            case 'save_after':
                $remembered = $this->take($prefix, $ruleId);
                $related = $rule->getData('related_banners');
                if (is_array($related)) {
                    $after = self::ids($related);
                } elseif ($ruleId > 0) {
                    $after = $this->lookup($prefix, $ruleId, 'current');
                    if ($after === null) {
                        // cannot diff: ask for every banner known to be linked before the write
                        $this->publishAll($remembered);
                        return;
                    }
                } else {
                    $after = [];
                }
                // Only a link that appeared or disappeared changes a banner's index text; a rule re-saved with the same
                // links (coupon usage counters, Staging re-saves) publishes nothing.
                $ids = array_merge(array_diff($remembered, $after), array_diff($after, $remembered));
                break;
            case 'delete_after':
                $ids = $this->take($prefix, $ruleId);
                break;
            default:
                return;
        }
        $this->publishAll($ids);
    }

    /**
     * @param list<int> $ids
     */
    private function publishAll(array $ids): void
    {
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
     * Never throws: a failing lookup is logged (class only) and yields an empty list for the "remembered" side, null
     * for the "current" side so the caller can still publish what it already knows.
     *
     * @return list<int>|null
     */
    private function lookup(string $prefix, int $ruleId, string $what): ?array
    {
        try {
            return $this->linked($prefix, $ruleId);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                'Dynamic Blocks of %s %d not looked up (%s): %s',
                $prefix,
                $ruleId,
                $what,
                get_class($exception)
            ));
            return $what === 'current' ? null : [];
        }
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
