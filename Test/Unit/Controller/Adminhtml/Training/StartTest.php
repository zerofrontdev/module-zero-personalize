<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Test\Unit\Controller\Adminhtml\Training;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use PHPUnit\Framework\TestCase;
use Zero\Personalize\Controller\Adminhtml\Training\Start;

/**
 * Train Now bills AWS: a POST only, so a link or an image in a page can't start a run.
 */
class StartTest extends TestCase
{
    public function testTrainNowIsAPostOnly(): void
    {
        $start = new \ReflectionClass(Start::class);

        $this->assertFalse($start->implementsInterface(HttpGetActionInterface::class));
        $this->assertTrue($start->implementsInterface(HttpPostActionInterface::class));
    }
}
