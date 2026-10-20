<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Model;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * What Personalize learns from, read from the store's own tables: the
 * products, and who bought, put in a cart or wished for which of them (no
 * tracking script: orders, quotes and wishlists already say it).
 *
 * Users: a customer is "c<id>"; a guest's order "g<hash of the email>"; a
 * guest's cart "q<quote id>". Only "c" users get "Picked for you".
 */
class Exporter
{
    public const MIN_INTERACTIONS = 1000;
    public const MIN_USERS = 25;

    /** @var array<int, array{price: float, categories: string, created: int}>|null */
    private ?array $items = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * @return array<int, array{0: string, 1: int, 2: int, 3: string}> user, product id, unix time, event type
     */
    public function interactions(int $days): array
    {
        $db = $this->resource->getConnection();
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $rows = [];

        $orders = $db->select()
            ->from(['i' => $this->resource->getTableName('sales_order_item')], ['product_id', 'created_at'])
            ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = i.order_id', ['customer_id', 'customer_email'])
            ->where('i.parent_item_id IS NULL')
            ->where('i.product_id IS NOT NULL')
            ->where('o.state <> ?', 'canceled')
            ->where('i.created_at >= ?', $since);
        foreach ($db->fetchAll($orders) as $row) {
            $user = $row['customer_id']
                ? 'c' . (int) $row['customer_id']
                : 'g' . substr(sha1(strtolower(trim((string) $row['customer_email']))), 0, 16);
            $rows[] = [$user, (int) $row['product_id'], (int) strtotime($row['created_at'] . ' UTC'), 'Purchase'];
        }

        $carts = $db->select()
            ->from(['i' => $this->resource->getTableName('quote_item')], ['product_id', 'created_at'])
            ->join(['q' => $this->resource->getTableName('quote')], 'q.entity_id = i.quote_id', ['customer_id', 'quote_id' => 'q.entity_id'])
            ->where('i.parent_item_id IS NULL')
            ->where('i.created_at >= ?', $since);
        foreach ($db->fetchAll($carts) as $row) {
            $user = $row['customer_id'] ? 'c' . (int) $row['customer_id'] : 'q' . (int) $row['quote_id'];
            $rows[] = [$user, (int) $row['product_id'], (int) strtotime($row['created_at'] . ' UTC'), 'AddToCart'];
        }

        $wishlists = $db->select()
            ->from(['i' => $this->resource->getTableName('wishlist_item')], ['product_id', 'added_at'])
            ->join(['w' => $this->resource->getTableName('wishlist')], 'w.wishlist_id = i.wishlist_id', ['customer_id'])
            ->where('i.added_at >= ?', $since);
        foreach ($db->fetchAll($wishlists) as $row) {
            $rows[] = ['c' . (int) $row['customer_id'], (int) $row['product_id'], (int) strtotime($row['added_at'] . ' UTC'), 'Wishlist'];
        }

        $known = array_flip(array_keys($this->items()));
        return array_values(array_filter($rows, static fn (array $r) => isset($known[$r[1]])));
    }

    /**
     * Enabled products visible in the catalog or search: id => [price, its
     * category paths, most specific first ("Men/Tops/Hoodies & Sweatshirts|
     * Collections/Eco Friendly"), its home ("Men/Tops": the most specific
     * path cut to two levels), created at (unix)].
     *
     * @return array<int, array{price: float, categories: string, home: string, created: int}>
     */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $db = $this->resource->getConnection();
        $link = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $attr = fn (string $code) => $this->eavConfig->getAttribute('catalog_product', $code);
        $value = function (string $code, string $alias) use ($db, $attr, $link) {
            $attribute = $attr($code);
            return [
                [$alias => $attribute->getBackendTable()],
                sprintf('%1$s.%2$s = e.%2$s AND %1$s.attribute_id = %3$d AND %1$s.store_id = 0', $alias, $link, (int) $attribute->getId()),
            ];
        };
        [$statusTable, $statusOn] = $value('status', 's');
        [$visibilityTable, $visibilityOn] = $value('visibility', 'v');
        [$priceTable, $priceOn] = $value('price', 'p');
        $select = $db->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['entity_id', 'created_at'])
            ->join($statusTable, $statusOn, [])
            ->join($visibilityTable, $visibilityOn, [])
            ->joinLeft($priceTable, $priceOn, ['price' => 'p.value'])
            ->where('s.value = ?', Status::STATUS_ENABLED)
            ->where('v.value IN (?)', [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]);
        $categories = $this->categoryPaths();
        $items = [];
        foreach ($db->fetchAll($select) as $row) {
            $id = (int) $row['entity_id'];
            $paths = $categories[$id] ?? [];
            $items[$id] = [
                'price' => round((float) $row['price'], 2),
                'categories' => implode('|', array_slice($paths, 0, 5)),
                'home' => self::home($paths),
                'created' => (int) strtotime($row['created_at'] . ' UTC'),
            ];
        }
        return $this->items = $items;
    }

    /**
     * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $interactions
     */
    public static function interactionsCsv(array $interactions): string
    {
        $lines = ['USER_ID,ITEM_ID,TIMESTAMP,EVENT_TYPE'];
        foreach ($interactions as [$user, $item, $time, $type]) {
            $lines[] = $user . ',' . $item . ',' . $time . ',' . $type;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int, array{price: float, categories: string, created: int}> $items
     */
    public static function itemsCsv(array $items): string
    {
        $lines = ['ITEM_ID,PRICE,CATEGORIES,CREATION_TIMESTAMP'];
        foreach ($items as $id => $item) {
            $categories = $item['categories'] === '' ? 'none' : $item['categories'];
            $lines[] = $id . ',' . number_format($item['price'], 2, '.', '') . ',"' . str_replace('"', '""', $categories) . '",' . $item['created'];
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Enough to train on, as Personalize asks: at least 1000 interactions
     * and 25 users with two or more.
     *
     * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $interactions
     * @return array{interactions: int, users: int, active_users: int, items: int, enough: bool}
     */
    public static function stats(array $interactions): array
    {
        $perUser = [];
        $items = [];
        foreach ($interactions as [$user, $item]) {
            $perUser[$user] = ($perUser[$user] ?? 0) + 1;
            $items[$item] = true;
        }
        $active = count(array_filter($perUser, static fn (int $n) => $n >= 2));
        return [
            'interactions' => count($interactions),
            'users' => count($perUser),
            'active_users' => $active,
            'items' => count($items),
            'enough' => count($interactions) >= self::MIN_INTERACTIONS && $active >= self::MIN_USERS,
        ];
    }

    /**
     * Demo shoppers for a demo store, which has too few real ones: "d<n>"
     * users who each keep to one home category ("Men/Tops", "Gear/Bags"),
     * as real shoppers mostly do.
     * The same every run (seeded), and never shown "Picked for you" (not "c").
     *
     * @param array<int, array{price: float, categories: string, created: int}> $items
     * @return array<int, array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function demo(array $items, int $users = 150, int $seed = 20261019): array
    {
        $byCategory = [];
        foreach ($items as $id => $item) {
            $home = (string) ($item['home'] ?? '') ?: (explode('|', $item['categories'])[0] ?: 'none');
            $byCategory[$home][] = $id;
        }
        $byCategory = array_filter($byCategory, static fn (array $ids) => count($ids) >= 4);
        if (!$byCategory) {
            return [];
        }
        $names = array_keys($byCategory);
        sort($names);
        // A small seeded generator (not random_int): the same shoppers every run.
        $state = $seed & 0x7fffffff;
        $rand = static function (int $min, int $max) use (&$state): int {
            $state = ($state * 1103515245 + 12345) & 0x7fffffff;
            return $min + intdiv($state, 16) % ($max - $min + 1);
        };
        $rows = [];
        $now = time();
        for ($u = 1; $u <= $users; $u++) {
            $pool = $byCategory[$names[$rand(0, count($names) - 1)]];
            sort($pool);
            $count = $rand(6, 14);
            for ($n = 0; $n < $count; $n++) {
                $type = ['AddToCart', 'AddToCart', 'Purchase', 'Wishlist'][$rand(0, 3)];
                $rows[] = ['d' . $u, $pool[$rand(0, count($pool) - 1)], $now - $rand(3600, 180 * 86400), $type];
            }
        }
        return $rows;
    }

    /**
     * The home of a product: its most specific category path, cut to two
     * levels ("Men/Tops/Hoodies & Sweatshirts" → "Men/Tops").
     *
     * @param string[] $paths most specific first
     */
    public static function home(array $paths): string
    {
        if (!$paths) {
            return '';
        }
        return implode('/', array_slice(explode('/', $paths[0]), 0, 2));
    }

    /**
     * Each product's category paths by name under the root, three levels at
     * most, most specific first (then the smaller category first): a men's
     * hoodie is "Men/Tops/Hoodies & Sweatshirts" before "Collections/Eco
     * Friendly", so mixed categories (collections, sales, new) don't make
     * it look like everything else in them.
     *
     * @return array<int, string[]>
     */
    private function categoryPaths(): array
    {
        $db = $this->resource->getConnection();
        $name = $this->eavConfig->getAttribute('catalog_category', 'name');
        $link = $this->metadataPool->getMetadata(\Magento\Catalog\Api\Data\CategoryInterface::class)->getLinkField();
        $categories = $db->fetchAll($db->select()
            ->from(['c' => $this->resource->getTableName('catalog_category_entity')], ['entity_id', 'path'])
            ->joinLeft(
                ['n' => $name->getBackendTable()],
                sprintf('n.%1$s = c.%1$s AND n.attribute_id = %2$d AND n.store_id = 0', $link, (int) $name->getId()),
                ['name' => 'n.value']
            ));
        $names = [];
        foreach ($categories as $category) {
            $names[(int) $category['entity_id']] = trim(str_replace(['|', '/', '"', ','], ' ', (string) $category['name']));
        }
        $paths = [];
        foreach ($categories as $category) {
            $ids = array_slice(array_map('intval', explode('/', (string) $category['path'])), 2, 3);
            $parts = array_filter(array_map(static fn (int $id) => $names[$id] ?? '', $ids));
            if ($parts) {
                $paths[(int) $category['entity_id']] = [implode('/', $parts), count($parts)];
            }
        }
        $sizes = array_map('intval', $db->fetchPairs($db->select()
            ->from($this->resource->getTableName('catalog_category_product'), ['category_id', 'COUNT(*)'])
            ->group('category_id')));
        $byProduct = [];
        $links = $db->fetchAll($db->select()->from($this->resource->getTableName('catalog_category_product'), ['product_id', 'category_id']));
        foreach ($links as $row) {
            $category = (int) $row['category_id'];
            if (isset($paths[$category])) {
                $byProduct[(int) $row['product_id']][$paths[$category][0]] = [$paths[$category][1], $sizes[$category] ?? 0];
            }
        }
        $result = [];
        foreach ($byProduct as $productId => $productPaths) {
            uksort($productPaths, static function (string $a, string $b) use ($productPaths): int {
                return [$productPaths[$b][0], $productPaths[$a][1]] <=> [$productPaths[$a][0], $productPaths[$b][1]];
            });
            $result[$productId] = array_keys($productPaths);
        }
        return $result;
    }
}
