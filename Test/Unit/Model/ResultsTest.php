<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\Personalize\Model\Resources;
use Zero\Personalize\Model\Results;

/**
 * A batch inference output line read; the schemas sent to Personalize.
 */
class ResultsTest extends TestCase
{
    public function testParsesABatchOutputLine(): void
    {
        $line = '{"input":{"itemId":"12"},"output":{"recommendedItems":["40","41","0"],"scores":[0.5,0.25,0.1]},"error":null}';
        $this->assertSame(['key' => '12', 'items' => [40, 41], 'scores' => [0.5, 0.25, 0.1]], Results::parseLine($line, 'itemId'));
        $this->assertSame('c7', Results::parseLine('{"input":{"userId":"c7"},"output":{"recommendedItems":["3"]}}', 'userId')['key']);
    }

    public function testSkipsErrorsEmptyAndBrokenLines(): void
    {
        $this->assertNull(Results::parseLine('{"input":{"itemId":"12"},"output":null,"error":"Item not found"}', 'itemId'));
        $this->assertNull(Results::parseLine('{"input":{"itemId":"12"},"output":{"recommendedItems":[]}}', 'itemId'));
        $this->assertNull(Results::parseLine('not json', 'itemId'));
        $this->assertNull(Results::parseLine('{"input":{"userId":"c7"},"output":{"recommendedItems":["3"]}}', 'itemId'));
    }

    public function testSchemasMatchTheCsvColumns(): void
    {
        $interactions = Resources::schema('Interactions');
        $this->assertSame(['USER_ID', 'ITEM_ID', 'TIMESTAMP', 'EVENT_TYPE'], array_column($interactions['fields'], 'name'));
        $items = Resources::schema('Items');
        $this->assertSame(['ITEM_ID', 'PRICE', 'CATEGORIES', 'CREATION_TIMESTAMP'], array_column($items['fields'], 'name'));
        $this->assertTrue($items['fields'][2]['categorical']);
        $this->assertSame('com.amazonaws.personalize.schema', $items['namespace']);
    }
}
