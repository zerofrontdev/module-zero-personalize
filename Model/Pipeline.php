<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Model;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A training run, in Personalize's batch mode: nothing runs (or is billed)
 * between runs.
 *
 * start(): the store's data exported (Exporter), checked against
 * Personalize's minimums. Then advance(), from the cron every few minutes
 * or the CLI, takes the run as far as it can without waiting:
 *   setup  the resources made (Resources::ensure)
 *   import the CSVs uploaded to S3, imported (FULL)
 *   train  a new version of each solution trained (FULL)
 *   batch  recommendations for every product and every customer with a
 *          history, as batch inference jobs writing to S3
 *   load   the results read into the store's tables (Results), the pages
 *          with recommendations cleaned from the cache
 * then done. Any AWS error ends the run as failed, with the message.
 *
 * One step at a time: start, resume, reload, cancel and advance share a lock,
 * so the cron and the CLI or the admin never run a step twice (two imports,
 * two trainings billed). advance() skips while another holds it; the others
 * refuse.
 */
class Pipeline
{
    /** Recommendations kept per product and per customer. */
    public const RESULTS = 12;
    private const LOCK = 'zero_personalize_pipeline';

    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly Resources $resources,
        private readonly Exporter $exporter,
        private readonly Results $results,
        private readonly Aws $aws,
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger,
        private readonly LockManagerInterface $locks
    ) {
    }

    /**
     * @param bool $demo add demo shoppers (a demo store without enough real ones)
     * @return array<string, mixed> the state
     * @throws LocalizedException when not configured, already running, a step is running, or the data is too thin
     */
    public function start(bool $demo = false): array
    {
        if ($this->config->bucket() === '' || $this->config->roleArn() === '') {
            throw new LocalizedException(__('Set the S3 bucket and the IAM role for Personalize first (Stores > Configuration > Zero > Recommendations > Amazon Personalize).'));
        }
        if ($this->state->isRunning()) {
            throw new LocalizedException(__('A training run is in progress (%1).', $this->state->get()['step']));
        }

        return $this->locked(fn (): array => $this->startLocked($demo));
    }

    /**
     * @return array<string, mixed> the state
     * @throws LocalizedException when a run started meanwhile, or the data is too thin
     */
    private function startLocked(bool $demo): array
    {
        // Again under the lock: another start may have got there first.
        if ($this->state->isRunning()) {
            throw new LocalizedException(__('A training run is in progress (%1).', $this->state->get()['step']));
        }
        $interactions = $this->interactions($demo);
        $stats = Exporter::stats($interactions);
        if (!$stats['enough']) {
            throw new LocalizedException(__(
                'Not enough to learn from yet: %1 interactions and %2 shoppers with two or more (Personalize needs %3 and %4). Real stores get there with their orders; a demo store can add demo shoppers (--demo-data).',
                $stats['interactions'],
                $stats['active_users'],
                Exporter::MIN_INTERACTIONS,
                Exporter::MIN_USERS
            ));
        }
        $run = ['id' => gmdate('YmdHis'), 'demo' => $demo, 'stats' => $stats, 'started_at' => gmdate('Y-m-d H:i:s')];
        $this->state->save(['step' => State::SETUP, 'run' => $run, 'error' => null]);
        return $this->advanceLocked();
    }

    /**
     * As far as the run can go now; nothing while another step holds the lock (the next cron run goes on).
     *
     * @return array<string, mixed> the state
     */
    public function advance(): array
    {
        if (!$this->locks->lock(self::LOCK, 0)) {
            return $this->state->get();
        }
        try {
            return $this->advanceLocked();
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }

    /**
     * @return array<string, mixed> the state
     */
    private function advanceLocked(): array
    {
        $state = $this->state->get();
        try {
            for ($i = 0; $i < 8 && in_array($state['step'], State::RUNNING, true); $i++) {
                $before = $state['step'];
                $state = match ($state['step']) {
                    State::SETUP => $this->setup($state),
                    State::IMPORT => $this->import($state),
                    State::TRAIN => $this->train($state),
                    State::BATCH => $this->batch($state),
                    State::LOAD => $this->load($state),
                };
                if ($state['step'] === $before) {
                    break; // waiting on AWS
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Zero Personalize: ' . $state['step'] . ': ' . $e->getMessage());
            $state = $this->state->save([
                'step' => State::FAILED,
                'error' => $state['step'] . ': ' . self::message($e),
                'run' => ['failed_step' => $state['step']] + $state['run'],
            ]);
        }
        return $state;
    }

    /**
     * A failed run taken up again from the step that failed (after fixing
     * what AWS said: a permission, a quota), keeping what was done.
     *
     * @throws LocalizedException when there is no failed run to resume, or a step is running
     */
    public function resume(): array
    {
        return $this->locked(fn (): array => $this->resumeLocked());
    }

    private function resumeLocked(): array
    {
        $state = $this->state->get();
        $step = (string) ($state['run']['failed_step'] ?? '');
        if ($state['step'] !== State::FAILED || !in_array($step, State::RUNNING, true)) {
            throw new LocalizedException(__('There is no failed run to resume.'));
        }
        $run = $state['run'];
        unset($run['failed_step']);
        $this->state->save(['step' => $step, 'run' => $run, 'error' => null]);
        return $this->advanceLocked();
    }

    /**
     * The last run's results read again from S3 (they stay there 30 days):
     * after a change in how they are loaded, without training again.
     *
     * @throws LocalizedException when no finished run is known, a run is in progress, or a step is running
     */
    public function reload(): array
    {
        return $this->locked(fn (): array => $this->reloadLocked());
    }

    private function reloadLocked(): array
    {
        $state = $this->state->get();
        $last = $state['last'];
        if (empty($last['run']) || empty($last['outputs'])) {
            throw new LocalizedException(__('No finished run to reload.'));
        }
        if ($this->state->isRunning()) {
            throw new LocalizedException(__('A training run is in progress (%1).', $state['step']));
        }
        $run = [
            'id' => $last['run'],
            'demo' => (bool) $last['demo'],
            'stats' => $last['stats'],
            'started_at' => $last['started_at'],
            'training_hours' => $last['training_hours'] ?? null,
            'batch_jobs' => array_fill_keys($last['outputs'], ''),
        ];
        $this->state->save(['step' => State::LOAD, 'run' => $run, 'error' => null]);
        return $this->advanceLocked();
    }

    /**
     * Similar products of the product's own department (the first level of
     * its home: Men, Women, Gear) first, in the model's order, then the
     * others: the model learns from every order, test orders and mixed
     * carts included, and a men's hoodie shouldn't open on women's tees.
     *
     * @param array<int, int[]> $similar product id => recommended ids
     * @param array<int, string> $departments product id => department
     * @return array<int, int[]>
     */
    public static function sameDepartmentFirst(array $similar, array $departments): array
    {
        foreach ($similar as $productId => $ids) {
            $own = $departments[$productId] ?? '';
            if ($own === '') {
                continue;
            }
            $same = array_values(array_filter($ids, static fn (int $id) => ($departments[$id] ?? '') === $own));
            $similar[$productId] = array_merge($same, array_values(array_diff($ids, $same)));
        }
        return $similar;
    }

    /**
     * @return array<int, string> product id => department (Men, Women, Gear…)
     */
    private function departments(): array
    {
        $departments = [];
        foreach ($this->exporter->items() as $id => $item) {
            $departments[$id] = explode('/', (string) ($item['home'] ?? ''))[0];
        }
        return $departments;
    }

    /**
     * The run in progress given up (the jobs already started finish on
     * AWS's side, unused).
     *
     * @throws LocalizedException when a step is running
     */
    public function cancel(): array
    {
        return $this->locked(fn (): array => $this->state->save(['step' => State::IDLE, 'run' => [], 'error' => null]));
    }

    /**
     * $work under the pipeline's lock, never nested: the *Locked methods call advanceLocked(), not advance().
     *
     * @param callable(): array<string, mixed> $work
     * @return array<string, mixed>
     * @throws LocalizedException when another step holds the lock
     */
    private function locked(callable $work): array
    {
        if (!$this->locks->lock(self::LOCK, 0)) {
            throw new LocalizedException(__('A training step is running right now. Try again in a minute.'));
        }
        try {
            return $work();
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }

    private function setup(array $state): array
    {
        $result = $this->resources->ensure($state['resources']);
        $changes = ['resources' => $result['resources'], 'run' => ['waiting' => $result['waiting']] + $state['run']];
        if ($result['ready']) {
            $changes['step'] = State::IMPORT;
        }
        return $this->state->save($changes);
    }

    private function import(array $state): array
    {
        $run = $state['run'];
        $p = $this->aws->personalize();
        if (empty($run['import_jobs'])) {
            $items = $this->exporter->items();
            $keys = [
                'interactions' => $this->put($run['id'], 'interactions.csv', Exporter::interactionsCsv($this->interactions((bool) $run['demo']))),
                'items' => $this->put($run['id'], 'items.csv', Exporter::itemsCsv($items)),
            ];
            $jobs = [];
            foreach ($keys as $type => $key) {
                $jobs[$type] = (string) $p->createDatasetImportJob([
                    'jobName' => $this->config->prefix() . '-' . $type . '-' . $run['id'],
                    'datasetArn' => $state['resources']['dataset_' . $type],
                    'dataSource' => ['dataLocation' => 's3://' . $this->config->bucket() . '/' . $key],
                    'roleArn' => $this->config->roleArn(),
                    'importMode' => 'FULL',
                ])['datasetImportJobArn'];
            }
            $run['import_jobs'] = $jobs;
            $state = $this->state->save(['run' => $run]);
        }
        $statuses = [];
        foreach ($run['import_jobs'] as $type => $arn) {
            $job = $p->describeDatasetImportJob(['datasetImportJobArn' => $arn])['datasetImportJob'];
            $this->failIfFailed($type . ' import', $job);
            $statuses[$type] = (string) $job['status'];
        }
        $run['waiting'] = 'import: ' . self::summary($statuses);
        return $this->state->save(['run' => $run] + (self::allActive($statuses) ? ['step' => State::TRAIN] : []));
    }

    private function train(array $state): array
    {
        $result = $this->resources->ensureSolutions($state['resources']);
        $run = $state['run'];
        if (!$result['ready']) {
            $run['waiting'] = (string) $result['waiting'];
            return $this->state->save(['resources' => $result['resources'], 'run' => $run]);
        }
        $p = $this->aws->personalize();
        if (empty($run['versions'])) {
            $versions = [];
            foreach (array_keys(Resources::SOLUTIONS) as $key) {
                $versions[$key] = (string) $p->createSolutionVersion([
                    'solutionArn' => $result['resources']['solution_' . $key],
                    'trainingMode' => 'FULL',
                ])['solutionVersionArn'];
            }
            $run['versions'] = $versions;
            $this->state->save(['resources' => $result['resources'], 'run' => $run]);
        }
        $statuses = [];
        $hours = 0.0;
        foreach ($run['versions'] as $key => $arn) {
            $version = $p->describeSolutionVersion(['solutionVersionArn' => $arn])['solutionVersion'];
            $this->failIfFailed($key . ' training', $version);
            $statuses[$key] = (string) $version['status'];
            $hours += (float) ($version['trainingHours'] ?? 0);
        }
        $run['training_hours'] = round($hours, 3);
        $run['waiting'] = 'training: ' . self::summary($statuses);
        return $this->state->save(['run' => $run] + (self::allActive($statuses) ? ['step' => State::BATCH] : []));
    }

    private function batch(array $state): array
    {
        $run = $state['run'];
        $p = $this->aws->personalize();
        if (!isset($run['batch_jobs'])) {
            $inputs = [
                'similar' => array_map(static fn (int $id) => ['itemId' => (string) $id], array_keys($this->exporter->items())),
                'for_you' => array_map(static fn (string $user) => ['userId' => $user], self::pickCustomers($this->interactions(false), $this->config->customerDays(), $this->config->maxCustomers(), time())),
            ];
            $jobs = [];
            foreach ($inputs as $key => $lines) {
                if (!$lines) {
                    continue;
                }
                $file = $this->put($run['id'], 'batch/' . $key . '.json', implode("\n", array_map('json_encode', $lines)) . "\n");
                $jobs[$key] = (string) $p->createBatchInferenceJob([
                    'jobName' => $this->config->prefix() . '-' . str_replace('_', '-', $key) . '-' . $run['id'],
                    'solutionVersionArn' => $run['versions'][$key],
                    'numResults' => $this->config->batchResults(),
                    'jobInput' => ['s3DataSource' => ['path' => 's3://' . $this->config->bucket() . '/' . $file]],
                    'jobOutput' => ['s3DataDestination' => ['path' => 's3://' . $this->config->bucket() . '/' . $this->key($run['id'], 'out/' . $key . '/')]],
                    'roleArn' => $this->config->roleArn(),
                ])['batchInferenceJobArn'];
            }
            $run['batch_jobs'] = $jobs;
            $this->state->save(['run' => $run]);
        }
        $statuses = [];
        foreach ($run['batch_jobs'] as $key => $arn) {
            $job = $p->describeBatchInferenceJob(['batchInferenceJobArn' => $arn])['batchInferenceJob'];
            $this->failIfFailed($key . ' batch recommendations', $job);
            $statuses[$key] = (string) $job['status'];
        }
        $run['waiting'] = 'recommendations: ' . self::summary($statuses);
        return $this->state->save(['run' => $run] + (self::allActive($statuses) ? ['step' => State::LOAD] : []));
    }

    private function load(array $state): array
    {
        $run = $state['run'];
        $data = ['similar' => [], 'for_you' => []];
        foreach (array_keys($run['batch_jobs'] ?? []) as $key) {
            $inputKey = $key === 'similar' ? 'itemId' : 'userId';
            foreach ($this->outputs($this->key($run['id'], 'out/' . $key . '/')) as $line) {
                $row = Results::parseLine($line, $inputKey);
                if ($row === null) {
                    continue;
                }
                $id = $key === 'similar' ? (int) $row['key'] : (int) substr($row['key'], 1);
                if ($id > 0) {
                    $data[$key][$id] = array_slice($row['items'], 0, self::RESULTS);
                }
            }
        }
        $data['similar'] = self::sameDepartmentFirst($data['similar'], $this->departments());
        $counts = $this->results->replace($data['similar'], $data['for_you']);
        $this->cacheContext->registerTags([\Zero\Personalize\Block\Recommendations::CACHE_TAG]);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
        $last = [
            'run' => $run['id'],
            'demo' => (bool) $run['demo'],
            'started_at' => $run['started_at'],
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'stats' => $run['stats'],
            'training_hours' => $run['training_hours'] ?? null,
            'products' => $counts['similar'],
            'customers' => $counts['users'],
            'outputs' => array_keys($run['batch_jobs'] ?? []),
        ];
        return $this->state->save(['step' => State::DONE, 'run' => [], 'last' => $last, 'error' => null]);
    }

    /**
     * @return array<int, array{0: string, 1: int, 2: int, 3: string}>
     */
    private function interactions(bool $demo): array
    {
        $rows = $this->exporter->interactions($this->config->lookbackDays());
        if ($demo) {
            $rows = array_merge($rows, Exporter::demo($this->exporter->items()));
        }
        return $rows;
    }

    /**
     * The customers ("c" users) who get picks: active within $days, the most
     * recently active first, at most $max of them (0: all). Guests ("g") and
     * demo shoppers never get picks.
     *
     * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $interactions
     * @return string[]
     */
    public static function pickCustomers(array $interactions, int $days, int $max, int $now): array
    {
        $since = $now - $days * 86400;
        $last = [];
        foreach ($interactions as [$user, , $time]) {
            if ($user[0] === 'c' && $time >= $since) {
                $last[$user] = max($last[$user] ?? 0, $time);
            }
        }
        arsort($last);
        $users = array_keys($last);
        return $max > 0 ? array_slice($users, 0, $max) : $users;
    }

    private function key(string $run, string $name): string
    {
        return $this->config->prefix() . '/' . $run . '/' . $name;
    }

    private function put(string $run, string $name, string $body): string
    {
        $key = $this->key($run, $name);
        $this->aws->s3()->putObject(['Bucket' => $this->config->bucket(), 'Key' => $key, 'Body' => $body]);
        return $key;
    }

    /**
     * @return \Generator<string> the lines of the batch output files under $prefix
     */
    private function outputs(string $prefix): \Generator
    {
        $s3 = $this->aws->s3();
        $token = null;
        do {
            $list = $s3->listObjectsV2(['Bucket' => $this->config->bucket(), 'Prefix' => $prefix] + ($token ? ['ContinuationToken' => $token] : []));
            foreach ((array) ($list['Contents'] ?? []) as $object) {
                if (!str_ends_with((string) $object['Key'], '.out')) {
                    continue;
                }
                $body = (string) $s3->getObject(['Bucket' => $this->config->bucket(), 'Key' => $object['Key']])['Body'];
                foreach (preg_split('/\R/', $body) ?: [] as $line) {
                    if (trim($line) !== '') {
                        yield $line;
                    }
                }
            }
            $token = !empty($list['IsTruncated']) ? ($list['NextContinuationToken'] ?? null) : null;
        } while ($token);
    }

    private function failIfFailed(string $what, array $resource): void
    {
        if (str_contains((string) ($resource['status'] ?? ''), 'FAILED')) {
            throw new \RuntimeException($what . ' failed: ' . ($resource['failureReason'] ?? 'no reason given'));
        }
    }

    /**
     * @param array<string, string> $statuses
     */
    private static function allActive(array $statuses): bool
    {
        return $statuses === [] || count(array_filter($statuses, static fn (string $s) => $s !== 'ACTIVE')) === 0;
    }

    /**
     * @param array<string, string> $statuses
     */
    private static function summary(array $statuses): string
    {
        return implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($statuses), $statuses));
    }

    private static function message(\Throwable $e): string
    {
        if ($e instanceof \Aws\Exception\AwsException) {
            return ($e->getAwsErrorCode() ?: 'AWS') . ': ' . ($e->getAwsErrorMessage() ?: $e->getMessage());
        }
        return $e->getMessage();
    }
}
