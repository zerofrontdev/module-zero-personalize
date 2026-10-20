<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\Personalize\Model\Config;
use Zero\Personalize\Model\Aws;
use Zero\Personalize\Model\Resources;
use Zero\Personalize\Model\Results;
use Zero\Personalize\Model\State;

/**
 * bin/magento zero:personalize:teardown --yes: this store's Personalize
 * resources and S3 files deleted, the recommendations emptied. The bucket
 * and the role stay (they are made outside the store).
 */
class Teardown extends Command
{
    public function __construct(
        private readonly Resources $resources,
        private readonly Results $results,
        private readonly State $state,
        private readonly Config $config,
        private readonly Aws $aws
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zero:personalize:teardown')
            ->setDescription('Delete this store\'s Amazon Personalize resources and files, and its recommendations')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Really delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('yes')) {
            $output->writeln('Deletes the Personalize resources named ' . $this->config->prefix() . '* and their S3 files. Run again with --yes.');
            return Command::FAILURE;
        }
        for ($i = 0; $i < 120; $i++) {
            if ($this->resources->delete()) {
                break;
            }
            $output->writeln('Deleting… (' . ($i + 1) . ')');
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            sleep(15);
        }
        if ($this->config->bucket() !== '') {
            $s3 = $this->aws->s3();
            $token = null;
            $deleted = 0;
            do {
                $list = $s3->listObjectsV2(['Bucket' => $this->config->bucket(), 'Prefix' => $this->config->prefix() . '/'] + ($token ? ['ContinuationToken' => $token] : []));
                $keys = array_map(static fn ($o) => ['Key' => $o['Key']], (array) ($list['Contents'] ?? []));
                if ($keys) {
                    $s3->deleteObjects(['Bucket' => $this->config->bucket(), 'Delete' => ['Objects' => $keys]]);
                    $deleted += count($keys);
                }
                $token = !empty($list['IsTruncated']) ? ($list['NextContinuationToken'] ?? null) : null;
            } while ($token);
            $output->writeln($deleted . ' file(s) deleted from s3://' . $this->config->bucket() . '/' . $this->config->prefix() . '/');
        }
        $this->results->clear();
        $this->state->clear();
        $output->writeln('Done.');
        return Command::SUCCESS;
    }
}
