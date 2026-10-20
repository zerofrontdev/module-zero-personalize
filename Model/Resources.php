<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Model;

use Aws\Personalize\PersonalizeClient;

/**
 * The store's Personalize resources, all named after the prefix: a dataset
 * group with an interactions and an items dataset, and two solutions:
 * "similar" (aws-similar-items, for the product page) and "for-you"
 * (aws-user-personalization-v2, for a signed-in customer). Made step by
 * step (ensure()), each when the one it needs is active; found again by
 * name, so a lost state or a second run makes nothing twice.
 *
 * Solutions are made with automatic training off: a training costs, and
 * the store starts its own (Pipeline) on its own schedule.
 */
class Resources
{
    public const RECIPE_SIMILAR = 'arn:aws:personalize:::recipe/aws-similar-items';
    public const RECIPE_FOR_YOU = 'arn:aws:personalize:::recipe/aws-user-personalization-v2';
    public const SOLUTIONS = ['similar' => self::RECIPE_SIMILAR, 'for_you' => self::RECIPE_FOR_YOU];

    public function __construct(
        private readonly Aws $aws,
        private readonly Config $config
    ) {
    }

    /**
     * One step towards every resource existing and active.
     *
     * @param array<string, string> $have the ARNs known so far
     * @return array{resources: array<string, string>, ready: bool, waiting: string|null}
     */
    public function ensure(array $have): array
    {
        $p = $this->client();
        $prefix = $this->config->prefix();

        $group = $have['dataset_group'] ?? $this->find('listDatasetGroups', [], 'datasetGroups', $prefix, 'datasetGroupArn');
        if ($group === null) {
            $group = (string) $p->createDatasetGroup(['name' => $prefix])['datasetGroupArn'];
        }
        $have['dataset_group'] = $group;
        $status = (string) $p->describeDatasetGroup(['datasetGroupArn' => $group])['datasetGroup']['status'];
        if ($status !== 'ACTIVE') {
            return $this->notYet($have, 'dataset group', $status);
        }

        foreach (['interactions' => 'Interactions', 'items' => 'Items'] as $key => $type) {
            $schemaName = $prefix . '-' . $key . '-v1';
            $schema = $have['schema_' . $key] ?? $this->find('listSchemas', [], 'schemas', $schemaName, 'schemaArn');
            if ($schema === null) {
                $schema = (string) $p->createSchema([
                    'name' => $schemaName,
                    'schema' => json_encode(self::schema($type)),
                ])['schemaArn'];
            }
            $have['schema_' . $key] = $schema;

            $dataset = $have['dataset_' . $key]
                ?? $this->find('listDatasets', ['datasetGroupArn' => $group], 'datasets', $prefix . '-' . $key, 'datasetArn');
            if ($dataset === null) {
                $dataset = (string) $p->createDataset([
                    'name' => $prefix . '-' . $key,
                    'schemaArn' => $schema,
                    'datasetGroupArn' => $group,
                    'datasetType' => $type,
                ])['datasetArn'];
            }
            $have['dataset_' . $key] = $dataset;
            $status = (string) $p->describeDataset(['datasetArn' => $dataset])['dataset']['status'];
            if ($status !== 'ACTIVE') {
                return $this->notYet($have, $key . ' dataset', $status);
            }
        }
        return ['resources' => $have, 'ready' => true, 'waiting' => null];
    }

    /**
     * The solutions, made once the datasets hold data (Personalize wants
     * an import before a solution can train).
     *
     * @param array<string, string> $have
     * @return array{resources: array<string, string>, ready: bool, waiting: string|null}
     */
    public function ensureSolutions(array $have): array
    {
        $p = $this->client();
        $prefix = $this->config->prefix();
        foreach (self::SOLUTIONS as $key => $recipe) {
            $name = $prefix . '-' . str_replace('_', '-', $key);
            $solution = $have['solution_' . $key]
                ?? $this->find('listSolutions', ['datasetGroupArn' => $have['dataset_group']], 'solutions', $name, 'solutionArn');
            if ($solution === null) {
                $solution = (string) $p->createSolution([
                    'name' => $name,
                    'datasetGroupArn' => $have['dataset_group'],
                    'recipeArn' => $recipe,
                    'performAutoTraining' => false,
                ])['solutionArn'];
            }
            $have['solution_' . $key] = $solution;
            $status = (string) $p->describeSolution(['solutionArn' => $solution])['solution']['status'];
            if ($status !== 'ACTIVE') {
                return $this->notYet($have, $key . ' solution', $status);
            }
        }
        return ['resources' => $have, 'ready' => true, 'waiting' => null];
    }

    /**
     * Everything removed, in the order Personalize allows: solutions,
     * datasets, the dataset group, the schemas. Deletions take a while;
     * call again until it returns true.
     */
    public function delete(): bool
    {
        $p = $this->client();
        $prefix = $this->config->prefix();
        $group = $this->find('listDatasetGroups', [], 'datasetGroups', $prefix, 'datasetGroupArn');
        $pending = false;
        if ($group !== null) {
            foreach ($this->all('listSolutions', ['datasetGroupArn' => $group], 'solutions') as $solution) {
                $pending = true;
                if (!str_starts_with((string) $solution['status'], 'DELETE')) {
                    $this->tryCall(fn () => $p->deleteSolution(['solutionArn' => $solution['solutionArn']]));
                }
            }
            if (!$pending) {
                foreach ($this->all('listDatasets', ['datasetGroupArn' => $group], 'datasets') as $dataset) {
                    $pending = true;
                    if (!str_starts_with((string) $dataset['status'], 'DELETE')) {
                        $this->tryCall(fn () => $p->deleteDataset(['datasetArn' => $dataset['datasetArn']]));
                    }
                }
            }
            if (!$pending) {
                $this->tryCall(fn () => $p->deleteDatasetGroup(['datasetGroupArn' => $group]));
                return false;
            }
            return false;
        }
        foreach (['interactions', 'items'] as $key) {
            $schema = $this->find('listSchemas', [], 'schemas', $prefix . '-' . $key . '-v1', 'schemaArn');
            if ($schema !== null) {
                $this->tryCall(fn () => $p->deleteSchema(['schemaArn' => $schema]));
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed> the Avro schema Personalize takes
     */
    public static function schema(string $type): array
    {
        $fields = $type === 'Interactions'
            ? [
                ['name' => 'USER_ID', 'type' => 'string'],
                ['name' => 'ITEM_ID', 'type' => 'string'],
                ['name' => 'TIMESTAMP', 'type' => 'long'],
                ['name' => 'EVENT_TYPE', 'type' => 'string'],
            ]
            : [
                ['name' => 'ITEM_ID', 'type' => 'string'],
                ['name' => 'PRICE', 'type' => 'float'],
                ['name' => 'CATEGORIES', 'type' => 'string', 'categorical' => true],
                ['name' => 'CREATION_TIMESTAMP', 'type' => 'long'],
            ];
        return [
            'type' => 'record',
            'name' => $type,
            'namespace' => 'com.amazonaws.personalize.schema',
            'fields' => $fields,
            'version' => '1.0',
        ];
    }

    /**
     * @return array{resources: array<string, string>, ready: bool, waiting: string}
     */
    private function notYet(array $have, string $what, string $status): array
    {
        if (str_contains($status, 'FAILED')) {
            throw new \RuntimeException(sprintf('The %s could not be made (%s).', $what, $status));
        }
        return ['resources' => $have, 'ready' => false, 'waiting' => $what . ': ' . $status];
    }

    private function find(string $list, array $args, string $key, string $name, string $arnKey): ?string
    {
        foreach ($this->all($list, $args, $key) as $row) {
            if (($row['name'] ?? '') === $name) {
                return (string) $row[$arnKey];
            }
        }
        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(string $list, array $args, string $key): array
    {
        $rows = [];
        $token = null;
        do {
            $result = $this->client()->{$list}($args + ['maxResults' => 100] + ($token ? ['nextToken' => $token] : []));
            $rows = array_merge($rows, (array) ($result[$key] ?? []));
            $token = $result['nextToken'] ?? null;
        } while ($token);
        return $rows;
    }

    private function tryCall(callable $call): void
    {
        try {
            $call();
        } catch (\Aws\Personalize\Exception\PersonalizeException $e) {
            if (!in_array($e->getAwsErrorCode(), ['ResourceNotFoundException', 'ResourceInUseException'], true)) {
                throw $e;
            }
        }
    }

    private function client(): PersonalizeClient
    {
        return $this->aws->personalize();
    }
}
