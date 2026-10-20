<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Model;

use Aws\Personalize\PersonalizeClient;
use Aws\S3\S3Client;

/**
 * The AWS clients, made once per process with the configured region and
 * credentials (none: the SDK's own chain). Short timeouts: nothing here runs
 * in a shopper's request.
 */
class Aws
{
    private ?PersonalizeClient $personalize = null;
    private ?S3Client $s3 = null;

    /**
     * @param array<string, mixed> $clientArgs more client options (tests pass a 'handler')
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $clientArgs = []
    ) {
    }

    public function personalize(): PersonalizeClient
    {
        return $this->personalize ??= new PersonalizeClient($this->args());
    }

    public function s3(): S3Client
    {
        return $this->s3 ??= new S3Client($this->args());
    }

    /**
     * @return array<string, mixed>
     */
    private function args(): array
    {
        $args = [
            'version' => 'latest',
            'region' => $this->config->region(),
            'http' => ['connect_timeout' => 5, 'timeout' => 60],
            'retries' => ['mode' => 'adaptive', 'max_attempts' => 5],
        ];
        $credentials = $this->config->credentials();
        if ($credentials !== null) {
            $args['credentials'] = $credentials;
        }
        return $this->clientArgs + $args;
    }
}
