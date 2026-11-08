<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Test\Unit\Block\Adminhtml;

use PHPUnit\Framework\TestCase;
use Zero\Personalize\Block\Adminhtml\Status;

/**
 * The Train Now buttons post through mage/dataPost: the action, and data as a JSON object (dataPost writes the form
 * key into it; into an empty array it never does).
 */
class StatusTest extends TestCase
{
    public function testTrainNowPostsToTheStartActionWithAnObjectForTheFormKey(): void
    {
        $this->assertSame(
            '{"action":"https:\/\/admin.test\/zero_personalize\/training\/start\/key\/abc\/","data":{}}',
            $this->block()->postData(false)
        );
    }

    public function testWithDemoShoppersTheDemoFlagIsPosted(): void
    {
        $this->assertSame(
            ['action' => 'https://admin.test/zero_personalize/training/start/key/abc/', 'data' => ['demo' => 1]],
            json_decode($this->block()->postData(true), true)
        );
    }

    public function testTheTemplatePostsAndLoadsDataPost(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/view/adminhtml/templates/status.phtml');

        $this->assertSame(2, substr_count($template, 'data-post="<?= $escaper->escapeHtmlAttr($block->postData('));
        $this->assertStringContainsString('<script type="text/x-magento-init">{"*": {"mage/dataPost": {}}}</script>', $template);
        $this->assertStringNotContainsString('startUrl(', $template, 'no plain link to the action');
    }

    private function block(): Status
    {
        return new class extends Status {
            public function __construct()
            {
            }

            public function startUrl(bool $demo = false): string
            {
                return 'https://admin.test/zero_personalize/training/start/key/abc/';
            }
        };
    }
}
