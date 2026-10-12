<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Support;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Thedevkitchen\CmsSearch\Model\ContentType;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType as ContentTypeResource;

/**
 * Builds ContentType entities for unit tests without touching the ObjectManager.
 *
 * The entity needs a mocked resource because AbstractModel::_init() falls back to the
 * ObjectManager (not initialized in unit tests) when no resource is injected.
 */
final class ContentTypeBuilder
{
    /**
     * @param callable(class-string): object $createMock the test's mock factory, e.g. fn (string $c) => $this->createMock($c)
     * @param array<int, string> $labels
     */
    public static function build(
        callable $createMock,
        ?int $id,
        string $code,
        array $labels = [0 => 'News']
    ): ContentType {
        $resource = $createMock(ContentTypeResource::class);
        $resource->method('getIdFieldName')->willReturn('type_id');
        $type = new ContentType(
            $createMock(Context::class),
            $createMock(Registry::class),
            $resource
        );
        if ($id !== null) {
            $type->setTypeId($id);
        }
        return $type->setCode($code)->setLabels($labels);
    }
}
