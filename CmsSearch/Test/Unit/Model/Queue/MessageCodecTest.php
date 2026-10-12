<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\Queue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;
use Thedevkitchen\CmsSearch\Model\Queue\MessageCodec;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;

class MessageCodecTest extends TestCase
{
    private const VALID = ['entity_type' => 'page', 'entity_id' => 7, 'action' => 'save', 'store_id' => null,
        'website_id' => null, 'timestamp' => 1760000000, 'attempt' => 0];

    /**
     * @param array<string, mixed> $changes
     * @param string[] $remove
     */
    private static function body(array $changes = [], array $remove = []): string
    {
        $data = array_merge(self::VALID, $changes);
        foreach ($remove as $field) {
            unset($data[$field]);
        }
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    public function testEncodeWritesTheDocumentedFieldsInOrder(): void
    {
        $this->assertSame(
            '{"entity_type":"page","entity_id":7,"action":"save","store_id":null,"website_id":null,"timestamp":1760000000,"attempt":0}',
            (new MessageCodec())->encode(new ReindexMessage('page', 7, 'save', 1760000000))
        );
    }

    public function testDecodeReadsWhatEncodeWrote(): void
    {
        $codec = new MessageCodec();
        $message = new ReindexMessage('classification_term', 12, 'save', 1760000001, 3, 2, 2);
        $this->assertSame($message->toArray(), $codec->decode($codec->encode($message))->toArray());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidBodies(): array
    {
        return [
            'not JSON' => ['not-json', 'not a valid JSON document'],
            'a JSON string' => ['"page"', 'must be a JSON object'],
            'a JSON list' => ['[1,2]', 'must be a JSON object'],
            'an empty object' => ['{}', 'must be a JSON object'],
            'missing field' => [self::body([], ['attempt']), 'exactly these fields'],
            'extra field' => [self::body(['content' => 'x']), 'exactly these fields'],
            'id as a string' => [self::body(['entity_id' => '7']), 'field "entity_id"'],
            'store as a string' => [self::body(['store_id' => '1']), 'field "store_id"'],
            'attempt as a float' => [self::body(['attempt' => 1.5]), 'field "attempt"'],
            'nested value' => [self::body(['entity_type' => ['page']]), 'field "entity_type"'],
            'boolean id' => [self::body(['entity_id' => true]), 'field "entity_id"'],
            'unknown entity type' => [self::body(['entity_type' => 'blog']), 'Unknown entity type'],
            'zero id' => [self::body(['entity_id' => 0]), 'positive integer'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function testDecodeRejectsAnythingButTheExactDocument(string $body, string $message): void
    {
        $this->expectException(InvalidReindexMessageException::class);
        $this->expectExceptionMessage($message);
        (new MessageCodec())->decode($body);
    }
}
