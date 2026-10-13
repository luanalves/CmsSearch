<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\Text\Directive;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockRef;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostType;
use Thedevkitchen\CmsSearch\Model\Reference\DirectiveParser;

class DirectiveParserTest extends TestCase
{
    private function keys(string $html): array
    {
        return array_map(
            static fn (BlockRef $ref): string => $ref->key(),
            (new DirectiveParser(new TextExtractor()))->parseHtml($html)
        );
    }

    /** @return array<string, array{0: string, 1: list<string>}> raw content => expected reference keys */
    public static function contents(): array
    {
        $long = str_repeat('a', 256);
        return [
            'widget block by id' => ['<div data-content-type="block">{{widget type="Magento\Cms\Block\Widget\Block" template="widget/static_block/default.phtml" block_id="12" type_name="CMS Static Block"}}</div>', ['block#12']],
            'widget block by identifier' => ['{{widget type="Magento\Cms\Block\Widget\Block" block_id="footer-links"}}', ['block@footer-links']],
            'double backslashes as stored by some imports' => ['{{widget type="Magento\\\\Cms\\\\Block\\\\Widget\\\\Block" block_id="7"}}', ['block#7']],
            'leading backslash and single quotes' => ["{{widget type='\\Magento\\Cms\\Block\\Widget\\Block' block_id='8'}}", ['block#8']],
            'widget block without block_id' => ['{{widget type="Magento\Cms\Block\Widget\Block" template="x.phtml"}}', []],
            'block id zero' => ['{{widget type="Magento\Cms\Block\Widget\Block" block_id="0"}}', []],
            'block id above the smallint range' => ['{{widget type="Magento\Cms\Block\Widget\Block" block_id="32768"}}', []],
            'identifier longer than the column' => ['{{block id="' . $long . '"}}', []],
            'dynamic blocks in fixed mode, junk and duplicates dropped' => ['{{widget type="Magento\Banner\Block\Widget\Banner" display_mode="fixed" banner_ids="3, 5,x,0,3" unique_id="3"}}', ['dynamic_block#3', 'dynamic_block#5']],
            'dynamic blocks chosen by cart rules (visitor dependent)' => ['{{widget type="Magento\Banner\Block\Widget\Banner" display_mode="salesrule" banner_ids="3"}}', []],
            'dynamic blocks without display_mode are fixed' => ['{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids="9"}}', ['dynamic_block#9']],
            'dynamic block id above the unsigned int range' => ['{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids="4294967296"}}', []],
            'legacy block by identifier' => ['<p>{{block id="footer"}}</p>', ['block@footer']],
            'legacy block by id' => ['{{block id="7"}}', ['block#7']],
            'legacy block class Magento\Cms\Block\Block with block_id (real home pages)' => ['{{block class="Magento\\\\Cms\\\\Block\\\\Block" block_id="electronics-home-content"}}', ['block@electronics-home-content']],
            'another block class is not a CMS block (real block 1)' => ['{{block class="Magento\\\\CatalogEvent\\\\Block\\\\Event\\\\Lister" name="catalog.event.lister" template="lister.phtml"}}', []],
            'another block class that carries a block_id is still no CMS block' => ['{{block class="Vendor\\Other\\Block" block_id="5" id="6"}}', []],
            'another widget type' => ['{{widget type="Magento\CatalogWidget\Block\Product\ProductsList" products_count="10"}}', []],
            'media, store and var directives' => ['<a href="{{store url="about-us"}}"><img src="{{media url="wysiwyg/a.jpg"}}"></a>{{var name}}', []],
            'escaped Page Builder html content type' => ['<div data-content-type="html" data-appearance="default" data-element="main">&lt;p&gt;{{widget type=&quot;Magento\Cms\Block\Widget\Block&quot; block_id=&quot;21&quot;}}&lt;/p&gt;</div>', ['block#21']],
            'duplicates collapse case-insensitively, order kept' => ['{{block id="Footer"}}{{widget type="Magento\Cms\Block\Widget\Block" block_id="4"}}{{block id="footer"}}{{block id="4"}}', ['block@footer', 'block#4']],
        ];
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function variants(): array
    {
        $big = str_repeat('x', 300000);
        return [
            'newlines and tabs between attributes' => ["{{widget\n type=\"Magento\\Cms\\Block\\Widget\\Block\"\n\tblock_id =  \"13\" }}", ['block#13']],
            'upper case directive name' => ['{{WIDGET type="Magento\Cms\Block\Widget\Block" block_id="14"}}', ['block#14']],
            'escaped quotes break the attribute: no bogus identifier' => ['{{widget type="Magento\Cms\Block\Widget\Block" block_id=\\"12\\"}}', []],
            'escaped quote inside the value' => ['{{block id="a\\"b"}}', []],
            'identifier with markup characters' => ['{{block id="<b>"}}', []],
            'unquoted id is read like Magento tokenizer does' => ['{{block id=12}}', ['block#12']],
            'commented, hidden and display:none references are not references' => ['<!-- {{block id="15"}} --><div hidden>{{block id="16"}}</div><div style="display:none">{{block id="17"}}</div>{{block id="18"}}', ['block#18']],
            'brace inside a value ends the directive early: nothing' => ['{{block id="}}"}}', []],
            'unclosed directive' => ['{{block id="19"', []],
            'empty and whitespace' => ['   ', []],
            'nested braces' => ['{{{{block id="20"}}}}', []],
            'three lists one widget' => ['{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids=",,1,,2 ,"}}', ['dynamic_block#1', 'dynamic_block#2']],
            'huge banner_ids value is bounded by the input cap' => ['{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids="' . $big . '"}}', []],
            'huge identifier' => ['{{block id="' . $big . '"}}', []],
            'a megabyte of open braces' => [str_repeat('{{', 600000) . '{{block id="21"}}', []],
        ];
    }

    #[DataProvider('variants')]
    public function testVariantsAndHostileContent(string $html, array $expected): void
    {
        $this->assertSame($expected, $this->keys($html));
    }

    public function testManyReferencesAreLinearAndDeduplicated(): void
    {
        $html = str_repeat('{{block id="1"}}', 30000) . '{{block id="2"}}';
        $start = microtime(true);
        $this->assertSame(['block#1', 'block#2'], $this->keys($html));
        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    public function testParseAcceptsDirectivesDirectlyAndKeepsFirstAppearanceOrder(): void
    {
        $parser = new DirectiveParser(new TextExtractor());
        $directives = [
            new Directive('block', ['id' => '9'], '{{block id="9"}}'),
            new Directive('block', ['id' => 'Z'], '{{block id="Z"}}'),
            new Directive('block', ['id' => '9'], '{{block id="9"}}'),
            new Directive('media', ['url' => 'a'], '{{media url="a"}}'),
        ];
        $this->assertSame(['block#9', 'block@z'], array_map(static fn (BlockRef $r): string => $r->key(), $parser->parse($directives)));
        $this->assertSame([], $parser->parse([]));
    }

    public function testNormalizeClass(): void
    {
        $this->assertSame('Magento\\Cms\\Block\\Block', DirectiveParser::normalizeClass('\\\\Magento\\\\Cms\\\\Block\\\\Block '));
        $this->assertSame('A\\B', DirectiveParser::normalizeClass('\\A\\B'));
    }

    public function testHostTypes(): void
    {
        $this->assertSame(['page', 'category', 'product'], HostType::ALL);
    }

    #[DataProvider('contents')]
    public function testReferencesComeFromTheDirectivesOnly(string $html, array $expected): void
    {
        $this->assertSame($expected, $this->keys($html));
    }

    public function testThePlan4PageBuilderFixtures(): void
    {
        $dir = dirname(__DIR__, 2) . '/_files/pagebuilder/';
        $this->assertSame(['block#12', 'block@footer-links'], $this->keys((string) file_get_contents($dir . 'block.html')));
        $this->assertSame(['dynamic_block#3', 'dynamic_block#5'], $this->keys((string) file_get_contents($dir . 'dynamic-block.html')));
    }

    public function testBlockRefAcceptsOnlyOneWayToNameABlock(): void
    {
        $this->assertSame('block@footer', (new BlockRef(BlockRef::KIND_BLOCK, 0, 'FOOTER'))->key());
        // utf8mb4_general_ci folding: accents and case ignored, ß = s (one character), æ/ø kept
        $this->assertSame('block@cafe', (new BlockRef(BlockRef::KIND_BLOCK, 0, 'Café'))->key());
        $this->assertSame('block@strase', (new BlockRef(BlockRef::KIND_BLOCK, 0, 'STRAẞE'))->key());
        $this->assertSame('block@strase', (new BlockRef(BlockRef::KIND_BLOCK, 0, 'Straße'))->key());
        $this->assertNotSame((new BlockRef(BlockRef::KIND_BLOCK, 0, 'strasse'))->key(), (new BlockRef(BlockRef::KIND_BLOCK, 0, 'Straße'))->key());
        $this->assertSame('block@æø-x', (new BlockRef(BlockRef::KIND_BLOCK, 0, 'ÆØ-X'))->key());
        $this->assertSame('FOOTER', (new BlockRef(BlockRef::KIND_BLOCK, 0, 'FOOTER'))->getIdentifier());
        $this->assertTrue((new BlockRef(BlockRef::KIND_BLOCK, 0, 'x'))->isByIdentifier());
        $this->assertFalse((new BlockRef(BlockRef::KIND_DYNAMIC_BLOCK, 3))->isByIdentifier());
        foreach ([['block', 0, ''], ['block', 5, 'x'], ['block', -1, ''], ['dynamic_block', 0, 'x'], ['page', 1, '']] as [$kind, $id, $identifier]) {
            try {
                new BlockRef($kind, $id, $identifier);
                $this->fail(sprintf('accepted %s/%d/%s', $kind, $id, $identifier));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
