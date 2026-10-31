<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Controller\Adminhtml\Training;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Zero\Personalize\Model\Pipeline;

/**
 * Train Now: a run started (the export and the first AWS calls, seconds);
 * the cron takes it on from there. A POST only (a run bills AWS): the
 * configuration page's buttons post it with the form key (mage/dataPost, as
 * that page is a form itself); the admin URL's secret key guards it too.
 */
class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Zero_Personalize::config';

    public function __construct(
        Action\Context $context,
        private readonly Pipeline $pipeline
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        try {
            $state = $this->pipeline->start((bool) $this->getRequest()->getParam('demo'));
            if ($state['step'] === 'failed') {
                $this->messageManager->addErrorMessage(__('The training run failed: %1', $state['error']));
            } else {
                $this->messageManager->addSuccessMessage(__('Training started. It takes one to three hours; this page shows how far it is.'));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', ['section' => 'zero_personalize']);
    }
}
