<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\ContentTypeState;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\TermState;
use Thedevkitchen\CmsSearch\Model\Indexer\VisibilityChecker as V;

class VisibilityCheckerTest extends TestCase
{
    /** website 2 = "website1" of this install (store views 2, 3, 4); website 3 = "website2" (5, 6, 7) */
    private static function store(int $storeId = 2, int $websiteId = 2): StoreContext
    {
        return new StoreContext($storeId, $websiteId, 's' . $storeId, 'pt_BR', 'https://s.test/', 'https://s.test/media/', 'home');
    }

    /** @param int[] $storeIds */
    private static function page(bool $active = true, array $storeIds = [2]): PageSnapshot
    {
        return new PageSnapshot(5, 'news-1', 'News', '', '<p>x</p>', '', $active, $storeIds, '2026-10-11 10:00:00');
    }

    private static function type(int $id = 3, bool $active = true): ContentTypeState
    {
        return new ContentTypeState($id, 'news', $active);
    }

    private static function term(int $id = 9, bool $effectiveActive = true, int $websiteId = 2): TermState
    {
        return new TermState($id, $websiteId, '1/9', [1, $id], $effectiveActive);
    }

    private const LINK = ['type_id' => 3, 'term_id' => 9];

    public function testAnActiveClassifiedPageOfTheStoreViewIsVisible(): void
    {
        $this->assertSame(V::VISIBLE, (new V())->check(self::page(), self::LINK, self::type(), self::term(), self::store()));
    }

    /** @return array<string, array{0: string, 1: ?PageSnapshot, 2: ?array, 3: ?ContentTypeState, 4: ?TermState}> */
    public static function hiddenPages(): array
    {
        return [
            'page deleted (or not readable)' => [V::PAGE_MISSING, null, self::LINK, self::type(), self::term()],
            'page disabled' => [V::PAGE_INACTIVE, self::page(false), self::LINK, self::type(), self::term()],
            'page assigned to another store view' => [V::NOT_IN_STORE, self::page(true, [3, 4]), self::LINK, self::type(), self::term()],
            'page without type and term (D5)' => [V::NOT_CLASSIFIED, self::page(), null, null, null],
            'type deleted' => [V::TYPE_MISSING, self::page(), self::LINK, null, self::term()],
            'type of another id than the link' => [V::TYPE_MISSING, self::page(), self::LINK, self::type(4), self::term()],
            'type disabled (TS-26)' => [V::TYPE_INACTIVE, self::page(), self::LINK, self::type(3, false), self::term()],
            'term deleted' => [V::TERM_MISSING, self::page(), self::LINK, self::type(), null],
            'term of another id than the link' => [V::TERM_MISSING, self::page(), self::LINK, self::type(), self::term(10)],
            'term or an ancestor disabled' => [V::TERM_INACTIVE, self::page(), self::LINK, self::type(), self::term(9, false)],
            'term of another website (stale link, D6)' => [V::TERM_OTHER_WEBSITE, self::page(), self::LINK, self::type(), self::term(9, true, 3)],
        ];
    }

    #[DataProvider('hiddenPages')]
    public function testEveryRuleHidesThePage(string $reason, ?PageSnapshot $page, ?array $link, ?ContentTypeState $type, ?TermState $term): void
    {
        $this->assertSame($reason, (new V())->check($page, $link, $type, $term, self::store()));
    }

    public function testAPageOfAllStoreViewsIsVisibleInTheStoreViewsOfTheTermsWebsite(): void
    {
        $page = self::page(true, [0]);
        $this->assertSame(V::VISIBLE, (new V())->check($page, self::LINK, self::type(), self::term(), self::store(4, 2)));
    }

    public function testAPageOfAllStoreViewsIsHiddenInAnotherWebsite(): void
    {
        $page = self::page(true, [0]);
        $this->assertSame(V::TERM_OTHER_WEBSITE, (new V())->check($page, self::LINK, self::type(), self::term(), self::store(5, 3)));
    }

    public function testAMissingPageWinsOverEveryOtherReason(): void
    {
        $this->assertSame(V::PAGE_MISSING, (new V())->check(null, null, null, null, self::store()));
    }

    public function testAPageOfAllStoreViewsThatIsDisabledIsHidden(): void
    {
        $this->assertSame(V::PAGE_INACTIVE, (new V())->check(self::page(false, [0]), self::LINK, self::type(), self::term(), self::store()));
    }

    public function testAPageAssignedToAStoreViewOfAnotherWebsiteIsNotInThisStoreView(): void
    {
        $this->assertSame(V::NOT_IN_STORE, (new V())->check(self::page(true, [5]), self::LINK, self::type(), self::term(), self::store(2, 2)));
    }

    public function testAPageAssignedToTheStoreViewIsHiddenWhenTheTermBelongsToAnotherWebsite(): void
    {
        $this->assertSame(V::TERM_OTHER_WEBSITE, (new V())->check(self::page(true, [5]), self::LINK, self::type(), self::term(), self::store(5, 3)));
    }

    public function testAPageAssignedToSeveralStoreViewsIsVisibleInEachOfThem(): void
    {
        $page = self::page(true, [3, 4]);
        $this->assertSame(V::VISIBLE, (new V())->check($page, self::LINK, self::type(), self::term(), self::store(4, 2)));
    }

    public function testAnInactiveTypeWinsOverAnInactiveTerm(): void
    {
        $this->assertSame(V::TYPE_INACTIVE, (new V())->check(self::page(), self::LINK, self::type(3, false), self::term(9, false), self::store()));
    }

    public function testAnInactiveTermWinsOverTheWebsiteMismatch(): void
    {
        $this->assertSame(V::TERM_INACTIVE, (new V())->check(self::page(), self::LINK, self::type(), self::term(9, false, 3), self::store()));
    }

    public function testADisabledPageWinsOverNotBeingInTheStoreView(): void
    {
        $this->assertSame(V::PAGE_INACTIVE, (new V())->check(self::page(false, [3]), null, null, null, self::store()));
    }

    public function testAPageNotInTheStoreViewWinsOverMissingClassification(): void
    {
        $this->assertSame(V::NOT_IN_STORE, (new V())->check(self::page(true, [3]), null, null, null, self::store()));
    }

    public function testALinkWithoutAnyStateIsAMissingTypeNotAClassifiedPage(): void
    {
        $this->assertSame(V::TYPE_MISSING, (new V())->check(self::page(), self::LINK, null, null, self::store()));
    }

    public function testStateWithoutALinkIsNotClassified(): void
    {
        $this->assertSame(V::NOT_CLASSIFIED, (new V())->check(self::page(), null, self::type(), self::term(), self::store()));
    }
}
