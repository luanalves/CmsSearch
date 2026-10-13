<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Observer;

use Magento\Banner\Model\ResourceModel\Banner as BannerResource;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\SalesRule\Model\Rule as SalesRule;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ReindexPublisherInterface;
use Thedevkitchen\CmsSearch\Observer\PublishRuleBannerReindex;

class PublishRuleBannerReindexTest extends TestCase
{
    /** @var list<string> */
    private array $published = [];
    /** @var array<string, list<int|string>> "sales#<rule id>" / "catalog#<rule id>" => banner ids linked in the database now */
    private array $links = [];
    private PublishRuleBannerReindex $observer;

    protected function setUp(): void
    {
        $this->observer = $this->observerWith($this->banners());
    }

    private function banners(): BannerResource
    {
        $banners = $this->createMock(BannerResource::class);
        $banners->method('getRelatedBannersBySalesRuleId')->willReturnCallback(fn ($ruleId): array => $this->links['sales#' . $ruleId] ?? []);
        $banners->method('getRelatedBannersByCatalogRuleId')->willReturnCallback(fn ($ruleId): array => $this->links['catalog#' . $ruleId] ?? []);
        return $banners;
    }

    private function observerWith(BannerResource $banners, ?LoggerInterface $logger = null): PublishRuleBannerReindex
    {
        $publisher = $this->createMock(ReindexPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $type, int $id, string $action): void {
            $this->published[] = "$type $id $action";
        });
        return new PublishRuleBannerReindex($publisher, $banners, $logger ?? $this->createMock(LoggerInterface::class));
    }

    private function fire(string $eventName, ?int $ruleId, mixed $relatedBanners = null, ?PublishRuleBannerReindex $observer = null): void
    {
        $rule = $this->createMock(SalesRule::class); // any AbstractModel: the event name tells the rule type
        $rule->method('getId')->willReturn($ruleId);
        $rule->method('getData')->willReturnCallback(static fn ($key = '', $index = null) => $key === 'related_banners' ? $relatedBanners : null);
        $event = new Event(['rule' => $rule, 'data_object' => $rule]);
        $event->setName($eventName);
        ($observer ?? $this->observer)->execute(new Observer(['event' => $event]));
    }

    public function testRelinkingFromTheRuleReindexesTheOldAndTheNewDynamicBlock(): void
    {
        $this->links['sales#5'] = ['3'];
        $this->fire('salesrule_rule_save_before', 5);
        $this->links['sales#5'] = ['4']; // Magento_Banner rebinds from related_banners (before or after us)
        $this->fire('salesrule_rule_save_after', 5, ['4']);
        $this->assertSame(['dynamic_block 3 save', 'dynamic_block 4 save'], $this->published);
    }

    public function testANewRuleReindexesTheDynamicBlocksItLinks(): void
    {
        $this->fire('salesrule_rule_save_before', null);
        $this->links['sales#9'] = [5];
        $this->fire('salesrule_rule_save_after', 9, ['5']);
        $this->assertSame(['dynamic_block 5 save'], $this->published);
    }

    public function testASaveWithoutTheBannerGridStillFindsTheLinksInTheDatabase(): void
    {
        // REST / mass actions / Staging saves carry no related_banners: the links in the database still count
        $this->links['sales#5'] = [7];
        $this->fire('salesrule_rule_save_before', 5);
        $this->fire('salesrule_rule_save_after', 5);
        $this->assertSame(['dynamic_block 7 save'], $this->published);
    }

    public function testTheBannersTheRuleCarriesCountEvenBeforeMagentoBannerBindsThem(): void
    {
        // observer order: ours may run before Magento_Banner's bind, so the database does not hold the link yet
        $this->fire('salesrule_rule_save_before', null);
        $this->fire('salesrule_rule_save_after', 9, ['5', '0', 'x', 6]);
        $this->assertSame(['dynamic_block 5 save', 'dynamic_block 6 save'], $this->published);
    }

    public function testALinkBoundByMagentoBannerBeforeUsIsFoundInTheDatabase(): void
    {
        // a new rule, no related_banners on the model (programmatic save), link already in the database
        $this->fire('salesrule_rule_save_before', null);
        $this->links['sales#9'] = [5];
        $this->fire('salesrule_rule_save_after', 9, null);
        $this->assertSame(['dynamic_block 5 save'], $this->published);
    }

    public function testDeletingARuleReindexesTheDynamicBlocksItWasLinkedTo(): void
    {
        $this->links['sales#5'] = [3, 6];
        $this->fire('salesrule_rule_delete_before', 5);
        unset($this->links['sales#5']); // FK CASCADE
        $this->fire('salesrule_rule_delete_after', 5);
        $this->fire('salesrule_rule_delete_after', 5); // nothing remembered any more
        $this->assertSame(['dynamic_block 3 save', 'dynamic_block 6 save'], $this->published);
    }

    public function testCatalogPriceRulesUseTheirOwnLinks(): void
    {
        $this->links['catalog#2'] = [8];
        $this->links['sales#2'] = [9];
        $this->fire('catalogrule_rule_save_before', 2);
        $this->fire('catalogrule_rule_save_after', 2, []);
        $this->assertSame(['dynamic_block 8 save'], $this->published);
    }

    public function testOtherEventsAndObjectsAreIgnored(): void
    {
        $this->links['sales#5'] = [3];
        $this->fire('salesrule_rule_load_after', 5);
        $event = new Event(['rule' => new DataObject(['id' => 5])]);
        $event->setName('salesrule_rule_save_after');
        $this->observer->execute(new Observer(['event' => $event]));
        $this->assertSame([], $this->published);
    }

    public function testALookupFailureIsLoggedAndTheRuleWriteGoesOn(): void
    {
        $banners = $this->createMock(BannerResource::class);
        $banners->method('getRelatedBannersBySalesRuleId')->willThrowException(new \RuntimeException('deadlock'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('RuntimeException'));
        $observer = $this->observerWith($banners, $logger);
        $this->fire('salesrule_rule_save_after', 5, ['4'], $observer);
        $this->assertSame([], $this->published);
    }
}
