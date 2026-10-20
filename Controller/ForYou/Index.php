<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Controller\ForYou;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * zeropersonalize/foryou: the signed-in customer's picks for the home
 * page's slot; empty for anyone else. Never cached.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly ResultFactory $resultFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_LAYOUT);
        $result->setHeader('Cache-Control', 'no-store, private', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);
        return $result;
    }
}
