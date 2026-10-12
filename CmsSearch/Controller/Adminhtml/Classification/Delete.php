<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\Input\StrictInput;

class Delete extends AbstractClassification implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_MANAGE;

    public function __construct(
        Context $context,
        private readonly ClassificationTermRepositoryInterface $repository,
        private readonly StrictInput $input,
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
        try {
            $termId = (int) $this->input->optionalId($this->getRequest()->getParam('term_id'));
        } catch (InputException) {
            $termId = 0;
        }
        if ($termId <= 0) {
            $this->messageManager->addErrorMessage(__('We can\'t find a classification term to delete.'));
            return $redirect->setPath('*/*/index');
        }
        try {
            $term = $this->repository->getById($termId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This classification term no longer exists.'));
            return $redirect->setPath('*/*/index');
        }

        try {
            $this->repository->delete($term);
            $this->messageManager->addSuccessMessage(__('You deleted the classification term.'));
            return $redirect->setPath('*/*/index', ['website_id' => $term->getWebsiteId()]);
        } catch (LocalizedException $exception) {
            // Includes ClassificationTermInUseException: the message says why (children or linked pages).
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('Something went wrong while deleting the classification term.'));
        }
        return $redirect->setPath('*/*/edit', ['term_id' => $termId]);
    }
}
