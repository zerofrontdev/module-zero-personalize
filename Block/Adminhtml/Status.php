<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Zero\Personalize\Model\Config;
use Zero\Personalize\Model\State;

/**
 * The Training group: where the run is, the last one, Train Now.
 */
class Status extends Field
{
    protected $_template = 'Zero_Personalize::status.phtml';

    public function __construct(
        Context $context,
        private readonly State $state,
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        return '<tr><td colspan="4">' . $this->toHtml() . '</td></tr>';
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state->get();
    }

    public function isConfigured(): bool
    {
        return $this->config->bucket() !== '' && $this->config->roleArn() !== '';
    }

    public function prefix(): string
    {
        return $this->config->prefix();
    }

    public function format(?string $utc): string
    {
        return $utc ? $this->timezone->formatDateTime($utc . ' UTC', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT) : '';
    }

    public function startUrl(bool $demo = false): string
    {
        return $this->getUrl('zero_personalize/training/start', $demo ? ['demo' => 1] : []);
    }
}
