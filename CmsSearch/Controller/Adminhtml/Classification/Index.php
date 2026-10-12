<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends AbstractClassification implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_VIEW;

    public function __construct(Context $context, private readonly PageFactory $resultPageFactory)
    {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Thedevkitchen_CmsSearch::classification');
        $page->getConfig()->getTitle()->prepend(__('Classification'));
        return $page;
    }
}
