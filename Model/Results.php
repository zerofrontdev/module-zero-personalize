<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * The recommendations, in the store's own tables: per product the similar
 * ones (zero_personalize_similar), per customer the picks
 * (zero_personalize_user), best first. The storefront reads only these; a
 * run replaces them at once.
 */
class Results
{
    public const SIMILAR = 'zero_personalize_similar';
    public const USER = 'zero_personalize_user';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return int[] product ids, best first
     */
    public function similar(int $productId, int $limit): array
    {
        $db = $this->resource->getConnection();
        return array_map('intval', $db->fetchCol($db->select()
            ->from($this->resource->getTableName(self::SIMILAR), 'recommended_id')
            ->where('product_id = ?', $productId)
            ->order('position ASC')
            ->limit($limit)));
    }

    /**
     * @return int[] product ids, best first
     */
    public function forCustomer(int $customerId, int $limit): array
    {
        $db = $this->resource->getConnection();
        return array_map('intval', $db->fetchCol($db->select()
            ->from($this->resource->getTableName(self::USER), 'product_id')
            ->where('customer_id = ?', $customerId)
            ->order('position ASC')
            ->limit($limit)));
    }

    /**
     * One batch output line: {"input": {"itemId"|"userId": …}, "output":
     * {"recommendedItems": […], "scores": […]}, "error": null}.
     *
     * @return array{key: string, items: int[], scores: float[]}|null
     */
    public static function parseLine(string $line, string $inputKey): ?array
    {
        $row = json_decode(trim($line), true);
        if (!is_array($row) || !empty($row['error'])) {
            return null;
        }
        $key = (string) ($row['input'][$inputKey] ?? '');
        $items = array_values(array_filter(array_map('intval', (array) ($row['output']['recommendedItems'] ?? []))));
        if ($key === '' || !$items) {
            return null;
        }
        $scores = array_map('floatval', (array) ($row['output']['scores'] ?? []));
        return ['key' => $key, 'items' => $items, 'scores' => $scores];
    }

    /**
     * @param array<int, int[]> $similar product id => recommended ids
     * @param array<int, int[]> $users customer id => product ids
     * @return array{similar: int, users: int}
     */
    public function replace(array $similar, array $users): array
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            foreach ([self::SIMILAR => [$similar, 'product_id', 'recommended_id'], self::USER => [$users, 'customer_id', 'product_id']] as $table => [$data, $keyColumn, $valueColumn]) {
                $name = $this->resource->getTableName($table);
                $db->delete($name);
                $rows = [];
                foreach ($data as $key => $ids) {
                    foreach (array_values($ids) as $position => $id) {
                        $rows[] = [$keyColumn => (int) $key, 'position' => $position, $valueColumn => (int) $id];
                        if (count($rows) === 1000) {
                            $db->insertMultiple($name, $rows);
                            $rows = [];
                        }
                    }
                }
                if ($rows) {
                    $db->insertMultiple($name, $rows);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['similar' => count($similar), 'users' => count($users)];
    }

    public function clear(): void
    {
        $this->replace([], []);
    }
}
