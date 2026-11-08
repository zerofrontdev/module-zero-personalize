<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Test\Unit\Model;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\TestCase;
use Zero\Personalize\Model\Exporter;

/**
 * The CSVs Personalize imports, the minimums it trains on, the demo shoppers,
 * the users' ids (a guest's is a keyed hash of the email).
 */
class ExporterTest extends TestCase
{
    public function testInteractionsAndItemsCsv(): void
    {
        $this->assertSame(
            "USER_ID,ITEM_ID,TIMESTAMP,EVENT_TYPE\nc7,12,1760000000,Purchase\nq3,13,1760000100,AddToCart\n",
            Exporter::interactionsCsv([['c7', 12, 1760000000, 'Purchase'], ['q3', 13, 1760000100, 'AddToCart']])
        );
        $this->assertSame(
            "ITEM_ID,PRICE,CATEGORIES,CREATION_TIMESTAMP\n12,34.50,\"Women|Gear\",1700000000\n13,9.00,\"none\",1700000001\n",
            Exporter::itemsCsv([
                12 => ['price' => 34.5, 'categories' => 'Women|Gear', 'created' => 1700000000],
                13 => ['price' => 9.0, 'categories' => '', 'created' => 1700000001],
            ])
        );
    }

    public function testStatsSayWhetherPersonalizeCanTrain(): void
    {
        $rows = [];
        for ($u = 1; $u <= 25; $u++) {
            for ($n = 0; $n < 40; $n++) {
                $rows[] = ['c' . $u, $n, 1, 'Purchase'];
            }
        }
        $stats = Exporter::stats($rows);
        $this->assertSame(['interactions' => 1000, 'users' => 25, 'active_users' => 25, 'items' => 40, 'enough' => true], $stats);
        $this->assertFalse(Exporter::stats(array_slice($rows, 0, 999))['enough']);
        $oneEach = array_map(static fn ($i) => ['g' . $i, 1, 1, 'Purchase'], range(1, 1200));
        $this->assertFalse(Exporter::stats($oneEach)['enough'], 'many shoppers with one interaction each are not enough');
    }

    public function testDemoShoppersKeepToOneCategoryAndAreTheSameEveryRun(): void
    {
        $items = [];
        foreach (range(1, 10) as $id) {
            $items[$id] = ['price' => 1.0, 'categories' => $id <= 5 ? 'Women/Tops/Tees|Collections/Eco' : 'Gear/Bags|Collections/Eco', 'home' => $id <= 5 ? 'Women/Tops' : 'Gear/Bags', 'created' => 1];
        }
        $a = Exporter::demo($items, 40);
        $b = Exporter::demo($items, 40);
        $this->assertSame(array_map(static fn ($r) => [$r[0], $r[1], $r[3]], $a), array_map(static fn ($r) => [$r[0], $r[1], $r[3]], $b));
        $this->assertTrue(Exporter::stats($a)['active_users'] === 40);
        $byUser = [];
        foreach ($a as [$user, $item]) {
            $byUser[$user][$item <= 5 ? 'Women' : 'Gear'] = true;
            $this->assertStringStartsWith('d', $user, 'demo shoppers never get customer picks');
        }
        foreach ($byUser as $categories) {
            $this->assertCount(1, $categories);
        }
        $this->assertSame([], Exporter::demo([1 => ['price' => 1.0, 'categories' => 'Tiny', 'home' => 'Tiny', 'created' => 1]]));
    }

    public function testHomeIsTheMostSpecificPathCutToTwoLevels(): void
    {
        $this->assertSame('Men/Tops', Exporter::home(['Men/Tops/Hoodies & Sweatshirts', 'Collections/Eco Friendly']));
        $this->assertSame('Gear', Exporter::home(['Gear']));
        $this->assertSame('', Exporter::home([]));
    }

    public function testGuestIdsAreKeyedHashes(): void
    {
        $hashed = [];
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(static function (string $data) use (&$hashed) {
            $hashed[] = $data;

            return hash_hmac('sha256', $data, 'the-install-key');
        });
        $orders = [
            ['product_id' => 12, 'created_at' => '2026-10-01 10:00:00', 'customer_id' => null, 'customer_email' => ' Ann@Example.com '],
            ['product_id' => 13, 'created_at' => '2026-10-02 10:00:00', 'customer_id' => null, 'customer_email' => 'ann@example.com'],
            ['product_id' => 12, 'created_at' => '2026-10-03 10:00:00', 'customer_id' => null, 'customer_email' => 'bob@example.com'],
            ['product_id' => 13, 'created_at' => '2026-10-04 10:00:00', 'customer_id' => '7', 'customer_email' => 'carl@example.com'],
        ];

        $rows = $this->exporter($encryptor, $orders)->interactions(365);

        $ann = 'g' . substr(hash_hmac('sha256', 'ann@example.com', 'the-install-key'), 0, 16);
        $this->assertSame([$ann, $ann], [$rows[0][0], $rows[1][0]], 'the same email is the same shopper');
        $this->assertNotSame($ann, $rows[2][0]);
        $this->assertNotSame('g' . substr(sha1('ann@example.com'), 0, 16), $ann, 'not the plain sha1 anyone can compute');
        $this->assertMatchesRegularExpression('/^g[0-9a-f]{16}\z/', $rows[2][0]);
        $this->assertSame('c7', $rows[3][0], 'a customer stays c<id>');
        $this->assertSame(['ann@example.com', 'ann@example.com', 'bob@example.com'], $hashed, 'trimmed, lower case, customers not hashed');
    }

    /**
     * An exporter whose order query answers $orders (carts and wishlists empty), the catalogue products 12 and 13.
     *
     * @param array<int, array<string, mixed>> $orders
     */
    private function exporter(EncryptorInterface $encryptor, array $orders): Exporter
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'where'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls($orders, [], []);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $exporter = new Exporter($resource, $this->createStub(EavConfig::class), $this->createStub(MetadataPool::class), $encryptor);
        // The catalogue read is not this test's business.
        (new \ReflectionProperty($exporter, 'items'))->setValue($exporter, [
            12 => ['price' => 1.0, 'categories' => 'Gear', 'home' => 'Gear', 'created' => 1],
            13 => ['price' => 1.0, 'categories' => 'Gear', 'home' => 'Gear', 'created' => 1],
        ]);

        return $exporter;
    }
}
