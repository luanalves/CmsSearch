<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Plugin\Cms;

use Magento\Cms\Model\Page\DataProvider as PageDataProvider;
use Magento\CmsStaging\Model\Page\DataProvider as StagingPageDataProvider;
use Magento\Framework\Exception\NoSuchEntityException;
use Thedevkitchen\CmsSearch\Api\PageClassificationRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\PageClassification\FormFields;

/**
 * Magento\Cms\Model\Page\DataProvider::getData() does not run the modifier pool's modifyData(), so the
 * current type and term of the Page are added here. The Staging "schedule update" provider (a subclass that
 * inherits this plugin) is skipped: the classification is page-level, not per version (D9).
 */
class PageFormDataPlugin
{
    public function __construct(private readonly PageClassificationRepositoryInterface $classifications)
    {
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function afterGetData(PageDataProvider $subject, $result)
    {
        if (!is_array($result) || $subject instanceof StagingPageDataProvider) {
            return $result;
        }
        foreach ($result as $key => $row) {
            if (!is_array($row) || array_key_exists(FormFields::TYPE, $row)) {
                continue; // values posted in a failed save win
            }
            $typeId = '';
            $termId = '';
            $pageId = (int) ($row['page_id'] ?? 0);
            if ($pageId > 0) {
                try {
                    $link = $this->classifications->getByPageId($pageId);
                    $typeId = (string) $link->getTypeId();
                    $termId = (string) $link->getTermId();
                } catch (NoSuchEntityException) {
                    // the Page is not classified yet
                }
            }
            $result[$key][FormFields::TYPE] = $typeId;
            $result[$key][FormFields::TERM] = $termId;
        }
        return $result;
    }
}
