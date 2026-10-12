<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Indexer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\ContentTypeState;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\PageSnapshot;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\StoreContext;
use Thedevkitchen\CmsSearch\Model\Indexer\Data\TermState;
use Thedevkitchen\CmsSearch\Model\Indexer\DocumentBuilder;
use Thedevkitchen\CmsSearch\Model\Indexer\IndexDefinition;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;

class DocumentBuilderTest extends TestCase
{
    private function builder(?AbstractLogger $logger = null): DocumentBuilder
    {
        return new DocumentBuilder(new TextExtractor(), $logger ?? new \Psr\Log\NullLogger());
    }

    private function store(string $base = 'https://w1.test/', string $media = 'https://w1.test/media/'): StoreContext
    {
        return new StoreContext(2, 2, 'w1', 'pt_BR', $base, $media, 'home');
    }

    private function page(string $content, string $identifier = 'news/offers', string $meta = '', string $heading = ''): PageSnapshot
    {
        return new PageSnapshot(5, $identifier, 'Seasonal offers', $heading, $content, $meta, true, [2], '2026-10-11 10:00:00');
    }

    /** @return array<string, mixed> */
    private function buildWith(PageSnapshot $page, ContentTypeState $type, TermState $term, StoreContext $store): array
    {
        $builder = $this->builder();
        return $builder->build($page, $type, $term, $store, $builder->prepare($page));
    }

    /** @return array<string, mixed> */
    private function build(PageSnapshot $page): array
    {
        return $this->buildWith($page, new ContentTypeState(3, 'news', true), new TermState(9, 2, '1/4/9', [1, 4, 9], true), $this->store());
    }

    public function testTheDocumentHasEveryMappedFieldFromTheCurrentState(): void
    {
        $document = $this->build($this->page(
            '<h2>Big sale</h2><p><img src="{{media url=wysiwyg/sale.jpg}}">Open every day.</p>{{block id="footer"}}',
            'news/offers',
            '',
            'Welcome'
        ));
        $this->assertSame([
            'entity_type' => 'page',
            'entity_id' => 5,
            'source_types' => ['cms'],
            'content_type_code' => 'news',
            'classification_term_id' => 9,
            'classification_ancestor_ids' => [1, 4, 9],
            'classification_path' => '1/4/9',
            'title' => 'Seasonal offers',
            'text' => 'Welcome Big sale Open every day.',
            'summary' => 'Welcome Big sale Open every day.',
            'url' => 'https://w1.test/news/offers',
            'image' => 'https://w1.test/media/wysiwyg/sale.jpg',
            'store_view_id' => 2,
            'website_id' => 2,
            'segment_ids' => [],
            'updated_at' => '2026-10-11 10:00:00',
        ], $document);
        $mapped = array_keys((new IndexDefinition())->build($this->store())['mappings']['properties']);
        $built = array_keys($document);
        sort($mapped);
        sort($built);
        $this->assertSame($mapped, $built, 'the document must match the strict mapping exactly');
    }

    public function testTheDocumentIdIsDeterministic(): void
    {
        $this->assertSame('page_5', DocumentBuilder::documentId(5));
        $this->assertSame('page_32767', DocumentBuilder::documentId(32767));
    }

    public function testTheHomePageUrlIsTheStoreBaseUrl(): void
    {
        $this->assertSame('https://w1.test/', $this->build($this->page('<p>x</p>', 'home'))['url']);
        $this->assertSame('https://w1.test/about-us', $this->build($this->page('<p>x</p>', '/about-us'))['url']);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function images(): array
    {
        return [
            'media directive, double quotes' => ['<img src="{{media url=&quot;wysiwyg/a.jpg&quot;}}">', 'https://w1.test/media/wysiwyg/a.jpg'],
            'media directive, single quotes' => ["<img src=\"{{media url='wysiwyg/b.jpg'}}\">", 'https://w1.test/media/wysiwyg/b.jpg'],
            'media directive, unquoted, leading slash' => ['<img src="{{media url=/wysiwyg/c.jpg}}">', 'https://w1.test/media/wysiwyg/c.jpg'],
            'absolute https URL' => ['<img src="https://cdn.test/d.png">', 'https://cdn.test/d.png'],
            'relative path' => ['<img src="/media/e.png">', null],
            'view directive' => ['<img src="{{view url=Magento_Reward::images/f.png}}">', null],
            'path traversal' => ['<img src="{{media url=../../etc/passwd}}">', null],
            'no image' => ['<p>text</p>', null],
        ];
    }

    #[DataProvider('images')]
    public function testOnlyMediaAndAbsoluteImagesAreKept(string $content, ?string $expected): void
    {
        $this->assertSame($expected, $this->build($this->page($content))['image']);
    }

    public function testTheMetaDescriptionIsTheSummaryWhenPresent(): void
    {
        $document = $this->build($this->page('<p>Body text.</p>', 'x', "  Short\n description  "));
        $this->assertSame('Short description', $document['summary']);
        $this->assertSame('Body text.', $document['text']);
    }

    public function testALongTextIsSummarisedAtAWordBoundary(): void
    {
        $words = str_repeat('palavra ', 60); // 480 characters
        $summary = $this->build($this->page('<p>' . $words . '</p>'))['summary'];
        $this->assertLessThanOrEqual(DocumentBuilder::SUMMARY_LENGTH + 1, mb_strlen($summary));
        $this->assertStringEndsWith('palavra…', $summary);
        $this->assertSame(300, DocumentBuilder::SUMMARY_LENGTH);
    }

    public function testTheIndexedTextIsBounded(): void
    {
        $text = $this->build($this->page('<p>' . str_repeat('a', 70000) . '</p>'))['text'];
        $this->assertSame(DocumentBuilder::MAX_TEXT_LENGTH, mb_strlen($text));
        $this->assertSame(65536, DocumentBuilder::MAX_TEXT_LENGTH);
    }

    public function testTheClassificationFieldsComeFromTheTermState(): void
    {
        $document = $this->buildWith(
            $this->page('<p>x</p>'),
            new ContentTypeState(7, 'guides', true),
            new TermState(31, 2, '1/20/31', [1, 20, 31], true),
            $this->store()
        );
        $this->assertSame('guides', $document['content_type_code']);
        $this->assertSame(31, $document['classification_term_id']);
        $this->assertSame([1, 20, 31], $document['classification_ancestor_ids']);
        $this->assertSame('1/20/31', $document['classification_path']);
        $this->assertSame(2, $document['store_view_id']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function urls(): array
    {
        return [
            'secure store' => ['https://w1.test/', 'news/offers', 'https://w1.test/news/offers'],
            'insecure store' => ['http://w1.test/', 'news/offers', 'http://w1.test/news/offers'],
            'base without trailing slash' => ['https://w1.test', 'news/offers', 'https://w1.test/news/offers'],
            'base with a path and a slashed identifier' => ['https://w1.test/br/', '//news//offers/', 'https://w1.test/br/news/offers'],
            'special characters are encoded' => ['https://w1.test/', 'promoção/50% off?x=1#a', 'https://w1.test/promo%C3%A7%C3%A3o/50%25%20off%3Fx%3D1%23a'],
            'home with a leading slash' => ['https://w1.test/', '/home', 'https://w1.test/'],
        ];
    }

    #[DataProvider('urls')]
    public function testTheUrlIsBuiltFromTheStoreBaseUrlAndTheEncodedIdentifier(string $base, string $identifier, string $expected): void
    {
        $document = $this->buildWith(
            $this->page('<p>x</p>', $identifier),
            new ContentTypeState(3, 'news', true),
            new TermState(9, 2, '1/4/9', [1, 4, 9], true),
            $this->store($base)
        );
        $this->assertSame($expected, $document['url']);
    }

    public function testTheImageUsesTheMediaUrlOfTheStoreView(): void
    {
        $document = $this->buildWith(
            $this->page('<img src="{{media url=&quot;a/b c.jpg&quot;}}">'),
            new ContentTypeState(3, 'news', true),
            new TermState(9, 2, '1/4/9', [1, 4, 9], true),
            $this->store('http://w1.test/', 'http://w1.test/pub/media/')
        );
        $this->assertSame('http://w1.test/pub/media/a/b%20c.jpg', $document['image']);
    }

    public function testATextOverTheCapIsCutAtAWordBoundaryWithValidUtf8(): void
    {
        $text = $this->build($this->page('<p>' . str_repeat('ação ', 20000) . '</p>'))['text'];
        $this->assertLessThanOrEqual(DocumentBuilder::MAX_TEXT_LENGTH, mb_strlen($text));
        $this->assertGreaterThan(DocumentBuilder::MAX_TEXT_LENGTH - 16, mb_strlen($text));
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringEndsWith(' ação', $text);
    }

    public function testATextWithoutSpacesIsCutExactlyAtTheCapOnACharacter(): void
    {
        $text = $this->build($this->page('<p>' . str_repeat('ç', 70000) . '</p>'))['text'];
        $this->assertSame(DocumentBuilder::MAX_TEXT_LENGTH, mb_strlen($text));
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    public function testTheMetaDescriptionSummaryIsPlainTextAndBounded(): void
    {
        $meta = '<b>Bold</b> &amp; &lt;i&gt;enc&lt;/i&gt; {{block id="x"}}clean <script>alert(1)</script> ' . str_repeat('word ', 100);
        $summary = $this->build($this->page('<p>Body.</p>', 'x', $meta))['summary'];
        $this->assertStringStartsWith('Bold & enc clean alert(1) word', $summary);
        $this->assertStringNotContainsString('<', $summary);
        $this->assertStringNotContainsString('{{', $summary);
        $this->assertLessThanOrEqual(DocumentBuilder::SUMMARY_LENGTH + 1, mb_strlen($summary));
    }

    public function testTheContentIsExtractedOnceAndBuildsADocumentPerStoreView(): void
    {
        $extractor = new class() extends TextExtractor {
            public int $calls = 0;

            public function extract(string $html): \Thedevkitchen\CmsSearch\Model\Indexer\Text\ExtractedContent
            {
                $this->calls++;
                return parent::extract($html);
            }
        };
        $builder = new DocumentBuilder($extractor, new \Psr\Log\NullLogger());
        $page = $this->page('<p><img src="{{media url=a.jpg}}">Hello</p>');
        $prepared = $builder->prepare($page);
        $type = new ContentTypeState(3, 'news', true);
        $term = new TermState(9, 2, '1/4/9', [1, 4, 9], true);
        $one = $builder->build($page, $type, $term, $this->store('https://one.test/', 'https://one.test/media/'), $prepared);
        $two = $builder->build($page, $type, $term, $this->store('https://two.test/', 'https://two.test/media/'), $prepared);
        $this->assertSame(1, $extractor->calls);
        $this->assertSame('https://one.test/news/offers', $one['url']);
        $this->assertSame('https://two.test/media/a.jpg', $two['image']);
        $this->assertSame($one['text'], $two['text']);
    }

    public function testATruncatedExtractionIsLoggedByPageIdOnly(): void
    {
        $logger = new class() extends AbstractLogger {
            /** @var list<array{0: string, 1: string, 2: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $builder = $this->builder($logger);
        $builder->prepare($this->page('<p>SECRET-CONTENT ' . str_repeat('a ', 70000) . '</p>'));
        $this->assertCount(1, $logger->records);
        $this->assertSame(5, $logger->records[0][2]['page_id']);
        $this->assertStringNotContainsString('SECRET', json_encode($logger->records));
        $quiet = new $logger();
        $this->builder($quiet)->prepare($this->page('<p>short</p>'));
        $this->assertSame([], $quiet->records);
    }

    public function testAnInvalidUpdateTimeIsNotIndexed(): void
    {
        $page = new PageSnapshot(5, 'x', 'T', '', '<p>x</p>', '', true, [2], 'not a date');
        $this->assertNull($this->build($page)['updated_at']);
        $page = new PageSnapshot(5, 'x', 'T', '', '<p>x</p>', '', true, [2], null);
        $this->assertNull($this->build($page)['updated_at']);
    }

    public function testPreparedContentOfAnotherPageIsRefusedWithoutLeakingContent(): void
    {
        $builder = $this->builder();
        $other = new PageSnapshot(6, 'x', 'T', '', '<p>PRIVATE-TEXT</p>', '', true, [2], null);
        $prepared = $builder->prepare($other);
        try {
            $builder->build($this->page('<p>x</p>'), new ContentTypeState(3, 'news', true), new TermState(9, 2, '1/4/9', [1, 4, 9], true), $this->store(), $prepared);
            $this->fail('a LogicException was expected');
        } catch (\LogicException $e) {
            $this->assertStringNotContainsString('PRIVATE', $e->getMessage());
            $this->assertStringContainsString('6', $e->getMessage());
        }
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeImages(): array
    {
        return [
            'quote' => ['https://cdn.test/a".png'],
            'angle bracket' => ['https://cdn.test/<a>.png'],
            'space' => ['https://cdn.test/a b.png'],
            'single quote' => ["https://cdn.test/a'.png"],
        ];
    }

    #[DataProvider('unsafeImages')]
    public function testAnAbsoluteImageUrlWithUnsafeCharactersIsDropped(string $url): void
    {
        $this->assertNull($this->build($this->page('<img src="' . htmlspecialchars($url, ENT_QUOTES) . '">'))['image']);
    }

    public function testTheConstructorOfTheBuilderRequiresTheExtractorAndTheLogger(): void
    {
        $parameters = (new \ReflectionClass(DocumentBuilder::class))->getConstructor()->getParameters();
        $this->assertSame(['textExtractor', 'logger'], array_map(static fn ($p): string => $p->getName(), $parameters));
        foreach ($parameters as $parameter) {
            $this->assertFalse($parameter->isOptional() || $parameter->allowsNull());
        }
    }
}
