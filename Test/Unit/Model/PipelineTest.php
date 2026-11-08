<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Test\Unit\Model;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Indexer\CacheContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Personalize\Model\Aws;
use Zero\Personalize\Model\Config;
use Zero\Personalize\Model\Exporter;
use Zero\Personalize\Model\Pipeline;
use Zero\Personalize\Model\Resources;
use Zero\Personalize\Model\Results;
use Zero\Personalize\Model\State;
use Zero\Personalize\Test\Unit\Fake\FakeLockManager;

/**
 * A training run against a mocked AWS (the SDK's MockHandler: every call
 * checked in order): from setup to the results in the tables in one
 * advance() when AWS answers ACTIVE at once; waiting when it doesn't;
 * failed with AWS's reason; one step at a time under the pipeline's lock.
 */
#[AllowMockObjectsWithoutExpectations]
class PipelineTest extends TestCase
{
    private const LOCK = 'zero_personalize_pipeline';

    private MockHandler $aws;
    /** @var array<int, string> the calls made, "Operation key=value" */
    private array $calls = [];
    private ?array $flag = null;
    private Results&MockObject $results;
    private ManagerInterface&MockObject $events;
    private Pipeline $pipeline;
    private State $state;
    private FakeLockManager $locks;

    protected function setUp(): void
    {
        $this->aws = new MockHandler();
        $config = $this->createStub(Config::class);
        $config->method('bucket')->willReturn('bucket');
        $config->method('roleArn')->willReturn('arn:role');
        $config->method('prefix')->willReturn('zero-shop');
        $config->method('region')->willReturn('us-east-1');
        $config->method('lookbackDays')->willReturn(365);
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $data) {
            $this->flag = $data;
            return true;
        });
        $this->state = new State($flags);
        $exporter = $this->createStub(Exporter::class);
        $exporter->method('items')->willReturn([
            1 => ['price' => 10.0, 'categories' => 'Gear', 'created' => 1],
            2 => ['price' => 20.0, 'categories' => 'Gear', 'created' => 1],
        ]);
        $exporter->method('interactions')->willReturn([['c7', 1, 100, 'Purchase'], ['q3', 2, 100, 'AddToCart']]);
        $this->results = $this->createMock(Results::class);
        $this->events = $this->createMock(ManagerInterface::class);
        $this->locks = new FakeLockManager();
        $client = new Aws($config, ['handler' => $this->aws, 'credentials' => ['key' => 'k', 'secret' => 's']]);
        $this->pipeline = new Pipeline(
            $config,
            $this->state,
            new Resources($client, $config),
            $exporter,
            $this->results,
            $client,
            new CacheContext(),
            $this->events,
            $this->createStub(LoggerInterface::class),
            $this->locks
        );
        $this->flag = [
            'step' => State::SETUP,
            'resources' => [
                'dataset_group' => 'arn:dg', 'schema_interactions' => 'arn:si', 'dataset_interactions' => 'arn:di',
                'schema_items' => 'arn:sit', 'dataset_items' => 'arn:dit', 'solution_similar' => 'arn:sol-s', 'solution_for_you' => 'arn:sol-u',
            ],
            'run' => ['id' => 'R1', 'demo' => false, 'stats' => ['interactions' => 2], 'started_at' => '2026-10-19 00:00:00'],
            'last' => [],
        ];
    }

    public function testARunFromSetupToTheTablesWhenAwsAnswersAtOnce(): void
    {
        $this->answer([
            'DescribeDatasetGroup' => ['datasetGroup' => ['status' => 'ACTIVE']],
            'DescribeDataset' => ['dataset' => ['status' => 'ACTIVE']],
            'DescribeDataset ' => ['dataset' => ['status' => 'ACTIVE']],
            'PutObject zero-shop/R1/interactions.csv' => [],
            'PutObject zero-shop/R1/items.csv' => [],
            'CreateDatasetImportJob interactions' => ['datasetImportJobArn' => 'arn:ij1'],
            'CreateDatasetImportJob items' => ['datasetImportJobArn' => 'arn:ij2'],
            'DescribeDatasetImportJob' => ['datasetImportJob' => ['status' => 'ACTIVE']],
            'DescribeDatasetImportJob ' => ['datasetImportJob' => ['status' => 'ACTIVE']],
            'DescribeSolution' => ['solution' => ['status' => 'ACTIVE']],
            'DescribeSolution ' => ['solution' => ['status' => 'ACTIVE']],
            'CreateSolutionVersion arn:sol-s' => ['solutionVersionArn' => 'arn:v1'],
            'CreateSolutionVersion arn:sol-u' => ['solutionVersionArn' => 'arn:v2'],
            'DescribeSolutionVersion' => ['solutionVersion' => ['status' => 'ACTIVE', 'trainingHours' => 0.6]],
            'DescribeSolutionVersion ' => ['solutionVersion' => ['status' => 'ACTIVE', 'trainingHours' => 0.4]],
            'PutObject zero-shop/R1/batch/similar.json' => [],
            'CreateBatchInferenceJob arn:v1' => ['batchInferenceJobArn' => 'arn:b1'],
            'PutObject zero-shop/R1/batch/for_you.json' => [],
            'CreateBatchInferenceJob arn:v2' => ['batchInferenceJobArn' => 'arn:b2'],
            'DescribeBatchInferenceJob' => ['batchInferenceJob' => ['status' => 'ACTIVE']],
            'DescribeBatchInferenceJob ' => ['batchInferenceJob' => ['status' => 'ACTIVE']],
            'ListObjectsV2 zero-shop/R1/out/similar/' => ['Contents' => [['Key' => 'zero-shop/R1/out/similar/similar.json.out'], ['Key' => 'zero-shop/R1/out/similar/_SUCCESS']]],
            'GetObject zero-shop/R1/out/similar/similar.json.out' => ['Body' => '{"input":{"itemId":"1"},"output":{"recommendedItems":["2"]},"error":null}' . "\n" . '{"input":{"itemId":"2"},"output":{"recommendedItems":["1"]},"error":null}'],
            'ListObjectsV2 zero-shop/R1/out/for_you/' => ['Contents' => [['Key' => 'zero-shop/R1/out/for_you/for_you.json.out']]],
            'GetObject zero-shop/R1/out/for_you/for_you.json.out' => ['Body' => '{"input":{"userId":"c7"},"output":{"recommendedItems":["2","1"]},"error":null}'],
        ]);
        $this->results->expects($this->once())->method('replace')->with([1 => [2], 2 => [1]], [7 => [2, 1]])
            ->willReturn(['similar' => 2, 'users' => 1]);
        $this->events->expects($this->once())->method('dispatch')->with('clean_cache_by_tags', $this->anything());

        $state = $this->pipeline->advance();

        $this->assertSame(State::DONE, $state['step'], (string) $state['error']);
        $this->assertSame(0, count($this->aws), 'every expected AWS call was made');
        $this->assertEquals(['products' => 2, 'customers' => 1, 'training_hours' => 1.0], array_intersect_key($state['last'], array_flip(['products', 'customers', 'training_hours'])));
        $this->assertSame([], $state['run']);
        $this->assertContains('CreateSolutionVersion trainingMode=FULL', $this->calls);
        $this->assertContains('CreateBatchInferenceJob numResults=12', $this->calls);
        $this->assertContains('CreateDatasetImportJob importMode=FULL', $this->calls);
    }

    public function testWaitsWhileAwsIsBusyThenFailsWithItsReason(): void
    {
        $this->flag['step'] = State::TRAIN;
        $this->flag['run']['versions'] = ['similar' => 'arn:v1', 'for_you' => 'arn:v2'];
        $this->answer([
            'DescribeSolution' => ['solution' => ['status' => 'ACTIVE']],
            'DescribeSolution ' => ['solution' => ['status' => 'ACTIVE']],
            'DescribeSolutionVersion' => ['solutionVersion' => ['status' => 'CREATE IN_PROGRESS']],
            'DescribeSolutionVersion ' => ['solutionVersion' => ['status' => 'CREATE IN_PROGRESS']],
        ]);
        $state = $this->pipeline->advance();
        $this->assertSame(State::TRAIN, $state['step']);
        $this->assertSame('training: similar CREATE IN_PROGRESS, for_you CREATE IN_PROGRESS', $state['run']['waiting']);

        $this->answer([
            'DescribeSolution' => ['solution' => ['status' => 'ACTIVE']],
            'DescribeSolution ' => ['solution' => ['status' => 'ACTIVE']],
            'DescribeSolutionVersion' => ['solutionVersion' => ['status' => 'CREATE FAILED', 'failureReason' => 'Not enough data']],
        ]);
        $state = $this->pipeline->advance();
        $this->assertSame(State::FAILED, $state['step']);
        $this->assertSame('train: similar training failed: Not enough data', $state['error']);

        // resumed: back to training, the versions already made are asked again
        $this->answer([
            'DescribeSolution' => ['solution' => ['status' => 'ACTIVE']],
            'DescribeSolution ' => ['solution' => ['status' => 'ACTIVE']],
            'DescribeSolutionVersion' => ['solutionVersion' => ['status' => 'CREATE IN_PROGRESS']],
            'DescribeSolutionVersion ' => ['solutionVersion' => ['status' => 'CREATE IN_PROGRESS']],
        ]);
        $state = $this->pipeline->resume();
        $this->assertSame(State::TRAIN, $state['step']);
        $this->assertNull($state['error']);
        $this->assertSame(['similar' => 'arn:v1', 'for_you' => 'arn:v2'], $state['run']['versions']);
        $this->assertArrayNotHasKey('failed_step', $state['run']);
    }

    public function testSameDepartmentFirstKeepsTheModelsOrderWithinEachGroup(): void
    {
        $departments = [1 => 'Men', 2 => 'Women', 3 => 'Men', 4 => 'Gear', 5 => 'Men', 6 => ''];
        $this->assertSame(
            [1 => [3, 5, 2, 4], 6 => [2, 1], 2 => [3]],
            Pipeline::sameDepartmentFirst([1 => [2, 3, 4, 5], 6 => [2, 1], 2 => [3]], $departments)
        );
    }

    public function testStartRefusesTooLittleDataWithoutCallingAws(): void
    {
        $this->flag = null;
        $this->expectExceptionMessageMatches('/Not enough to learn from yet: 2 interactions/');
        $this->pipeline->start();
    }

    public function testStartRefusedWhileAnotherStepRuns(): void
    {
        $this->flag = null;
        $this->locks->holdByOther(self::LOCK);
        $this->answer(['DescribeDatasetGroup' => []]);

        try {
            $this->pipeline->start();
            $this->fail('start is refused');
        } catch (LocalizedException $e) {
            $this->assertSame('A training step is running right now. Try again in a minute.', $e->getMessage());
        }

        $this->assertNull($this->flag, 'the state is unchanged');
        $this->assertSame(1, count($this->aws), 'AWS is not called');
        $this->assertSame([['lock', self::LOCK, 0]], $this->locks->calls, 'a lock not taken is not released');
    }

    public function testAdvanceSkipsWhileLocked(): void
    {
        $this->flag['step'] = State::TRAIN;
        $before = $this->flag;
        $this->locks->holdByOther(self::LOCK);
        $this->answer(['DescribeSolution' => []]);

        $state = $this->pipeline->advance();

        $this->assertSame(State::TRAIN, $state['step']);
        $this->assertSame($before, $this->flag, 'nothing saved');
        $this->assertSame(1, count($this->aws), 'AWS is not called');
        $this->assertSame([['lock', self::LOCK, 0]], $this->locks->calls);
    }

    public function testResumeReloadAndCancelAreRefusedWhileAStepRuns(): void
    {
        $this->flag['step'] = State::FAILED;
        $this->flag['run']['failed_step'] = State::TRAIN;
        $this->flag['last'] = ['run' => 'R0', 'outputs' => ['similar'], 'demo' => false, 'stats' => [], 'started_at' => 'x'];
        $before = $this->flag;
        $this->locks->holdByOther(self::LOCK);

        foreach (['resume', 'reload', 'cancel'] as $method) {
            try {
                $this->pipeline->$method();
                $this->fail($method . ' is refused');
            } catch (LocalizedException $e) {
                $this->assertSame('A training step is running right now. Try again in a minute.', $e->getMessage(), $method);
            }
        }
        $this->assertSame($before, $this->flag);
        $this->assertSame([], $this->locks->unlocked());
    }

    public function testOneLockPerCall(): void
    {
        $this->flag['step'] = State::TRAIN;
        $this->flag['run']['versions'] = ['similar' => 'arn:v1', 'for_you' => 'arn:v2'];
        $busy = [
            'DescribeSolution' => ['solution' => ['status' => 'ACTIVE']],
            'DescribeSolution ' => ['solution' => ['status' => 'ACTIVE']],
            'DescribeSolutionVersion' => ['solutionVersion' => ['status' => 'CREATE IN_PROGRESS']],
            'DescribeSolutionVersion ' => ['solutionVersion' => ['status' => 'CREATE IN_PROGRESS']],
        ];
        $this->answer($busy);
        $this->assertSame(State::TRAIN, $this->pipeline->advance()['step']);
        $this->assertSame([['lock', self::LOCK, 0], ['unlock', self::LOCK]], $this->locks->calls);

        // resume runs the step itself, under its own lock, not a second one (the fake refuses a held lock)
        $this->flag['step'] = State::FAILED;
        $this->flag['run']['failed_step'] = State::TRAIN;
        $this->locks->calls = [];
        $this->answer($busy);
        $state = $this->pipeline->resume();
        $this->assertSame(State::TRAIN, $state['step'], (string) $state['error']);
        $this->assertSame(0, count($this->aws), 'the step ran');
        $this->assertSame([['lock', self::LOCK, 0], ['unlock', self::LOCK]], $this->locks->calls);

        $this->locks->calls = [];
        $this->assertSame(State::IDLE, $this->pipeline->cancel()['step']);
        $this->assertSame([['lock', self::LOCK, 0], ['unlock', self::LOCK]], $this->locks->calls);
        $this->assertSame([], $this->locks->held);
    }

    public function testReloadLoadsUnderItsOneLock(): void
    {
        $this->flag['step'] = State::IDLE;
        $this->flag['run'] = [];
        $this->flag['last'] = ['run' => 'R1', 'outputs' => ['similar', 'for_you'], 'demo' => false, 'stats' => [], 'started_at' => '2026-10-19 00:00:00'];
        $this->answer([
            'ListObjectsV2 zero-shop/R1/out/similar/' => ['Contents' => [['Key' => 'zero-shop/R1/out/similar/similar.json.out']]],
            'GetObject zero-shop/R1/out/similar/similar.json.out' => ['Body' => '{"input":{"itemId":"1"},"output":{"recommendedItems":["2"]},"error":null}'],
            'ListObjectsV2 zero-shop/R1/out/for_you/' => ['Contents' => [['Key' => 'zero-shop/R1/out/for_you/for_you.json.out']]],
            'GetObject zero-shop/R1/out/for_you/for_you.json.out' => ['Body' => '{"input":{"userId":"c7"},"output":{"recommendedItems":["2"]},"error":null}'],
        ]);
        $this->results->expects($this->once())->method('replace')->willReturn(['similar' => 1, 'users' => 1]);

        $state = $this->pipeline->reload();

        $this->assertSame(State::DONE, $state['step'], (string) $state['error']);
        $this->assertSame([['lock', self::LOCK, 0], ['unlock', self::LOCK]], $this->locks->calls);
    }

    public function testTheLockIsReleasedWhenTheStepRefuses(): void
    {
        $this->flag['step'] = State::IDLE;

        try {
            $this->pipeline->resume();
            $this->fail('nothing to resume');
        } catch (LocalizedException $e) {
            $this->assertSame('There is no failed run to resume.', $e->getMessage());
        }

        $this->assertSame([['lock', self::LOCK, 0], ['unlock', self::LOCK]], $this->locks->calls);
        $this->assertSame([], $this->locks->held);
    }

    public function testStartLooksAgainUnderTheLock(): void
    {
        $this->flag = null;
        // Another start saves its run between this one's check and its lock.
        $this->locks->observer = function (string $call): void {
            if ($call === 'lock') {
                $this->flag = ['step' => State::SETUP, 'run' => ['id' => 'R2']];
            }
        };

        try {
            $this->pipeline->start();
            $this->fail('the second start is refused');
        } catch (LocalizedException $e) {
            $this->assertSame('A training run is in progress (setup).', $e->getMessage());
        }

        $this->assertSame('R2', $this->flag['run']['id'], 'the other run is left as it is');
        $this->assertSame([self::LOCK], $this->locks->unlocked());
    }

    /**
     * Queues an answer per expected call, in order; each checks the call's
     * operation (and, after it, its distinguishing argument) and records it.
     *
     * @param array<string, array<string, mixed>> $answers "Operation detail" => result
     */
    private function answer(array $answers): void
    {
        foreach ($answers as $expected => $result) {
            $this->aws->append(function (CommandInterface $command) use ($expected, $result) {
                [$operation, $detail] = array_pad(explode(' ', trim($expected), 2), 2, '');
                $this->assertSame($operation, $command->getName(), 'expected ' . $expected);
                if ($detail !== '') {
                    $this->assertStringContainsString($detail, json_encode($command->toArray(), JSON_UNESCAPED_SLASHES), $expected);
                }
                foreach (['trainingMode', 'importMode', 'numResults'] as $key) {
                    if (isset($command[$key])) {
                        $this->calls[] = $command->getName() . ' ' . $key . '=' . $command[$key];
                    }
                }
                return new Result($result);
            });
        }
    }
}
