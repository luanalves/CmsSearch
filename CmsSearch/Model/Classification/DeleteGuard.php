<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Classification;

use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface;
use Thedevkitchen\CmsSearch\Exception\ClassificationTermInUseException;

class DeleteGuard
{
    private const SAMPLE_SIZE = 5;

    public function __construct(private readonly UsageCounter $usageCounter)
    {
    }

    /**
     * @throws ClassificationTermInUseException when the term has child terms or linked CMS Pages
     */
    public function assertDeletable(ClassificationTermInterface $term): void
    {
        $termId = $term->getTermId();
        if ($termId === null) {
            return;
        }

        $children = $this->usageCounter->countChildren($termId);
        if ($children > 0) {
            throw new ClassificationTermInUseException(
                __(
                    'The classification term "%1" cannot be deleted: it has %2 child term(s). '
                    . 'Move or delete the child terms first.',
                    $term->getLabel(0),
                    $children
                )
            );
        }

        $count = $this->usageCounter->countPages($termId);
        if ($count === 0) {
            return;
        }
        $pages = $this->usageCounter->firstPages($termId, self::SAMPLE_SIZE);
        $list = implode(', ', array_map(
            static fn (array $page): string => $page['identifier'] === ''
                ? (string) $page['title']
                : sprintf('%s [%s]', $page['title'], $page['identifier']),
            $pages
        ));
        if ($count > count($pages)) {
            $list .= ', ' . __('and %1 more', $count - count($pages));
        }
        throw new ClassificationTermInUseException(
            __(
                'The classification term "%1" cannot be deleted: it is linked to %2 CMS page(s) (%3). '
                . 'Unlink the pages or deactivate the term.',
                $term->getLabel(0),
                $count,
                $list
            )
        );
    }
}
