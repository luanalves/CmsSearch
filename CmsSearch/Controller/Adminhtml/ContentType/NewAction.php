<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Forward;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;

class NewAction extends AbstractContentType implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = self::RESOURCE_CREATE;

    public function __construct(Context $context, private readonly ForwardFactory $forwardFactory)
    {
        parent::__construct($context);
    }

    public function execute(): Forward
    {
        return $this->forwardFactory->create()->forward('edit');
    }
}
