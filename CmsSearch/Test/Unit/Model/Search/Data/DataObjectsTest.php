<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Search\Data;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Search\Data\Card;
use Thedevkitchen\CmsSearch\Model\Search\Data\CmsResult;
use Thedevkitchen\CmsSearch\Model\Search\Data\SearchRequest;
use Thedevkitchen\CmsSearch\Model\Search\Data\Tab;

class DataObjectsTest extends TestCase
{
    public function testOnlyTheProductsTabIsNative(): void
    {
        $products = new Tab('produtos', Tab::KIND_PRODUCTS, 'Produtos', null);
        $news = new Tab('news', Tab::KIND_CONTENT, 'News', 3);
        $categories = new Tab('categorias', Tab::KIND_CATEGORIES, 'Categorias', 0);
        $this->assertFalse($products->isCms());
        $this->assertTrue($news->isCms());
        $this->assertTrue($categories->isCms());
        $this->assertSame(['news', 'content', 'News', 3], [$news->getCode(), $news->getKind(), $news->getLabel(), $news->getCount()]);
        $this->assertNull($products->getCount());
    }

    public function testTheRequestKnowsItsOffsetAndWhetherItNeedsHits(): void
    {
        $content = new SearchRequest('milho', 2, 2, Tab::KIND_CONTENT, 'news', 7, 3, 12);
        $this->assertSame(24, $content->getFrom());
        $this->assertTrue($content->wantsHits());
        $products = new SearchRequest('milho', 2, 2, Tab::KIND_PRODUCTS, null, null, 1, 12);
        $this->assertFalse($products->wantsHits());
        $this->assertSame(0, $products->getFrom());
    }

    public function testTheCategoriesTabNeedsHitsToo(): void
    {
        $categories = new SearchRequest('milho', 2, 2, Tab::KIND_CATEGORIES, null, null, 1, 12);
        $this->assertTrue($categories->wantsHits());
    }

    public function testTheRequestExposesEveryConstructorValue(): void
    {
        $request = new SearchRequest('milho', 2, 3, Tab::KIND_CONTENT, 'news', 7, 3, 12);
        $this->assertSame(
            ['milho', 2, 3, 'content', 'news', 7, 3, 12],
            [
                $request->getQueryText(),
                $request->getStoreId(),
                $request->getWebsiteId(),
                $request->getActiveKind(),
                $request->getContentTypeCode(),
                $request->getTermId(),
                $request->getPage(),
                $request->getPageSize(),
            ]
        );
    }

    public function testTheCardExposesEveryConstructorValue(): void
    {
        $card = new Card('page', 'Title <b>', 'https://x.test/a', null, 'Sum', 'news');
        $this->assertSame(
            ['page', 'Title <b>', 'https://x.test/a', null, 'Sum', 'news'],
            [
                $card->getEntityType(),
                $card->getTitle(),
                $card->getUrl(),
                $card->getImage(),
                $card->getSummary(),
                $card->getContentTypeCode(),
            ]
        );
    }

    public function testCountsOfUnknownContentTypesAreZero(): void
    {
        $result = new CmsResult(['news' => 5], 0, false, 0, [], []);
        $this->assertSame(5, $result->getCount('news'));
        $this->assertSame(0, $result->getCount('help'));
    }

    public function testTheResultExposesEveryConstructorValue(): void
    {
        $card = new Card('page', 'T', '', null, '', 'news');
        $result = new CmsResult(['news' => 5], 2, true, 9, [$card], [4 => 3]);
        $this->assertSame(['news' => 5], $result->getTypeCounts());
        $this->assertSame(2, $result->getCategoryCount());
        $this->assertTrue($result->hasCategoryDocuments());
        $this->assertSame(9, $result->getTotal());
        $this->assertSame([$card], $result->getHits());
        $this->assertSame([4 => 3], $result->getTermCounts());
    }
}
