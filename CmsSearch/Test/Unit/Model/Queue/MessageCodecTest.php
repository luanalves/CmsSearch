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
            'null body' => ['null', 'must be a JSON object'],
            'empty body' => ['', 'not a valid JSON document'],
            'number body' => ['7', 'must be a JSON object'],
            'empty list body' => ['[]', 'must be a JSON object'],
            'list body' => ['[1]', 'must be a JSON object'],
            'numeric key body' => ['{"0":1}', 'must be a JSON object'],
            'too deep' => ['{"a":{"b":{"c":1}}}', 'not a valid JSON document'],
            'attempt 101' => [self::body(['attempt' => 101]), 'between 0 and 100'],
            'negative store' => [self::body(['store_id' => -1]), 'store id must be'],
            'negative timestamp' => [self::body(['timestamp' => -1]), 'timestamp must be between'],
            'zero timestamp' => [self::body(['timestamp' => 0]), 'timestamp must be between'],
            'huge int id (float on the wire)' => ['{"entity_type":"page","entity_id":1e30,"action":"save","store_id":null,"website_id":null,"timestamp":1760000000,"attempt":0}', 'field "entity_id"'],
            'huge digits id' => ['{"entity_type":"page","entity_id":99999999999999999999,"action":"save","store_id":null,"website_id":null,"timestamp":1760000000,"attempt":0}', 'field "entity_id"'],
            'id PHP_INT_MAX' => [self::body(['entity_id' => PHP_INT_MAX]), 'at most 32767'],
            'page id 32768' => [self::body(['entity_id' => 32768]), 'at most 32767'],
            'store 65536' => [self::body(['store_id' => 65536]), 'at most 65535'],
            'website 65536' => [self::body(['website_id' => 65536]), 'at most 65535'],
            'timestamp PHP_INT_MAX' => [self::body(['timestamp' => PHP_INT_MAX]), 'timestamp must be between'],
            'numeric string keys instead of fields' => ['{"0":"page","1":7,"2":"save","3":null,"4":null,"5":1,"6":0}', 'must be a JSON object'],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function wrongTypes(): array
    {
        $allowed = ['entity_type' => ['string'], 'entity_id' => ['integer'], 'action' => ['string'],
            'store_id' => ['integer', 'NULL'], 'website_id' => ['integer', 'NULL'], 'timestamp' => ['integer'],
            'attempt' => ['integer']];
        $values = ['string' => '1', 'boolean' => true, 'array' => [1], 'NULL' => null, 'double' => 1.5];
        $cases = [];
        foreach ($allowed as $field => $types) {
            foreach ($values as $type => $value) {
                if (!in_array($type, $types, true)) {
                    $cases[$field . ' as ' . $type] = [self::body([$field => $value]), 'field "' . $field . '"'];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('wrongTypes')]
    #[DataProvider('invalidBodies')]
    public function testDecodeRejectsAnythingButTheExactDocument(string $body, string $message): void
    {
        $this->expectException(InvalidReindexMessageException::class);
        $this->expectExceptionMessage($message);
        (new MessageCodec())->decode($body);
    }
}
