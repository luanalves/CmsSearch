<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Search\Helper\Data as SearchHelper;
use Magento\Search\Model\QueryFactoryInterface;
use Magento\Search\Model\QueryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\CmsSearchQueryInterface;
use Thedevkitchen\CmsSearch\Exception\SearchUnavailableException;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Search\ClassificationTerms;
use Thedevkitchen\CmsSearch\Model\Search\ContentTypeTabs;
use Thedevkitchen\CmsSearch\Model\Search\Data\CmsResult;
use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;
use Thedevkitchen\CmsSearch\Model\Search\SearchState;
use Thedevkitchen\CmsSearch\Model\Search\TabResolver;

class SearchStateTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $params = [];
    private string $queryText = 'milho';
    private bool $enabled = true;
    private bool $tooShort = false;
    private int $pageSize = 12;
    /** @var list<SearchRequest> */
    private array $requests = [];
    private ?\Throwable $failure = null;
    private CmsResult $result;
    private ContentTypeTabs&MockObject $types;
    private ClassificationTerms&MockObject $terms;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->result = new CmsResult(['news' => 3, 'help' => 0], 0, false, 3, [], [1 => 2]);
        $this->types = $this->createMock(ContentTypeTabs::class);
        $this->types->method('forStore')->willReturn([['code' => 'news', 'label' => 'News'], ['code' => 'help', 'label' => 'Help']]);
        $this->terms = $this->createMock(ClassificationTerms::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function state(): SearchState
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $this->params[$key] ?? null);
        $query = $this->createMock(QueryInterface::class);
        $query->method('getQueryText')->willReturnCallback(fn () => $this->queryText);
        $factory = $this->createMock(QueryFactoryInterface::class);
        $factory->method('get')->willReturn($query);
        $helper = $this->createMock(SearchHelper::class);
        $helper->method('isMinQueryLength')->willReturnCallback(fn (): bool => $this->tooShort);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn('2');
        $store->method('getWebsiteId')->willReturn('2');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn (int $storeId): bool => $this->enabled && $storeId === 2);
        $config->method('getPageSize')->willReturnCallback(fn (): int => $this->pageSize);
        $config->method('getProductsLabel')->willReturn('Produtos');
        $config->method('getCategoriesLabel')->willReturn('Categorias');
        $search = $this->createMock(CmsSearchQueryInterface::class);
        $search->method('search')->willReturnCallback(function (SearchRequest $request): CmsResult {
            $this->requests[] = $request;
            if ($this->failure !== null) {
                throw $this->failure;
            }
            return $this->result;
        });
        return new SearchState(
            $request,
            $factory,
            $helper,
            $stores,
            $config,
            new TabResolver(),
            $this->types,
            $this->terms,
            $search,
            $this->logger
        );
    }

    /** @param list<Tab> $tabs */
    private function codes(array $tabs): array
    {
        return array_map(static fn (Tab $tab): string => $tab->getCode() . ':' . ($tab->getCount() ?? '-'), $tabs);
    }

    private function lastRequest(): SearchRequest
    {
        $this->assertNotEmpty($this->requests, 'the CMS index was not queried');
        return $this->requests[array_key_last($this->requests)];
    }

    public function testTheModuleSwitchGivesTheNativePage(): void
    {
        $this->enabled = false;
        $state = $this->state();
        $this->assertFalse($state->isEnabled());
        $this->assertSame([], $state->getTabs());
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
        $this->assertFalse($state->isCmsTabActive());
        $this->assertSame([], $this->requests);
    }

    public function testAnEmptyQueryGivesTheNativePage(): void
    {
        $this->queryText = '   ';
        $state = $this->state();
        $this->assertFalse($state->isEnabled());
        $this->assertSame([], $this->requests);
    }

    public function testATooShortQueryGivesTheNativePage(): void
    {
        $this->tooShort = true;
        $this->assertFalse($this->state()->isEnabled());
        $this->assertSame([], $this->requests);
    }

    public function testTheProductsTabIsTheDefaultAndOnlyTheCountersAreRequested(): void
    {
        $state = $this->state();
        $this->assertTrue($state->isEnabled());
        $this->assertSame(['produtos:-', 'news:3', 'help:0'], $this->codes($state->getTabs()));
        $this->assertSame(['Produtos', 'News', 'Help'], array_map(static fn (Tab $t): string => $t->getLabel(), $state->getTabs()));
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
        $request = $this->lastRequest();
        $this->assertSame(
            [Tab::KIND_PRODUCTS, null, null, 1, 12, 'milho', 2, 2],
            [$request->getActiveKind(), $request->getContentTypeCode(), $request->getTermId(), $request->getPage(),
                $request->getPageSize(), $request->getQueryText(), $request->getStoreId(), $request->getWebsiteId()]
        );
    }

    public function testACmsTabAsksForItsPageAndItsTerm(): void
    {
        $this->params = ['tab' => 'news', 'cls' => 'agro', 'p' => '2'];
        $this->terms->expects($this->once())->method('findByCode')->with(2, 2, 'agro')
            ->willReturn(['term_id' => 1, 'code' => 'agro', 'label' => 'Agro']);
        $state = $this->state();
        $this->assertSame('news', $state->getActiveTab()->getCode());
        $this->assertTrue($state->isCmsTabActive());
        $this->assertSame(['term_id' => 1, 'code' => 'agro', 'label' => 'Agro'], $state->getSelectedTerm());
        $this->assertSame(2, $state->getPage());
        $request = $this->lastRequest();
        $this->assertSame([Tab::KIND_CONTENT, 'news', 1, 2], [$request->getActiveKind(), $request->getContentTypeCode(), $request->getTermId(), $request->getPage()]);
        $this->assertSame($this->result, $state->getResult());
    }

    public function testAnUnknownTabFallsBackToProducts(): void
    {
        $this->params = ['tab' => 'nope'];
        $state = $this->state();
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
        $this->assertSame(Tab::KIND_PRODUCTS, $this->lastRequest()->getActiveKind());
    }

    public function testTheTermIsIgnoredOutsideContentTabs(): void
    {
        $this->params = ['tab' => 'produtos', 'cls' => 'agro', 'p' => '3'];
        $this->terms->expects($this->never())->method('findByCode');
        $state = $this->state();
        $this->assertNull($state->getSelectedTerm());
        $this->assertSame(1, $state->getPage());
        $this->assertSame([], $state->getTermTree());
    }

    public function testAnUnknownInactiveOrForeignTermMeansNoFilter(): void
    {
        $this->params = ['tab' => 'news', 'cls' => 'soja'];
        $this->terms->method('findByCode')->willReturn(null);
        $state = $this->state();
        $this->assertNull($state->getSelectedTerm());
        $this->assertNull($this->lastRequest()->getTermId());
        $this->assertSame('news', $state->getActiveTab()->getCode());
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function pages(): array
    {
        return ['third' => ['3', 3], 'text' => ['abc', 1], 'past the result window' => ['9999', 833]];
    }

    #[DataProvider('pages')]
    public function testThePageIsSanitizedAndClamped(string $raw, int $expected): void
    {
        $this->params = ['tab' => 'news', 'p' => $raw];
        $this->assertSame($expected, $this->state()->getPage());
        $this->assertSame($expected, $this->lastRequest()->getPage());
    }

    public function testTheCategoriesTabAppearsWhenCategoryDocumentsExist(): void
    {
        $this->result = new CmsResult(['news' => 3], 4, true, 4, [], []);
        $this->params = ['tab' => 'categorias'];
        $state = $this->state();
        $this->assertSame(['produtos:-', 'categorias:4', 'news:3', 'help:0'], $this->codes($state->getTabs()));
        $this->assertSame('categorias', $state->getActiveTab()->getCode());
        $this->assertSame(Tab::KIND_CATEGORIES, $this->lastRequest()->getActiveKind());
    }

    public function testWithoutCategoryDocumentsTheCategoriesTabFallsBackToProducts(): void
    {
        $this->params = ['tab' => 'categorias'];
        $state = $this->state();
        $this->assertSame(['produtos:-', 'news:3', 'help:0'], $this->codes($state->getTabs()));
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
    }

    public function testAnIndexFailureLeavesOnlyTheProductsTabAndIsLogged(): void
    {
        $this->params = ['tab' => 'news', 'cls' => 'agro', 'p' => '3'];
        $this->terms->method('findByCode')->willReturn(['term_id' => 1, 'code' => 'agro', 'label' => 'Agro']);
        $this->failure = new SearchUnavailableException('CMS search request on magento2_thedevkitchen_cmssearch_2 failed (RuntimeException).');
        $this->logger->expects($this->once())->method('warning')->with($this->callback(
            static fn (string $message): bool => str_contains($message, 'store 2') && !str_contains($message, 'milho')
        ));
        $state = $this->state();
        $this->assertSame(['produtos:-'], $this->codes($state->getTabs()));
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
        $this->assertFalse($state->isCmsTabActive());
        $this->assertNull($state->getResult());
        $this->assertNull($state->getSelectedTerm(), 'the filter term is dropped after an unavailable search');
        $this->assertSame(1, $state->getPage(), 'the requested page is dropped after an unavailable search');
    }

    public function testAStoreViewWithoutIndexIsNotAnError(): void
    {
        $this->failure = SearchUnavailableException::missingIndex('magento2_thedevkitchen_cmssearch_2');
        $this->logger->expects($this->never())->method('warning');
        $this->logger->expects($this->never())->method('error');
        $this->assertSame(['produtos:-'], $this->codes($this->state()->getTabs()));
    }

    public function testEverythingIsComputedOncePerRequest(): void
    {
        $this->types = $this->createMock(ContentTypeTabs::class);
        $this->types->expects($this->once())->method('forStore')->willReturn([['code' => 'news', 'label' => 'News']]);
        $this->params = ['tab' => 'news', 'cls' => 'agro'];
        $this->terms->expects($this->once())->method('findByCode')->willReturn(['term_id' => 1, 'code' => 'agro', 'label' => 'Agro']);
        $this->terms->expects($this->once())->method('tree')->willReturn([]);
        $state = $this->state();
        for ($i = 0; $i < 3; $i++) {
            $state->isEnabled();
            $state->getTabs();
            $state->getActiveTab();
            $state->getResult();
            $state->getSelectedTerm();
            $state->getTermTree();
        }
        $this->assertCount(1, $this->requests);
    }

    public function testReservedContentTypeCodesNeverShadowTheFixedTabs(): void
    {
        $this->types = $this->createMock(ContentTypeTabs::class);
        $this->types->method('forStore')->willReturn([['code' => 'produtos', 'label' => 'X'], ['code' => 'news', 'label' => 'News']]);
        $this->assertSame(['produtos:-', 'news:3'], $this->codes($this->state()->getTabs()));
    }

    public function testUnexpectedErrorsNeverBreakThePage(): void
    {
        $this->types = $this->createMock(ContentTypeTabs::class);
        $this->types->method('forStore')->willThrowException(new \RuntimeException('database down'));
        $this->logger->expects($this->once())->method('error');
        $state = $this->state();
        $this->assertSame(['produtos:-'], $this->codes($state->getTabs()));
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
        $this->assertNull($state->getResult());
    }

    public function testTheSearchedTextIsBounded(): void
    {
        $this->queryText = str_repeat('a', 300);
        $this->state()->getTabs();
        $this->assertSame(SearchState::MAX_QUERY_LENGTH, mb_strlen($this->lastRequest()->getQueryText()));
    }

    // ---- additions to the brief's tests ----

    public function testTheSearchedTextIsTrimmedAndCutByCharactersNotBytes(): void
    {
        $this->queryText = "  \t" . str_repeat('é', 255) . ' ção  ';
        $state = $this->state();
        $this->assertSame(str_repeat('é', 255), $state->getQueryText(), 'trimmed, cut at 256 characters, trimmed again');
        $this->assertSame(str_repeat('é', 255), $this->lastRequest()->getQueryText());
    }

    public function testTheStoreScopeComesFromTheCurrentStore(): void
    {
        $state = $this->state();
        $this->assertSame([2, 2, 12, 'milho'], [$state->getStoreId(), $state->getWebsiteId(), $state->getPageSize(), $state->getQueryText()]);
    }

    public function testADisabledStoreViewNeverReadsTypesTermsOrTheIndex(): void
    {
        $this->enabled = false;
        $this->params = ['tab' => 'news', 'cls' => 'agro', 'p' => '2'];
        $this->types = $this->createMock(ContentTypeTabs::class);
        $this->types->expects($this->never())->method('forStore');
        $this->terms->expects($this->never())->method('findByCode');
        $this->terms->expects($this->never())->method('tree');
        $this->logger->expects($this->never())->method($this->anything());
        $state = $this->state();
        $this->assertSame([[], null, 1, [], null], [$state->getTabs(), $state->getSelectedTerm(), $state->getPage(), $state->getTermTree(), $state->getResult()]);
        $this->assertFalse($state->isCmsTabActive());
        $this->assertSame([], $this->requests);
    }

    public function testAnEmptyQueryHasNoTabsAndReadsNothing(): void
    {
        $this->queryText = '';
        $this->params = ['tab' => 'news'];
        $this->types = $this->createMock(ContentTypeTabs::class);
        $this->types->expects($this->never())->method('forStore');
        $state = $this->state();
        $this->assertSame([], $state->getTabs());
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
        $this->assertSame([], $this->requests);
    }

    public function testAMalformedTermCodeIsNeverLookedUp(): void
    {
        $this->params = ['tab' => 'news', 'cls' => "agro\n"];
        $this->terms->expects($this->never())->method('findByCode');
        $state = $this->state();
        $this->assertNull($state->getSelectedTerm());
        $this->assertNull($this->lastRequest()->getTermId());
    }

    public function testTheTermIsIgnoredOnTheCategoriesTab(): void
    {
        $this->result = new CmsResult(['news' => 3], 4, true, 4, [], []);
        $this->params = ['tab' => 'categorias', 'cls' => 'agro', 'p' => '2'];
        $this->terms->expects($this->never())->method('findByCode');
        $this->terms->expects($this->never())->method('tree');
        $state = $this->state();
        $this->assertNull($state->getSelectedTerm());
        $this->assertSame([], $state->getTermTree());
        $this->assertSame(2, $state->getPage());
        $this->assertNull($this->lastRequest()->getTermId());
        $this->assertNull($this->lastRequest()->getContentTypeCode());
    }

    public function testAnUnavailableCategoriesTabResetsThePage(): void
    {
        $this->params = ['tab' => 'categorias', 'p' => '4'];
        $state = $this->state();
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
        $this->assertFalse($state->isCmsTabActive());
        $this->assertSame(1, $state->getPage());
    }

    public function testTheTermTreeOfAContentTabIsTheWebsiteTreeOfThisStoreView(): void
    {
        $tree = [['term_id' => 1, 'code' => 'agro', 'label' => 'Agro', 'children' => []]];
        $this->params = ['tab' => 'news'];
        $this->terms->expects($this->once())->method('tree')->with(2, 2)->willReturn($tree);
        $this->assertSame($tree, $this->state()->getTermTree());
    }

    public function testAFailingTermTreeHidesOnlyTheFilter(): void
    {
        $this->params = ['tab' => 'news'];
        $this->terms->expects($this->once())->method('tree')->willThrowException(new \RuntimeException('db milho'));
        $this->logger->expects($this->once())->method('error')->with($this->callback(
            static fn (string $message): bool => str_contains($message, 'RuntimeException') && !str_contains($message, 'milho')
        ));
        $state = $this->state();
        $this->assertSame([], $state->getTermTree());
        $this->assertSame([], $state->getTermTree());
        $this->assertSame('news', $state->getActiveTab()->getCode());
        $this->assertSame($this->result, $state->getResult());
    }

    public function testThePageSizeComesFromTheStoreViewSetting(): void
    {
        $this->pageSize = 25;
        $this->params = ['tab' => 'news', 'p' => '9999'];
        $state = $this->state();
        $this->assertSame([25, 400], [$state->getPageSize(), $state->getPage()]);
        $this->assertSame([25, 400], [$this->lastRequest()->getPageSize(), $this->lastRequest()->getPage()]);
    }

    public function testAnUnexpectedQueryErrorDropsTheTermAndThePage(): void
    {
        $this->params = ['tab' => 'news', 'cls' => 'agro', 'p' => '2'];
        $this->terms->method('findByCode')->willReturn(['term_id' => 1, 'code' => 'agro', 'label' => 'Agro']);
        $this->failure = new \LogicException('boom');
        $state = $this->state();
        $this->assertSame([null, 1, 'produtos', false], [$state->getSelectedTerm(), $state->getPage(), $state->getActiveTab()->getCode(), $state->isCmsTabActive()]);
    }

    public function testNoTermTreeAfterAnIndexFailure(): void
    {
        $this->params = ['tab' => 'news'];
        $this->failure = new SearchUnavailableException('CMS search request on x failed (RuntimeException).');
        $this->terms->expects($this->never())->method('tree');
        $this->assertSame([], $this->state()->getTermTree());
    }

    public function testAnIndexFailureIsLoggedOncePerRequestWithoutTheSearchedText(): void
    {
        $this->queryText = 'segredo';
        $this->failure = new SearchUnavailableException('CMS search request on magento2_thedevkitchen_cmssearch_2 failed (RuntimeException).');
        $messages = [];
        $this->logger->method('warning')->willReturnCallback(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $state = $this->state();
        for ($i = 0; $i < 3; $i++) {
            $state->getTabs();
            $state->getResult();
            $state->getActiveTab();
            $state->getTermTree();
        }
        $this->assertCount(1, $messages);
        $this->assertStringNotContainsString('segredo', $messages[0]);
        $this->assertCount(1, $this->requests, 'a failed index is not asked again in the same request');
    }

    public function testAnUnexpectedErrorIsLoggedByClassOnly(): void
    {
        $this->types = $this->createMock(ContentTypeTabs::class);
        $this->types->method('forStore')->willThrowException(new \RuntimeException('database down milho'));
        $this->logger->expects($this->once())->method('error')->with($this->callback(
            static fn (string $message): bool => str_contains($message, 'RuntimeException')
                && !str_contains($message, 'database down') && !str_contains($message, 'milho')
        ));
        $this->state()->getTabs();
    }

    public function testAnUnexpectedErrorOfTheQueryIsCaughtToo(): void
    {
        $this->params = ['tab' => 'news'];
        $this->failure = new \LogicException('boom');
        $this->logger->expects($this->once())->method('error');
        $state = $this->state();
        $this->assertSame(['produtos:-'], $this->codes($state->getTabs()));
        $this->assertNull($state->getResult());
    }

    public function testABrokenLogNeverBreaksThePage(): void
    {
        $this->params = ['tab' => 'news'];
        $this->failure = new SearchUnavailableException('CMS search request on x failed (RuntimeException).');
        $this->logger->method('warning')->willThrowException(new \UnexpectedValueException('log file not writable'));
        $this->logger->method('error')->willThrowException(new \UnexpectedValueException('log file not writable'));
        $state = $this->state();
        $this->assertSame(['produtos:-'], $this->codes($state->getTabs()));
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
    }

    public function testABrokenLogNeverBreaksThePageOnAnUnexpectedError(): void
    {
        $this->types = $this->createMock(ContentTypeTabs::class);
        $this->types->method('forStore')->willThrowException(new \RuntimeException('x'));
        $this->logger->method('error')->willThrowException(new \UnexpectedValueException('log file not writable'));
        $this->assertSame(['produtos:-'], $this->codes($this->state()->getTabs()));
    }

    public function testTheCategoriesCodeOfAContentTypeNeverGetsATab(): void
    {
        $this->types = $this->createMock(ContentTypeTabs::class);
        $this->types->method('forStore')->willReturn([['code' => 'categorias', 'label' => 'X'], ['code' => 'news', 'label' => 'News']]);
        $this->params = ['tab' => 'categorias'];
        $state = $this->state();
        $this->assertSame(['produtos:-', 'news:3'], $this->codes($state->getTabs()));
        $this->assertSame(Tab::KIND_CATEGORIES, $this->lastRequest()->getActiveKind());
        $this->assertSame('produtos', $state->getActiveTab()->getCode());
    }

    public function testAContentTypeWithoutHitsStillHasItsTab(): void
    {
        $this->params = ['tab' => 'help'];
        $state = $this->state();
        $this->assertSame('help', $state->getActiveTab()->getCode());
        $this->assertSame(0, $state->getActiveTab()->getCount());
        $this->assertSame('help', $this->lastRequest()->getContentTypeCode());
    }

    public function testAResetForgetsTheDecisionOfThePreviousRequest(): void
    {
        $state = $this->state();
        $this->assertInstanceOf(ResetAfterRequestInterface::class, $state);
        $this->assertSame('milho', $state->getQueryText());
        $this->queryText = 'soja';
        $this->assertSame('milho', $state->getQueryText(), 'decided once per request');
        $requests = count($this->requests);
        $state->_resetState();
        $this->assertSame('soja', $state->getQueryText());
        $this->assertSame($requests + 1, count($this->requests), 'the next request asks the index again');
    }
}
