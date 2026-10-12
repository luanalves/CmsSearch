<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterfaceFactory;
use Thedevkitchen\CmsSearch\Model\Classification\FormDataMapper;
use Thedevkitchen\CmsSearch\Model\Input\StrictInput;

/**
 * Create, rename, move (parent), reorder and (de)activate a term. Every rule is enforced by the repository.
 */
class Save extends AbstractClassification implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_MANAGE;
    public const PERSISTOR_KEY = 'thedevkitchen_cmssearch_classification_term';

    public function __construct(
        Context $context,
        private readonly ClassificationTermRepositoryInterface $repository,
        private readonly ClassificationTermInterfaceFactory $termFactory,
        private readonly FormDataMapper $mapper,
        private readonly StrictInput $input,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        if (!$this->_authorization->isAllowed(self::RESOURCE_MANAGE)) {
            $this->messageManager->addErrorMessage(__('You are not allowed to manage classification terms.'));
            return $redirect->setPath('*/*/index');
        }
        $data = $this->getRequest()->getPostValue();
        if (!$data) {
            return $redirect->setPath('*/*/index');
        }
        $data = (array) $data;
        $termId = 0;
        $websiteId = 0;
        try {
            // Single source of the id: the POST body (the form posts term_id). The query string is never read.
            $termId = (int) $this->input->optionalId($data['term_id'] ?? null);
            if ($termId > 0) {
                $term = $this->repository->getById($termId); // code and website are immutable: not read from the post
            } else {
                $term = $this->termFactory->create();
                $term->setCode(trim((string) ($data['code'] ?? '')));
                $term->setWebsiteId((int) $this->input->optionalId($data['website_id'] ?? null));
            }
            $websiteId = $term->getWebsiteId();
            $term->setParentId($this->input->optionalId($data['parent_id'] ?? null));
            $term->setSortOrder($this->input->sortOrder($data['sort_order'] ?? null));
            $term->setIsActive($this->input->flag($data['is_active'] ?? null));
            $term->setLabels($this->mapper->toLabels($data));
            $saved = $this->repository->save($term);

            $this->messageManager->addSuccessMessage(__('You saved the classification term.'));
            $this->dataPersistor->clear(self::PERSISTOR_KEY);
            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['term_id' => $saved->getTermId()]);
            }
            return $redirect->setPath('*/*/index', ['website_id' => $saved->getWebsiteId()]);
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('Something went wrong while saving the classification term.'));
        }

        $this->dataPersistor->set(self::PERSISTOR_KEY, $data);
        if ($termId > 0) {
            return $redirect->setPath('*/*/edit', ['term_id' => $termId]);
        }
        return $redirect->setPath('*/*/new', $websiteId > 0 ? ['website_id' => $websiteId] : []);
    }
}
