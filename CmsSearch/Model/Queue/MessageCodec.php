<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Exception\InvalidReindexMessageException;

/**
 * Wire format of the topic (communication.xml declares request="string"): one JSON object with exactly the seven
 * fields of ReindexMessageInterface::toArray(), strictly typed (flat document, depth 3). Duplicate JSON keys are an
 * accepted limitation: json_decode keeps the last one.  Anything else is a permanent error (DLQ, no retry).
 */
class MessageCodec
{
    /** field => allowed gettype() results after json_decode */
    private const FIELDS = [
        'entity_type' => ['string'],
        'entity_id' => ['integer'],
        'action' => ['string'],
        'store_id' => ['integer', 'NULL'],
        'website_id' => ['integer', 'NULL'],
        'timestamp' => ['integer'],
        'attempt' => ['integer'],
    ];

    public function encode(ReindexMessageInterface $message): string
    {
        return json_encode($message->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws InvalidReindexMessageException
     */
    public function decode(string $body): ReindexMessageInterface
    {
        try {
            $data = json_decode($body, true, 3, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidReindexMessageException('The reindex message is not a valid JSON document.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidReindexMessageException('The reindex message must be a JSON object.');
        }
        if (array_diff_key(self::FIELDS, $data) !== [] || array_diff_key($data, self::FIELDS) !== []) {
            throw new InvalidReindexMessageException(sprintf(
                'The reindex message must have exactly these fields: %s.',
                implode(', ', array_keys(self::FIELDS))
            ));
        }
        foreach (self::FIELDS as $field => $types) {
            if (!in_array(gettype($data[$field]), $types, true)) {
                throw new InvalidReindexMessageException(
                    sprintf('The field "%s" of the reindex message has the wrong type.', $field)
                );
            }
        }
        return new ReindexMessage(
            $data['entity_type'],
            $data['entity_id'],
            $data['action'],
            $data['timestamp'],
            $data['store_id'],
            $data['website_id'],
            $data['attempt']
        );
    }
}
