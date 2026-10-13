<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Reference;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Indexer\TextExtractor;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReader;
use Thedevkitchen\CmsSearch\Model\Reference\BlockReferenceResolver;
use Thedevkitchen\CmsSearch\Model\Reference\BlockTextResolver;
use Thedevkitchen\CmsSearch\Model\Reference\Data\BlockSource;
use Thedevkitchen\CmsSearch\Model\Reference\Data\HostEnrichment;
use Thedevkitchen\CmsSearch\Model\Reference\DirectiveParser;
use Thedevkitchen\CmsSearch\Model\Reference\ReferenceStore;
use Thedevkitchen\CmsSearch\Test\Unit\Support\CountingTextExtractor;
use Thedevkitchen\CmsSearch\Test\Unit\Support\RecordingLogger;

class BlockTextResolverTest extends TestCase
{
    private ReferenceStore&MockObject $store;
    private BlockReader&MockObject $blocks;
    /** @var list<list<string>> ref keys of each BlockReader call */
    private array $reads = [];

    protected function setUp(): void
    {
        $this->store = $this->createMock(ReferenceStore::class);
        $this->blocks = $this->createMock(BlockReader::class);
    }

    private ?CountingTextExtractor $extractor = null;
    private ?RecordingLogger $logger = null;

    private function resolver(): BlockTextResolver
    {
        $this->extractor = new CountingTextExtractor();
        $this->logger = new RecordingLogger();
        return new BlockTextResolver($this->store, $this->blocks, new DirectiveParser($this->extractor), $this->extractor, $this->logger);
    }

    private static function ref(string $kind, int $id, string $identifier = '', ?string $segments = null): array
    {
        return ['block_kind' => $kind, 'block_id' => $id, 'block_identifier' => $identifier, 'segment_ids' => $segments];
    }

    private function blocksFrom(array $sources): void
    {
        $this->blocks->method('read')->willReturnCallback(function (array $refs, int $storeId) use ($sources): array {
            $this->assertSame(2, $storeId);
            $this->reads[] = array_map(static fn ($r): string => $r->key(), $refs);
            $out = [];
            foreach ($refs as $ref) {
                if (isset($sources[$ref->key()])) {
                    $out[$ref->key()] = $sources[$ref->key()];
                }
            }
            return $out;
        });
    }

    /** @param array<int, HostEnrichment> $result */
    private static function texts(array $result): array
    {
        return array_map(static fn (HostEnrichment $e): array => [$e->getText(), $e->getSegmentIds()], $result);
    }

    public function testTheTextOfEachHostsBlocksInOrder(): void
    {
        $this->store->expects($this->once())->method('directRefs')->with('page', [10, 11, 12], 2)->willReturn([
            10 => [self::ref('block', 1), self::ref('dynamic_block', 3)],
            11 => [self::ref('block', 1)],
        ]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>Alpha</p>'),
            'dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '<div>Gamma</div>'),
        ]);
        $this->assertSame(
            [10 => ['Alpha Gamma', []], 11 => ['Alpha', []], 12 => ['', []]],
            self::texts($this->resolver()->forHosts('page', [10, 11, 12, 12], 2))
        );
        $this->assertCount(1, $this->reads);
    }

    public function testABlockTheStoreViewDoesNotShowAddsNothing(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 2)]]);
        $this->blocksFrom([]);
        $this->assertSame([10 => ['', []]], self::texts($this->resolver()->forHosts('category', [10], 2)));
    }

    public function testSegmentRestrictedDynamicBlocksAddNoTextButTheirSegments(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('dynamic_block', 3, '', '4,2'), self::ref('dynamic_block', 5, '', '4'), self::ref('block', 1)]]);
        $this->blocksFrom([
            'dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '<p>Members only</p>', [2, 4]),
            'dynamic_block#5' => new BlockSource('dynamic_block', 5, '', '<p>VIP</p>', [4]),
            'block#1' => new BlockSource('block', 1, 'a', '<p>Public</p>'),
        ]);
        $this->assertSame([10 => ['Public', [2, 4]]], self::texts($this->resolver()->forHosts('page', [10], 2)));
    }

    public function testRuleRestrictedDynamicBlocksAddNoTextAndAreNotFollowed(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('dynamic_block', 3), self::ref('block', 1)]]);
        $this->blocksFrom([
            'dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '<p>COUPON10</p>{{block id="9"}}', [], true),
            'block#9' => new BlockSource('block', 9, 'n', '<p>Nested promo</p>'),
            'block#1' => new BlockSource('block', 1, 'a', '<p>Public</p>'),
        ]);
        $this->assertSame([10 => ['Public', []]], self::texts($this->resolver()->forHosts('page', [10], 2)));
        $this->assertSame([['dynamic_block#3', 'block#1']], $this->reads, 'children of an excluded block are not read');
    }

    public function testABlockSharedByManyHostsIsExtractedOncePerCall(): void
    {
        $refs = [];
        foreach (range(10, 209) as $hostId) {
            $refs[$hostId] = [self::ref('block', 1)];
        }
        $this->store->method('directRefs')->willReturn($refs);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>Shared</p>{{block id="2"}}'),
            'block#2' => new BlockSource('block', 2, 'b', '<p>Nested shared</p>'),
        ]);
        $result = $this->resolver()->forHosts('page', range(10, 209), 2);
        $this->assertSame(['Shared Nested shared', []], self::texts($result)[209]);
        $this->assertSame(
            ['<p>Shared</p>{{block id="2"}}' => 1, '<p>Nested shared</p>' => 1],
            $this->extractor->calls,
            'one extraction per block and call, not per host'
        );
        $this->assertCount(2, $this->reads, 'one BlockReader call per level');
    }

    public function testABlockWhoseExtractionFailsIsSkippedForEveryHostAndLoggedByIdOnce(): void
    {
        $this->store->method('directRefs')->willReturn([
            10 => [self::ref('block', 1), self::ref('block', 2)],
            11 => [self::ref('block', 1)],
        ]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>SECRET ' . CountingTextExtractor::FAIL_MARKER . '</p>{{block id="3"}}'),
            'block#2' => new BlockSource('block', 2, 'b', '<p>Fine</p>'),
            'block#3' => new BlockSource('block', 3, 'c', '<p>Child of broken</p>'),
        ]);
        $result = $this->resolver()->forHosts('page', [10, 11], 2);
        $this->assertSame([10 => ['Fine', []], 11 => ['', []]], self::texts($result));
        $this->assertCount(1, $this->logger->records, 'logged once per block and call');
        $this->assertSame('warning', $this->logger->records[0][0]);
        $this->assertSame(['block_kind' => 'block', 'block_id' => 1], $this->logger->records[0][2]);
        $this->assertStringNotContainsString('SECRET', json_encode($this->logger->records));
        $this->assertNotContains('block#3', array_merge(...$this->reads), 'children of a broken block are not followed');
    }

    public function testOtherFailuresOfTheExtractorStillPropagate(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1)]]);
        $this->blocksFrom(['block#1' => new BlockSource('block', 1, 'a', '<p>x</p>')]);
        $extractor = new class() extends TextExtractor {
            public function extract(string $html): \Thedevkitchen\CmsSearch\Model\Indexer\Text\ExtractedContent
            {
                throw new \RuntimeException('out of memory');
            }
        };
        $this->expectExceptionMessage('out of memory');
        (new BlockTextResolver($this->store, $this->blocks, new DirectiveParser($extractor), $extractor, new RecordingLogger()))
            ->forHosts('page', [10], 2);
    }

    public function testNestedBlocksAreFollowed(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1)]]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>A</p>{{block id="b"}}'),
            'block@b' => new BlockSource('block', 2, 'b', '<p>B</p>'),
        ]);
        $this->assertSame([10 => ['A B', []]], self::texts($this->resolver()->forHosts('page', [10], 2)));
        $this->assertSame([['block#1'], ['block@b']], $this->reads);
    }

    public function testACycleAddsEachBlockOnce(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1)]]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>A</p>{{block id="b"}}'),
            'block@b' => new BlockSource('block', 2, 'b', '<p>B</p>{{block id="1"}}{{block id="a"}}'),
            'block@a' => new BlockSource('block', 1, 'a', '<p>A</p>{{block id="b"}}'),
        ]);
        $this->assertSame([10 => ['A B', []]], self::texts($this->resolver()->forHosts('page', [10], 2)));
        $this->assertSame([['block#1'], ['block@b'], ['block@a']], $this->reads);
    }

    public function testNestingStopsAtTheDepthLimit(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1)]]);
        $chain = [];
        for ($i = 1; $i <= 8; $i++) {
            $chain['block#' . $i] = new BlockSource('block', $i, 'b' . $i, '<p>T' . $i . '</p>{{block id="' . ($i + 1) . '"}}');
        }
        $this->blocksFrom($chain);
        $this->assertSame([10 => ['T1 T2 T3 T4 T5 T6', []]], self::texts($this->resolver()->forHosts('page', [10], 2)));
        $this->assertCount(BlockReferenceResolver::MAX_DEPTH + 1, $this->reads);
    }

    public function testIdentifierRowsAreResolvedAgainByIdentifier(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 7, 'Footer')]]);
        $this->blocksFrom(['block@footer' => new BlockSource('block', 8, 'footer', '<p>Renamed target</p>')]);
        $this->assertSame([10 => ['Renamed target', []]], self::texts($this->resolver()->forHosts('page', [10], 2)));
        $this->assertSame([['block@footer']], $this->reads);
    }

    public function testNoHostNoQuery(): void
    {
        $this->store->expects($this->never())->method('directRefs');
        $this->blocks->expects($this->never())->method('read');
        $this->assertSame([], $this->resolver()->forHosts('page', [0, -1], 2));
    }

    public function testHostTextIsCappedAndFlaggedTruncated(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1), self::ref('block', 2)], 11 => [self::ref('block', 2)]]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>' . str_repeat('word ', 20000) . '</p>'),
            'block#2' => new BlockSource('block', 2, 'b', '<p>Small</p>'),
        ]);
        $result = $this->resolver()->forHosts('page', [10, 11], 2);
        $this->assertLessThanOrEqual(BlockTextResolver::MAX_TEXT_CHARS, mb_strlen($result[10]->getText()));
        $this->assertGreaterThan(BlockTextResolver::MAX_TEXT_CHARS - 10, mb_strlen($result[10]->getText()));
        $this->assertStringNotContainsString('Small', $result[10]->getText());
        $this->assertTrue($result[10]->isTruncated());
        $this->assertSame('Small', $result[11]->getText());
        $this->assertFalse($result[11]->isTruncated());
    }

    public function testSegmentsAreStillCollectedAfterTheCapIsReached(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1), self::ref('dynamic_block', 3)]]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>' . str_repeat('word ', 20000) . '</p>'),
            'dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '<p>VIP</p>', [9]),
        ]);
        $this->assertSame([9], $this->resolver()->forHosts('page', [10], 2)[10]->getSegmentIds());
    }

    public function testOrderIsDocumentOrderThenBreadthFirstAndStable(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 3), self::ref('block', 1), self::ref('block', 2)]]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>A</p>{{block id="9"}}'),
            'block#2' => new BlockSource('block', 2, 'b', '<p>B</p>'),
            'block#3' => new BlockSource('block', 3, 'c', '<p>C</p>'),
            'block#9' => new BlockSource('block', 9, 'n', '<p>N</p>'),
        ]);
        $first = self::texts($this->resolver()->forHosts('page', [10], 2));
        $this->assertSame([10 => ['C A B N', []]], $first);
        $this->assertSame($first, self::texts($this->resolver()->forHosts('page', [10], 2)));
    }

    public function testTheSameBlockReferencedTwiceAndByAnotherHostIsCountedOncePerHost(): void
    {
        $this->store->method('directRefs')->willReturn([
            10 => [self::ref('block', 1), self::ref('block', 1), self::ref('block', 1, 'A')],
            11 => [self::ref('block', 1)],
        ]);
        $this->blocksFrom(['block#1' => new BlockSource('block', 1, 'a', '<p>Once</p>'), 'block@a' => new BlockSource('block', 1, 'a', '<p>Once</p>')]);
        $this->assertSame([10 => ['Once', []], 11 => ['Once', []]], self::texts($this->resolver()->forHosts('page', [10, 11], 2)));
    }

    public function testNestedSegmentRestrictedBlocksReportTheirSegmentsSortedAndUnique(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1), self::ref('dynamic_block', 3)]]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>A</p>{{widget type="Magento\\Banner\\Block\\Widget\\Banner" display_mode="fixed" banner_ids="5"}}'),
            'dynamic_block#5' => new BlockSource('dynamic_block', 5, '', '<p>Hidden</p>', [7, 2, 4]),
            'dynamic_block#3' => new BlockSource('dynamic_block', 3, '', '<p>Hidden2</p>', [9, 4]),
        ]);
        $this->assertSame([10 => ['A', [2, 4, 7, 9]]], self::texts($this->resolver()->forHosts('page', [10], 2)));
    }

    public function testHiddenContentOfABlockIsNotPublishedNorFollowed(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1)]]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>Shown</p><div style="display:none">Secret {{block id="b"}}</div><div hidden>Gone</div>'),
            'block@b' => new BlockSource('block', 2, 'b', '<p>Nested secret</p>'),
        ]);
        $this->assertSame([10 => ['Shown', []]], self::texts($this->resolver()->forHosts('page', [10], 2)));
        $this->assertSame([['block#1']], $this->reads);
    }

    public function testOutputIsPlainTextOnlyWhateverTheBlockContains(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1)]]);
        $this->blocksFrom(['block#1' => new BlockSource(
            'block',
            1,
            'a',
            '<p>Hi</p><script>alert(1)</script><style>p{color:red}</style><p>a &amp; b &eacute;</p>{{config path="web/secure/base_url"}}{{block id="self-host"}}<img src="x" onerror="alert(1)">'
        )]);
        $text = $this->resolver()->forHosts('page', [10], 2)[10]->getText();
        $this->assertSame('Hi a & b é', $text);
        $this->assertStringNotContainsString('{{', $text);
    }

    public function testRowsOfAnUnknownKindAreIgnored(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('weird', 1), self::ref('block', 0, ''), self::ref('block', 1)]]);
        $this->blocksFrom(['block#1' => new BlockSource('block', 1, 'a', '<p>Ok</p>')]);
        $this->assertSame([10 => ['Ok', []]], self::texts($this->resolver()->forHosts('page', [10], 2)));
    }

    public function testDifferentBlocksWithTheSameTextBothContribute(): void
    {
        $this->store->method('directRefs')->willReturn([10 => [self::ref('block', 1), self::ref('block', 2)]]);
        $this->blocksFrom([
            'block#1' => new BlockSource('block', 1, 'a', '<p>Same</p>'),
            'block#2' => new BlockSource('block', 2, 'b', '<p>Same</p>'),
        ]);
        $this->assertSame([10 => ['Same Same', []]], self::texts($this->resolver()->forHosts('page', [10], 2)));
    }
}
