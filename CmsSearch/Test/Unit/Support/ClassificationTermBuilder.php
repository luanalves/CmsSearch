<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Support;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Thedevkitchen\CmsSearch\Model\ClassificationTerm;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;

/**
 * Builds ClassificationTerm entities for unit tests without the ObjectManager (AbstractModel needs a resource).
 */
final class ClassificationTermBuilder
{
    /**
     * @param callable(class-string): object $createMock e.g. fn (string $c) => $this->createMock($c)
     * @param array<int, string> $labels
     */
    public static function build(
        callable $createMock,
        ?int $id,
        int $websiteId,
        string $code,
        ?int $parentId = null,
        array $labels = [0 => 'Agro']
    ): ClassificationTerm {
        $resource = $createMock(TermResource::class);
        $resource->method('getIdFieldName')->willReturn('term_id');
        $term = new ClassificationTerm($createMock(Context::class), $createMock(Registry::class), $resource);
        if ($id !== null) {
            $term->setTermId($id);
        }
        return $term->setWebsiteId($websiteId)->setCode($code)->setParentId($parentId)->setLabels($labels)
            ->setIsActive(true);
    }
}
