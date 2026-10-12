<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ContentType;

use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;
use Thedevkitchen\CmsSearch\Exception\ContentTypeInUseException;

class DeleteGuard
{
    private const SAMPLE_SIZE = 5;

    public function __construct(private readonly UsageCounter $usageCounter)
    {
    }

    /**
     * @throws ContentTypeInUseException when CMS Pages are linked to the type
     */
    public function assertDeletable(ContentTypeInterface $type): void
    {
        $typeId = $type->getTypeId();
        if ($typeId === null) {
            return;
        }

        $count = $this->usageCounter->count($typeId);
        if ($count === 0) {
            return;
        }

        $pages = $this->usageCounter->firstPages($typeId, self::SAMPLE_SIZE);
        $list = implode(', ', array_map(
            static fn (array $page): string => $page['identifier'] === ''
                ? (string) $page['title']
                : sprintf('%s [%s]', $page['title'], $page['identifier']),
            $pages
        ));
        if ($count > count($pages)) {
            $list .= ', ' . __('and %1 more', $count - count($pages));
        }

        throw new ContentTypeInUseException(
            __(
                'The content type "%1" cannot be deleted: it is linked to %2 CMS page(s) (%3). '
                . 'Unlink the pages or deactivate the type.',
                $type->getLabel(0),
                $count,
                $list
            )
        );
    }
}
