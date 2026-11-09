<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/helpers.php';
load_env(dirname(__DIR__) . '/.env');

$pdo = db();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$database = (string) env('DB_NAME', '');

$columnExists = static function (string $table, string $column) use ($pdo, $database): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['db' => $database, 'table' => $table, 'column' => $column]);
    return (bool) $stmt->fetchColumn();
};

$indexExists = static function (string $table, string $index) use ($pdo, $database): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND INDEX_NAME = :index'
    );
    $stmt->execute(['db' => $database, 'table' => $table, 'index' => $index]);
    return (bool) $stmt->fetchColumn();
};

$tableExists = static function (string $table) use ($pdo, $database): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table');
    $stmt->execute(['db' => $database, 'table' => $table]);
    return (bool) $stmt->fetchColumn();
};

$alreadyApplied = static function (string $migration) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration');
    $stmt->execute(['migration' => $migration]);
    return (bool) $stmt->fetchColumn();
};

$record = static function (string $migration) use ($pdo): void {
    $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
    $stmt->execute(['migration' => $migration]);
};

$migrations = [
    '20260825_0800_viki_core' => static function () use ($pdo, $columnExists, $indexExists, $tableExists): void {
        if (!$columnExists('stores', 'logo_url')) {
            $pdo->exec('ALTER TABLE stores ADD COLUMN logo_url VARCHAR(500) NULL AFTER code');
        }
        if (!$columnExists('products', 'source_type')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN source_type VARCHAR(50) NULL AFTER store_id");
        }
        if (!$columnExists('products', 'external_id')) {
            $pdo->exec('ALTER TABLE products ADD COLUMN external_id BIGINT UNSIGNED NULL AFTER source_type');
        }
        if (!$columnExists('products', 'source_updated_at')) {
            $pdo->exec('ALTER TABLE products ADD COLUMN source_updated_at DATETIME NULL AFTER external_id');
        }
        if (!$indexExists('products', 'idx_products_store_source')) {
            $pdo->exec('ALTER TABLE products ADD INDEX idx_products_store_source (store_id, source_type, external_id)');
        }
        if (!$indexExists('products', 'idx_products_store_sku')) {
            $pdo->exec('ALTER TABLE products ADD INDEX idx_products_store_sku (store_id, sku)');
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS product_variants (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                external_id BIGINT UNSIGNED NULL,
                sku VARCHAR(190) NULL,
                name VARCHAR(255) NULL,
                regular_price DECIMAL(12,2) NULL,
                sale_price DECIMAL(12,2) NULL,
                stock INT NULL,
                stock_status VARCHAR(40) NOT NULL DEFAULT 'instock',
                attributes_json JSON NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_variants_store_product (store_id, product_id),
                KEY idx_variants_store_external (store_id, external_id),
                KEY idx_variants_store_sku (store_id, sku),
                CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $selectProducts = $pdo->query('SELECT id, store_id, source_type, external_id, payload_json FROM products ORDER BY id ASC');
        $updateSource = $pdo->prepare('UPDATE products SET source_type = :source_type, external_id = :external_id WHERE id = :id');
        $variantCount = $pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE store_id = :store_id AND product_id = :product_id');
        $insertVariant = $pdo->prepare(
            'INSERT INTO product_variants (store_id, product_id, external_id, sku, name, regular_price, sale_price, stock, stock_status, attributes_json, sort_order, is_active, created_at, updated_at)
             VALUES (:store_id, :product_id, :external_id, :sku, :name, :regular_price, :sale_price, :stock, :stock_status, :attributes_json, :sort_order, :is_active, NOW(), NOW())'
        );
        foreach ($selectProducts->fetchAll() ?: [] as $product) {
            $payload = json_decode((string) ($product['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                continue;
            }
            $source = (array) ($payload['source'] ?? []);
            if (empty($product['source_type']) && (($source['source'] ?? '') === 'woocommerce')) {
                $updateSource->execute([
                    'source_type' => 'woocommerce',
                    'external_id' => !empty($source['external_id']) ? (int) $source['external_id'] : null,
                    'id' => (int) $product['id'],
                ]);
            }
            $variants = array_values((array) ($payload['variants'] ?? []));
            if ($variants === []) {
                continue;
            }
            $variantCount->execute(['store_id' => (int) $product['store_id'], 'product_id' => (int) $product['id']]);
            if ((int) $variantCount->fetchColumn() > 0) {
                continue;
            }
            foreach ($variants as $index => $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $insertVariant->execute([
                    'store_id' => (int) $product['store_id'], 'product_id' => (int) $product['id'],
                    'external_id' => !empty($variant['external_id']) ? (int) $variant['external_id'] : null,
                    'sku' => trim((string) ($variant['sku'] ?? '')) ?: null,
                    'name' => $variant['name'] ?? ($variant['label'] ?? null),
                    'regular_price' => ($variant['regular_price'] ?? $variant['price'] ?? '') !== '' ? ($variant['regular_price'] ?? $variant['price']) : null,
                    'sale_price' => ($variant['sale_price'] ?? '') !== '' ? $variant['sale_price'] : null,
                    'stock' => ($variant['stock'] ?? '') !== '' ? (int) $variant['stock'] : null,
                    'stock_status' => (string) ($variant['stock_status'] ?? 'instock'),
                    'attributes_json' => json_encode($variant['attributes'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sort_order' => (int) ($variant['sort_order'] ?? $index),
                    'is_active' => array_key_exists('is_active', $variant) ? (int) ((bool) $variant['is_active']) : 1,
                ]);
            }
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(80) NULL,
                entity_id BIGINT UNSIGNED NULL,
                context_json JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_audit_store_created (store_id, created_at),
                KEY idx_audit_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS woo_product_map (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, store_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL,
                woo_product_id BIGINT UNSIGNED NULL, woo_parent_id BIGINT UNSIGNED NULL, sync_status VARCHAR(50) NOT NULL DEFAULT 'pending',
                last_synced_at DATETIME NULL, last_error TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_woo_product_map_store_product (store_id, product_id), KEY idx_woo_product_map_store_woo (store_id, woo_product_id), KEY idx_woo_product_map_status (sync_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS woo_category_map (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, store_id INT UNSIGNED NOT NULL, category_id INT UNSIGNED NOT NULL,
                woo_term_id BIGINT UNSIGNED NULL, woo_parent_term_id BIGINT UNSIGNED NULL, sync_status VARCHAR(50) NOT NULL DEFAULT 'pending',
                last_synced_at DATETIME NULL, last_error TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_woo_category_map_store_category (store_id, category_id), KEY idx_woo_category_map_store_woo (store_id, woo_term_id), KEY idx_woo_category_map_status (sync_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if ($tableExists('product_woo_map')) {
            $pdo->exec("INSERT INTO woo_product_map (store_id, product_id, woo_product_id, sync_status, last_synced_at, last_error, created_at, updated_at)
                        SELECT store_id, product_id, woo_product_id, sync_status, last_synced_at, last_error, created_at, updated_at FROM product_woo_map
                        ON DUPLICATE KEY UPDATE woo_product_id = VALUES(woo_product_id), sync_status = VALUES(sync_status), last_synced_at = VALUES(last_synced_at), last_error = VALUES(last_error)");
        }
        if ($tableExists('category_woo_map')) {
            $pdo->exec("INSERT INTO woo_category_map (store_id, category_id, woo_term_id, sync_status, last_synced_at, last_error, created_at, updated_at)
                        SELECT store_id, category_id, woo_category_id, sync_status, last_synced_at, last_error, created_at, updated_at FROM category_woo_map
                        ON DUPLICATE KEY UPDATE woo_term_id = VALUES(woo_term_id), sync_status = VALUES(sync_status), last_synced_at = VALUES(last_synced_at), last_error = VALUES(last_error)");
        }
    },
];

foreach ($migrations as $name => $migration) {
    if ($alreadyApplied($name)) {
        echo "SKIP {$name}\n";
        continue;
    }

    try {
        $migration();
        $record($name);
        echo "OK   {$name}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL {$name}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "Viki schema is up to date.\n";
