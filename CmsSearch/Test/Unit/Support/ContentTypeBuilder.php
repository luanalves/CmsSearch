<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Support;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\Generator\Generator;
use Thedevkitchen\CmsSearch\Model\ContentType;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType as ContentTypeResource;

/**
 * Builds ContentType entities for unit tests without touching the ObjectManager.
 */
final class ContentTypeBuilder
{
    /**
     * @param array<int, string> $labels
     */
    public static function build(?int $id, string $code, array $labels = [0 => 'News']): ContentType
    {
        $generator = new Generator();
        // A resource instance keeps AbstractModel::_init() from touching the ObjectManager.
        $resource = $generator->testDouble(ContentTypeResource::class, true, callOriginalConstructor: false);
        $resource->method('getIdFieldName')->willReturn('type_id');
        $type = new ContentType(
            $generator->testDouble(Context::class, true, callOriginalConstructor: false),
            $generator->testDouble(Registry::class, true, callOriginalConstructor: false),
            $resource
        );
        if ($id !== null) {
            $type->setTypeId($id);
        }
        return $type->setCode($code)->setLabels($labels);
    }
}
