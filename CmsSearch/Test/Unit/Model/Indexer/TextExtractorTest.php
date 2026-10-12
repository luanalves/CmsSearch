<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\Text\Directive;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;

class TextExtractorTest extends TestCase
{
    private TextExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TextExtractor();
    }

    private function fixture(string $name): string
    {
        $file = dirname(__DIR__, 2) . '/_files/pagebuilder/' . $name . '.html';
        $this->assertFileExists($file);
        return (string) file_get_contents($file);
    }

    /** @param list<Directive> $directives @return list<string> */
    private function names(array $directives): array
    {
        return array_map(static fn (Directive $directive): string => $directive->getName(), $directives);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function fixtures(): array
    {
        return [
            'row, column group and text (style block, nbsp, inline tags)' => [
                'row-column-text', 'Our regional stores open every day. Ask about delivery. Second column text.',
            ],
            'heading' => ['heading', 'Seasonal offers For the whole family'],
            'block (widget directive only)' => ['block', ''],
            'dynamic block (widget directive only)' => ['dynamic-block', ''],
            'html content type (escaped, decoded once, style/script dropped)' => [
                'html-escaped', 'Opening hours Shops & offices: 9h - 18h After the html element',
            ],
            'banner (content and button text, link directive dropped)' => ['banner', 'Free delivery this week Shop now'],
            'slider (two slides)' => ['slider', 'First slide text Second slide text'],
            'products (widget directive only)' => ['products', ''],
            'legacy WYSIWYG with block/store/view directives' => [
                'legacy-wysiwyg',
                'Welcome to our store. Find the customer service page or contact us. Points can be used at checkout.',
            ],
            'image, video and map carry no text' => ['image-video', 'Only this paragraph is text.'],
            'UTF-8 accents, typographic quotes, emoji' => [
                'utf8', 'Promoções de verão: até 50% em informática — “ofertas” válidas já. Emoji 🎉 e símbolos © ®',
            ],
            'style, script, noscript, comment and template are not text' => ['style-script', 'Visible text'],
            'hidden by Page Builder style rules, inline style and the hidden attribute' => [
                'hidden',
                'Visible column copy Mobile only hidden stays visible on desktop Aria hidden is still visible text Visible control',
            ],
        ];
    }

    #[DataProvider('fixtures')]
    public function testPageBuilderFixturesBecomeCleanText(string $fixture, string $expected): void
    {
        $text = $this->extractor->extract($this->fixture($fixture))->getText();
        $this->assertSame($expected, $text);
        $this->assertDoesNotMatchRegularExpression('/[<>]|\{\{|\}\}|data-pb|&[a-z]+;/i', $text);
    }

    public function testBlockAndDynamicBlockDirectivesAreExposedForPlan6(): void
    {
        $block = $this->extractor->extract($this->fixture('block'))->getDirectives();
        $this->assertSame(['widget', 'widget'], $this->names($block));
        $this->assertSame('Magento\\Cms\\Block\\Widget\\Block', $block[0]->getParam('type'));
        $this->assertSame(['12', 'footer-links'], [$block[0]->getParam('block_id'), $block[1]->getParam('block_id')]);

        $dynamic = $this->extractor->extract($this->fixture('dynamic-block'))->getDirectives();
        $this->assertCount(1, $dynamic);
        $this->assertSame('Magento\\Banner\\Block\\Widget\\Banner', $dynamic[0]->getParam('type'));
        $this->assertSame('3,5', $dynamic[0]->getParam('banner_ids'));
        $this->assertSame('', $dynamic[0]->getParam('rotate'));
        $this->assertNull($dynamic[0]->getParam('block_id'));
    }

    public function testLegacyDirectivesWithEitherQuoteStyleAreParsedInOrder(): void
    {
        $directives = $this->extractor->extract($this->fixture('legacy-wysiwyg'))->getDirectives();
        $this->assertSame(['store', 'store', 'block', 'block', 'view'], $this->names($directives));
        $this->assertSame('customer-service', $directives[0]->getParam('url'));
        $this->assertSame('contact', $directives[1]->getParam('url'));
        $this->assertSame('Magento\\Cms\\Block\\Block', $directives[2]->getParam('class'));
        $this->assertSame('home-content', $directives[2]->getParam('block_id'));
        $this->assertSame('footer-links', $directives[3]->getParam('id'));
        $this->assertSame('{{block id="footer-links"}}', $directives[3]->getRaw());
        $this->assertSame('Magento_Reward::images/payment.png', $directives[4]->getParam('url'));
    }

    public function testDirectivesInsideEscapedHtmlAreDecodedBeforeParsing(): void
    {
        $directives = $this->extractor->extract($this->fixture('html-escaped'))->getDirectives();
        $this->assertSame(['widget'], $this->names($directives));
        $this->assertSame('7', $directives[0]->getParam('block_id'));
        $this->assertSame('Magento\\Cms\\Block\\Widget\\Block', $directives[0]->getParam('type'));
    }

    public function testUnquotedParametersAndDuplicatesAreHandled(): void
    {
        $content = $this->extractor->extract('<p>{{media url=wysiwyg/a.jpg}} {{media url=wysiwyg/a.jpg}} {{trans "Hello"}}</p>');
        $this->assertSame(['media', 'trans'], $this->names($content->getDirectives()));
        $this->assertSame('wysiwyg/a.jpg', $content->getDirectives()[0]->getParam('url'));
        $this->assertSame([], $content->getDirectives()[1]->getParams());
        $this->assertSame('', $content->getText());
    }

    public function testTheFirstImageSourceIsKeptRaw(): void
    {
        $this->assertSame(
            '{{media url=wysiwyg/team.jpg}}',
            $this->extractor->extract($this->fixture('image-video'))->getFirstImage()
        );
        $this->assertNull($this->extractor->extract($this->fixture('row-column-text'))->getFirstImage());
    }

    public function testEscapedHtmlIsDecodedAtMostTwoLevelsDeep(): void
    {
        $wrap = static fn (string $inner): string => '<div data-content-type="html">' . htmlspecialchars($inner, ENT_QUOTES) . '</div>';
        $html = $wrap('<p>one</p>' . $wrap('<p>two</p>' . $wrap('<p>three</p>')));
        $this->assertSame('one two', $this->extractor->extract($html)->getText());
        $this->assertSame(2, TextExtractor::MAX_HTML_DEPTH);
    }

    public function testInvalidUtf8AndEmptyContentDoNotBreakTheExtraction(): void
    {
        $this->assertSame('?( still indexed', $this->extractor->extract("<p>\xC3\x28 still indexed</p>")->getText());
        $empty = $this->extractor->extract("  \n ");
        $this->assertSame('', $empty->getText());
        $this->assertSame([], $empty->getDirectives());
        $this->assertNull($empty->getFirstImage());
    }

    public function testInvalidBytesNeverReachTheDirectivesSoTheyStayJsonEncodable(): void
    {
        $content = $this->extractor->extract("<p>x</p>{{block id=\"a\xFFb\"}}");
        $directive = $content->getDirectives()[0];
        $this->assertTrue(mb_check_encoding($directive->getRaw(), 'UTF-8'));
        $this->assertTrue(mb_check_encoding((string) $directive->getParam('id'), 'UTF-8'));
        $this->assertNotFalse(json_encode($directive->getParams()));
    }

    public function testBlockElementsSeparateWordsAndInlineElementsDoNot(): void
    {
        $this->assertSame(
            'Promoção next a b line break',
            $this->extractor->extract('<p>Pro<strong>moção</strong></p><p>next</p><ul><li>a</li><li>b</li></ul>line<br>break')->getText()
        );
    }

    public function testXssPayloadsEndUpAsInertTextOrNothing(): void
    {
        $html = '<p>Hello <img src=x onerror=alert(1)><a href="javascript:alert(2)">link</a></p>'
            . '<svg onload=alert(3)><text>svg text</text></svg><iframe src="javascript:alert(4)">frame</iframe>'
            . '<div data-content-type="html">&lt;img src=x onerror=alert(5)&gt;&lt;script&gt;alert(6)&lt;/script&gt;'
            . '&lt;b&gt;bold&lt;/b&gt;</div>';
        $text = $this->extractor->extract($html)->getText();
        $this->assertSame('Hello link bold', $text);
        $this->assertDoesNotMatchRegularExpression('/[<>]|alert|onerror|onload|javascript/i', $text);
    }

    public function testDoubleEncodedMarkupInTheHtmlTypeIsLiteralTextNotMarkup(): void
    {
        // "&amp;lt;script&amp;gt;" is the user's TEXT "<script>..." (shown literally by the storefront); it must be kept
        // as text, not parsed as an element (which would silently drop it). Consumers escape the text on output.
        $html = '<div data-content-type="html">&amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;</div>';
        $this->assertSame('<script>alert(1)</script>', $this->extractor->extract($html)->getText());
    }

    public function testVeryLargeAndDeeplyNestedContentIsExtractedWithoutFailure(): void
    {
        $html = str_repeat('<div>', 400) . '<p>deep</p>' . str_repeat('</div>', 400)
            . str_repeat('<p>word ' . str_repeat('x', 50) . '</p>', 4000);
        $text = $this->extractor->extract($html)->getText();
        $this->assertStringStartsWith('deep word', $text);
        $this->assertSame(4000, substr_count($text, 'word'));
    }

    public function testUnclosedTagsAndGarbageDoNotLeakWarnings(): void
    {
        $text = $this->extractor->extract('<p>open <b>bold <i>x</p></div><td>cell<<>>&bogus; {{unclosed')->getText();
        $this->assertStringContainsString('open bold x', $text);
    }

    public function testHiddenContentExposesNeitherDirectivesNorImages(): void
    {
        $content = $this->extractor->extract($this->fixture('hidden'));
        $this->assertSame([], $content->getDirectives());
        $this->assertNull($content->getFirstImage());
    }

    public function testCommentedNoscriptTemplateAndScriptContentIsNotExposed(): void
    {
        $html = '<!-- <p>{{widget type="Magento\Cms\Block\Widget\Block" block_id="1"}}<img src="{{media url=c.jpg}}"></p> -->'
            . '<noscript>{{block id="n"}}<img src="n.jpg"></noscript><template>{{block id="t"}}</template>'
            . '<script>var a = "{{block id=\"s\"}}";</script><style>/* {{block id="st"}} */</style>'
            . '<p>shown</p>{{block id="real"}}';
        $content = $this->extractor->extract($html);
        $this->assertSame('shown', $content->getText());
        $this->assertSame(['real'], array_map(static fn (Directive $d): ?string => $d->getParam('id'), $content->getDirectives()));
        $this->assertNull($content->getFirstImage());
    }

    public function testTheFirstImageIsTheFirstVisibleOne(): void
    {
        $html = '<div hidden><img src="hidden.jpg"></div><!-- <img src="comment.jpg"> --><img src="shown.jpg">';
        $this->assertSame('shown.jpg', $this->extractor->extract($html)->getFirstImage());
    }

    public function testAnUnclosedDirectiveWithAHugeTailDoesNotEmptyTheText(): void
    {
        foreach (['<p>{{a ', '<p>{{widget type="x" ', '<p>' . str_repeat('{{a ', 60000)] as $prefix) {
            $content = $this->extractor->extract($prefix . str_repeat('ab ', 300000));
            $this->assertStringContainsString('ab ab ab', $content->getText());
            $this->assertNotSame('', $content->getText());
            $this->assertSame([], $content->getDirectives());
        }
    }

    public function testManyDirectivesAndAHugeSingleDirectiveAreParsedLinearly(): void
    {
        $many = str_repeat('{{store url="x"}} ', 30000) . '<p>end</p>';
        $content = $this->extractor->extract($many);
        $this->assertSame('end', $content->getText());
        $this->assertCount(1, $content->getDirectives());
        $huge = $this->extractor->extract('<p>a</p>{{widget note="' . str_repeat('y', 900000) . '" id="9"}}');
        $this->assertSame('a', $huge->getText());
        $this->assertSame('9', $huge->getDirectives()[0]->getParam('id'));
    }

    public function testInputAboveTheCapIsCutAtAUtf8BoundaryAndFlagged(): void
    {
        $this->assertSame(1048576, TextExtractor::MAX_INPUT_BYTES);
        // markup without text, then 3-byte characters: the cap falls INSIDE the 22 859th character
        $html = str_repeat('<b></b>', 140000) . str_repeat('€', 40000);
        $content = $this->extractor->extract($html);
        $this->assertTrue($content->isTruncated());
        $this->assertSame(str_repeat('€', 22858), $content->getText());
    }

    public function testDirectivesBeyondTheInputCapAreNotScanned(): void
    {
        $html = '<p>' . str_repeat('a ', 600000) . '</p>{{block id="late"}}';
        $content = $this->extractor->extract($html);
        $this->assertTrue($content->isTruncated());
        $this->assertSame([], $content->getDirectives());
    }

    public function testCollectedTextStopsAtTheTextCapAndIsFlagged(): void
    {
        $content = $this->extractor->extract(str_repeat('<p>' . str_repeat('w', 99) . '</p>', 9000));
        $this->assertTrue($content->isTruncated());
        $this->assertLessThan(TextExtractor::MAX_TEXT_CHARS + 5000, mb_strlen($content->getText()));
        $this->assertGreaterThanOrEqual(TextExtractor::MAX_TEXT_CHARS, mb_strlen($content->getText()));
        $small = $this->extractor->extract('<p>small</p>');
        $this->assertFalse($small->isTruncated());
    }

    public function testAFailedRegularExpressionThrowsInsteadOfYieldingEmptyText(): void
    {
        // separate process: compiled patterns (and the JIT setting) are cached per process
        $root = dirname(__DIR__, 8);
        $script = 'require ' . var_export($root . '/app/code/Thedevkitchen/CmsSearch/Model/Indexer/Text/Directive.php', true) . ';'
            . 'require ' . var_export($root . '/app/code/Thedevkitchen/CmsSearch/Model/Indexer/Text/ExtractedContent.php', true) . ';'
            . 'require ' . var_export($root . '/app/code/Thedevkitchen/CmsSearch/Model/Indexer/TextExtractor.php', true) . ';'
            . 'try { (new Thedevkitchen\\CmsSearch\\Model\\Indexer\\TextExtractor())'
            . '->extract("<p>" . str_repeat("word  ", 500) . "</p>"); echo "NO EXCEPTION"; }'
            . 'catch (UnexpectedValueException $e) { echo $e->getMessage(); }';
        $output = (string) shell_exec(
            escapeshellarg(PHP_BINARY) . ' -d pcre.jit=0 -d pcre.backtrack_limit=1 -r ' . escapeshellarg($script) . ' 2>&1'
        );
        $this->assertMatchesRegularExpression('/^Text extraction failed \(regular expression error \d+\)\.$/', $output);
    }
}
