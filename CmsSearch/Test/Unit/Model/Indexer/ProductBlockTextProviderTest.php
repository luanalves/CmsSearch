<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use Magento\AdvancedSearch\Model\Adapter\DataMapper\AdditionalFieldsProviderInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\ProductBlockTextProvider;
use Thedevkitchen\CmsSearch\Test\Unit\Support\DbStubTrait;

class ProductBlockTextProviderTest extends TestCase
{
    use DbStubTrait;

    public function testTheFieldOfTheStoreViewForProductsThatHaveBlockText(): void
    {
        $provider = new ProductBlockTextProvider($this->db([], [], [['7' => 'Zeta block', '9' => '']]));
        $this->assertInstanceOf(AdditionalFieldsProviderInterface::class, $provider);
        $this->assertSame('cmssearch_block_text', ProductBlockTextProvider::FIELD);
        $this->assertSame([7 => ['cmssearch_block_text' => 'Zeta block']], $provider->getFields([7, 8, 9, '7'], '1'));
        $this->assertSame([1], $this->whereValues('store_id = ?'));
        $this->assertSame([[7, 8, 9]], $this->whereValues('product_id IN (?)'));
        $this->assertSame(['thedevkitchen_cmssearch_product_block_text'], $this->tables);
    }

    public function testNoProductOrNoStoreViewRunsNoQuery(): void
    {
        $provider = new ProductBlockTextProvider($this->db());
        $this->assertSame([], $provider->getFields([], 1));
        $this->assertSame([], $provider->getFields([0, -1], 1));
        $this->assertSame([], $provider->getFields([7], 0));
    }
}
