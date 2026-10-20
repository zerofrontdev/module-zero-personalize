<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\Personalize\Model\Config;
use Zero\Personalize\Model\State;

/**
 * bin/magento zero:personalize:status: the run, the last one, the resources.
 */
class Status extends Command
{
    public function __construct(
        private readonly State $state,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zero:personalize:status')->setDescription('Where Amazon Personalize training is, and what the last run produced');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $state = $this->state->get();
        $output->writeln('Prefix: ' . $this->config->prefix() . ', bucket: ' . ($this->config->bucket() ?: '(not set)') . ', region: ' . $this->config->region());
        $output->writeln('Step: ' . $state['step'] . (!empty($state['run']['waiting']) ? ' (' . $state['run']['waiting'] . ')' : '') . ($state['updated_at'] ? ', updated ' . $state['updated_at'] . ' UTC' : ''));
        if ($state['error']) {
            $output->writeln('<error>Error: ' . $state['error'] . '</error>');
        }
        if ($state['last']) {
            $l = $state['last'];
            $output->writeln(sprintf(
                'Last run: %s (finished %s UTC%s): %d interactions, similar products for %d products, picks for %d customers, %s training hours.',
                $l['run'], $l['finished_at'], $l['demo'] ? ', with demo shoppers' : '', $l['stats']['interactions'] ?? 0, $l['products'], $l['customers'], $l['training_hours'] ?? '?'
            ));
        }
        foreach ($state['resources'] as $key => $arn) {
            $output->writeln('  ' . str_pad($key, 22) . $arn);
        }
        return Command::SUCCESS;
    }
}
