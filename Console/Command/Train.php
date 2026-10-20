<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\Personalize\Model\Pipeline;
use Zero\Personalize\Model\State;

/**
 * bin/magento zero:personalize:train [--demo-data] [--wait]: a training
 * run started (or the one in progress taken further); --wait follows it to
 * the end, checking every 30 seconds.
 */
class Train extends Command
{
    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zero:personalize:train')
            ->setDescription('Train Amazon Personalize on the store\'s orders, carts and wishlists and load the recommendations')
            ->addOption('demo-data', null, InputOption::VALUE_NONE, 'Add demo shoppers (a demo store without enough real ones)')
            ->addOption('wait', null, InputOption::VALUE_NONE, 'Stay until the run is done (one to three hours)')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Take a failed run up again from the step that failed')
            ->addOption('reload', null, InputOption::VALUE_NONE, 'Load the last run\'s results again from S3 (no training)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $state = match (true) {
                (bool) $input->getOption('resume') => $this->pipeline->resume(),
                (bool) $input->getOption('reload') => $this->pipeline->reload(),
                $this->state->isRunning() => $this->pipeline->advance(),
                default => $this->pipeline->start((bool) $input->getOption('demo-data')),
            };
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if (!empty($state['run']['stats'])) {
            $s = $state['run']['stats'];
            $output->writeln(sprintf('Data: %d interactions, %d shoppers (%d with two or more), %d products.', $s['interactions'], $s['users'], $s['active_users'], $s['items']));
        }
        $last = '';
        while (true) {
            $line = $state['step'] . (!empty($state['run']['waiting']) ? ' (' . $state['run']['waiting'] . ')' : '');
            if ($line !== $last) {
                $output->writeln(gmdate('H:i:s') . ' ' . $line);
                $last = $line;
            }
            if (!in_array($state['step'], State::RUNNING, true) || !$input->getOption('wait')) {
                break;
            }
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            sleep(30);
            $state = $this->pipeline->advance();
        }
        if ($state['step'] === State::FAILED) {
            $output->writeln('<error>' . $state['error'] . '</error>');
            return Command::FAILURE;
        }
        if ($state['step'] === State::DONE) {
            $l = $state['last'];
            $output->writeln(sprintf('Done: similar products for %d products, picks for %d customers; %s training hours.', $l['products'], $l['customers'], $l['training_hours'] ?? '?'));
        }
        return Command::SUCCESS;
    }
}
