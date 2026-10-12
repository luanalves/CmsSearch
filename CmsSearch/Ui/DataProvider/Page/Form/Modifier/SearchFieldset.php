<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Ui\DataProvider\Page\Form\Modifier;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\Data\PageClassificationInterface;
use Thedevkitchen\CmsSearch\Api\PageClassificationRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\PageClassification\FormFields;
use Thedevkitchen\CmsSearch\Model\PageClassification\PageWebsites;
use Thedevkitchen\CmsSearch\Model\Source\ClassificationTermOptions;
use Thedevkitchen\CmsSearch\Model\Source\PageContentTypeOptions;

/**
 * Adds the "Search" fieldset (content type + classification term) to cms_page_form.
 * Values are filled by Plugin\Cms\PageFormDataPlugin; the save is handled by Observer\SavePageClassification.
 */
class SearchFieldset implements ModifierInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageClassificationRepositoryInterface $classifications,
        private readonly PageWebsites $pageWebsites,
        private readonly PageContentTypeOptions $typeOptions,
        private readonly ClassificationTermOptions $termOptions,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    public function modifyData(array $data): array
    {
        return $data;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function modifyMeta(array $meta): array
    {
        try {
            $meta[FormFields::FIELDSET] = $this->fieldset();
        } catch (\Throwable $exception) {
            // Non-essential add-on: the native Page form opens without the fieldset (and so cannot post an empty
            // pair over a link it could not show).
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
        }
        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldset(): array
    {
        $pageId = $this->pageId();
        $storeIds = [];
        $current = null;
        if ($pageId > 0) {
            try {
                $storeIds = $this->pageWebsites->storeIdsOf($this->pageRepository->getById($pageId));
            } catch (NoSuchEntityException) {
                $storeIds = [];
            }
            $current = $this->currentLink($pageId);
        }
        // A new Page (or one without store views yet) can still go to any website: offer the terms of all of them.
        $websiteIds = $this->pageWebsites->fromStoreIds($storeIds ?: [0]);

        return [
            'arguments' => ['data' => ['config' => [
                'componentType' => 'fieldset',
                'label' => __('Search'),
                'collapsible' => true,
                'opened' => false,
                'sortOrder' => 25,
            ]]],
            'children' => [
                FormFields::TYPE => $this->select(
                    FormFields::TYPE,
                    (string) __('Content Type'),
                    $this->typeOptions->toOptionArrayWithCurrent($current?->getTypeId()),
                    10,
                    (string) __('Pages without a content type and a search classification are not shown in the search.')
                ),
                FormFields::TERM => $this->select(
                    FormFields::TERM,
                    (string) __('Search Classification'),
                    $this->termOptions->forWebsites($websiteIds, $current?->getTermId()),
                    20,
                    (string) __('Terms of the websites of the page\'s store views. After changing the store views, save the page to refresh this list.')
                ),
                FormFields::MARKER => ['arguments' => ['data' => ['config' => [
                    'componentType' => 'field',
                    'formElement' => 'input',
                    'dataType' => 'text',
                    'dataScope' => FormFields::MARKER,
                    'visible' => false,
                    'default' => '1',
                    'sortOrder' => 30,
                ]]]],
            ],
        ];
    }

    /**
     * @param list<array{value: int, label: string}> $options
     * @return array<string, mixed>
     */
    private function select(string $field, string $label, array $options, int $sortOrder, string $notice): array
    {
        return ['arguments' => ['data' => ['config' => [
            'componentType' => 'field',
            'formElement' => 'select',
            'dataType' => 'text',
            'label' => $label,
            'dataScope' => $field,
            'options' => $options,
            'caption' => (string) __('-- Please Select --'),
            'notice' => $notice,
            'sortOrder' => $sortOrder,
        ]]]];
    }

    private function currentLink(int $pageId): ?PageClassificationInterface
    {
        try {
            return $this->classifications->getByPageId($pageId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function pageId(): int
    {
        $value = (string) $this->request->getParam('page_id');
        return ctype_digit($value) ? (int) $value : 0;
    }
}
