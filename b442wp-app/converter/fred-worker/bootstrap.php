<?php
declare(strict_types=1);
/**
 * Fred worker bootstrap.
 * Provides config(), a no-op db() stub, and conversion tier helpers.
 * Mimics the server's helpers.php surface that ThemeBuilder depends on.
 */

define('APP_ROOT', __DIR__);

function config(string $key, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg[$key] ?? $default;
}

/**
 * No-op DB stub — Fred does not have direct SQLite access.
 * ThemeBuilder's setStage() calls are silently discarded.
 */
function db(): object
{
    return new class {
        public function prepare(string $sql): object {
            return new class {
                public function execute(array $params = []): bool { return true; }
                public function fetch(int $mode = PDO::FETCH_ASSOC): array|false { return false; }
                public function fetchAll(int $mode = PDO::FETCH_ASSOC): array { return []; }
                public function fetchColumn(int $col = 0): mixed { return false; }
            };
        }
        public function query(string $sql): object {
            return new class {
                public function fetch(int $mode = PDO::FETCH_ASSOC): array|false { return false; }
                public function fetchAll(int $mode = PDO::FETCH_ASSOC): array { return []; }
                public function fetchColumn(int $col = 0): mixed { return false; }
            };
        }
        public function exec(string $sql): int|false { return false; }
    };
}

function conversion_tier_from_product_count(int $product_count): array
{
    $tiers = [
        ['key' => 'site',   'label' => 'WordPress Site',   'has_woocommerce' => false, 'max_products' => 0,   'price_cents' => (int) config('price_basic', 900)],
        ['key' => 'woo25',  'label' => 'WooCommerce 25',   'has_woocommerce' => true,  'max_products' => 25,  'price_cents' => (int) config('price_woocommerce', 1900)],
        ['key' => 'woo100', 'label' => 'WooCommerce 100',  'has_woocommerce' => true,  'max_products' => 100, 'price_cents' => (int) config('price_woocommerce_100', 2999)],
        ['key' => 'woo250', 'label' => 'WooCommerce 250',  'has_woocommerce' => true,  'max_products' => 250, 'price_cents' => (int) config('price_woocommerce_250', 5999)],
    ];
    if ($product_count <= 0) return $tiers[0];
    foreach (array_slice($tiers, 1) as $tier) {
        if ($product_count <= (int) $tier['max_products']) return $tier;
    }
    return $tiers[3];
}
