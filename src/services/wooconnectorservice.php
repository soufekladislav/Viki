<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\StoreConnectionRepository;
use PDO;
use RuntimeException;

final class WooConnectorService
{
    private const MAX_RESPONSE_BYTES = 52428800;
    private string $productsTable;
    private string $categoriesTable;
    private string $variantsTable;
    private string $backupDir;
    private string $settingsDir;

    public function __construct(
        private PDO $pdo,
        private StoreConnectionRepository $connections,
        array $config
    ) {
        $this->productsTable = $config['tables']['products'] ?? 'products';
        $this->categoriesTable = $config['tables']['categories'] ?? 'categories';
        $this->variantsTable = $config['tables']['product_variants'] ?? 'product_variants';

        $root = dirname(__DIR__, 2);
        $storageRoot = rtrim((string) env('STORAGE_PATH', $root . '/storage/private'), '/');
        $this->backupDir = $storageRoot . '/store-backups';
        $this->settingsDir = $storageRoot . '/store-settings';

        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0775, true);
        }
        if (!is_dir($this->settingsDir)) {
            @mkdir($this->settingsDir, 0775, true);
        }
    }

    public function allConnections(): array
    {
        $rows = $this->connections->all();
        return array_map(fn(array $row): array => $this->appendLocalMeta($row), $rows);
    }

    public function connection(int $storeId): ?array
    {
        $connection = $this->connections->findByStoreId($storeId);
        return $connection ? $this->appendLocalMeta($connection) : null;
    }

    public function publicConnection(?array $connection): ?array
    {
        if ($connection === null) {
            return null;
        }
        $connection['token_configured'] = !empty($connection['plugin_token']) || !empty($connection['token_configured']);
        unset($connection['plugin_token']);
        return $connection;
    }

    public function saveConnection(int $storeId, array $data): array
    {
        $baseUrl = $this->normalizeBaseUrl((string) ($data['wp_base_url'] ?? ''));
        $token = trim((string) ($data['plugin_token'] ?? ''));
        $existing = $this->connections->findByStoreId($storeId);

        if ($baseUrl === '' || ($token === '' && empty($existing['plugin_token']))) {
            throw new RuntimeException('WordPress URL a plugin token jsou povinné.');
        }

        $payload = [
            'wp_base_url' => $baseUrl,
            'status' => 'connected',
            'mode' => 'plugin_api',
            'connection_type' => 'woocommerce_plugin',
            'plugin_status' => 'configured',
            'last_error' => null,
        ];
        if ($token !== '') {
            $payload['plugin_token'] = $token;
        }
        $saved = $this->connections->upsert($storeId, $payload);

        return $this->appendLocalMeta($saved);
    }

    public function discover(int $storeId): array
    {
        $connection = $this->requireConnection($storeId);
        $export = $this->fetchJson(
            rtrim((string) $connection['wp_base_url'], '/') . '/wp-json/feedhub-connector/v1/export?page=1&per_page=1',
            (string) $connection['plugin_token'],
            'Plugin export endpoint není dostupný nebo vrátil chybu.',
            true
        );

        $site = $export['site'] ?? [];
        $counts = $export['counts'] ?? [];

        $saved = $this->connections->upsert($storeId, [
            'status' => 'connected',
            'plugin_status' => 'ok',
            'remote_site_name' => $site['name'] ?? null,
            'remote_home_url' => $site['home_url'] ?? null,
            'remote_wp_version' => $site['wp_version'] ?? null,
            'remote_wc_version' => $site['woo_version'] ?? null,
            'remote_product_count' => (int) ($counts['products'] ?? 0),
            'remote_category_count' => (int) ($counts['categories'] ?? 0),
            'last_discovery_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
        ]);

        return [
            'connection' => $this->appendLocalMeta($saved),
            'remote' => [
                'site' => $site,
                'counts' => $counts,
                'generated_at' => $export['generated_at'] ?? null,
            ],
        ];
    }

    public function firstImport(int $storeId): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $connection = $this->requireConnection($storeId);
        $backup = null;

        if ($this->storeHasCatalog($storeId)) {
            $backup = $this->createBackup($storeId, 'automatic', 'Před prvním importem z WooCommerce');
        }

        $export = $this->fetchPagedExport($connection);

        $categories = array_values((array) ($export['categories'] ?? []));
        $products = array_values((array) ($export['products'] ?? []));

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM {$this->variantsTable} WHERE store_id = :store_id")
                ->execute(['store_id' => $storeId]);
            $this->pdo->prepare("DELETE FROM {$this->productsTable} WHERE store_id = :store_id")
                ->execute(['store_id' => $storeId]);
            $this->pdo->prepare("DELETE FROM {$this->categoriesTable} WHERE store_id = :store_id")
                ->execute(['store_id' => $storeId]);

            $this->clearWooMapsIfPresent($storeId);

            $categoryMap = [];
            $insertCategory = $this->pdo->prepare(
                "INSERT INTO {$this->categoriesTable}
                 (store_id, parent_id, name, slug, description, image_url, sort_order, is_active, created_at, updated_at)
                 VALUES (:store_id, :parent_id, :name, :slug, :description, :image_url, :sort_order, :is_active, NOW(), NOW())"
            );

            foreach ($categories as $index => $category) {
                $insertCategory->execute([
                    'store_id' => $storeId,
                    'parent_id' => null,
                    'name' => trim((string) ($category['name'] ?? 'Bez názvu')),
                    'slug' => $this->slug((string) ($category['slug'] ?? $category['name'] ?? ('kategorie-' . $index))),
                    'description' => (string) ($category['description'] ?? ''),
                    'image_url' => $this->nullableString($category['image_url'] ?? null),
                    'sort_order' => isset($category['sort_order']) ? (int) $category['sort_order'] : ($index + 1),
                    'is_active' => !empty($category['is_active']) ? 1 : 0,
                ]);
                $categoryMap[(int) ($category['external_id'] ?? $category['id'] ?? 0)] = (int) $this->pdo->lastInsertId();
            }

            $updateParent = $this->pdo->prepare("UPDATE {$this->categoriesTable} SET parent_id = :parent_id, updated_at = NOW() WHERE id = :id LIMIT 1");
            foreach ($categories as $category) {
                $externalId = (int) ($category['external_id'] ?? $category['id'] ?? 0);
                $parentExternalId = (int) ($category['parent_id'] ?? 0);
                if ($externalId > 0 && isset($categoryMap[$externalId]) && $parentExternalId > 0 && isset($categoryMap[$parentExternalId])) {
                    $updateParent->execute([
                        'parent_id' => $categoryMap[$parentExternalId],
                        'id' => $categoryMap[$externalId],
                    ]);
                }
            }

            $insertProduct = $this->pdo->prepare(
                "INSERT INTO {$this->productsTable}
                 (store_id, source_type, external_id, source_updated_at, category_id, sku, name, slug, type, status, short_description, description,
                  regular_price, sale_price, stock, image_url, payload_json, is_active, created_at, updated_at)
                 VALUES
                 (:store_id, :source_type, :external_id, :source_updated_at, :category_id, :sku, :name, :slug, :type, :status, :short_description, :description,
                  :regular_price, :sale_price, :stock, :image_url, :payload_json, :is_active, NOW(), NOW())"
            );

            $insertVariant = $this->pdo->prepare(
                "INSERT INTO {$this->variantsTable}
                 (store_id, product_id, external_id, sku, name, regular_price, sale_price, stock, stock_status, attributes_json, sort_order, is_active, created_at, updated_at)
                 VALUES
                 (:store_id, :product_id, :external_id, :sku, :name, :regular_price, :sale_price, :stock, :stock_status, :attributes_json, :sort_order, :is_active, NOW(), NOW())"
            );

            foreach ($products as $index => $product) {
                $categoryIds = array_values(array_filter(array_map('intval', (array) ($product['category_ids'] ?? []))));
                $primaryExternalCategoryId = $categoryIds[0] ?? (int) ($product['primary_category_id'] ?? 0);
                $categoryId = $primaryExternalCategoryId > 0 && isset($categoryMap[$primaryExternalCategoryId])
                    ? $categoryMap[$primaryExternalCategoryId]
                    : null;

                $insertProduct->execute([
                    'store_id' => $storeId,
                    'source_type' => 'woocommerce',
                    'external_id' => $this->nullableInt($product['external_id'] ?? $product['id'] ?? null),
                    'source_updated_at' => $this->nullableDateTime($product['updated_at'] ?? $product['date_modified'] ?? null),
                    'category_id' => $categoryId,
                    'sku' => $this->nullableString($product['sku'] ?? null),
                    'name' => trim((string) ($product['name'] ?? ('Produkt ' . ($index + 1)))),
                    'slug' => $this->slug((string) ($product['slug'] ?? $product['name'] ?? ('produkt-' . $index))),
                    'type' => (string) ($product['type'] ?? 'simple'),
                    'status' => (string) ($product['status'] ?? 'draft'),
                    'short_description' => $this->sanitizeHtml((string) ($product['short_description'] ?? '')),
                    'description' => $this->sanitizeHtml((string) ($product['description'] ?? '')),
                    'regular_price' => $this->nullableDecimal($product['regular_price'] ?? null),
                    'sale_price' => $this->nullableDecimal($product['sale_price'] ?? null),
                    'stock' => $this->nullableInt($product['stock_quantity'] ?? ($product['stock'] ?? null)),
                    'image_url' => $this->nullableString($product['image_url'] ?? null),
                    'payload_json' => json_encode([
                        'categoryIds' => array_values(array_filter(array_map(function (int $externalId) use ($categoryMap): int {
                            return $categoryMap[$externalId] ?? 0;
                        }, $categoryIds))),
                        'galleryImages' => array_values((array) ($product['gallery_images'] ?? [])),
                        'attributes' => $product['attributes'] ?? new \stdClass(),
                        'variants' => array_values((array) ($product['variants'] ?? $product['variations'] ?? [])),
                        'virtual' => !empty($product['virtual']),
                        'downloadable' => !empty($product['downloadable']),
                        'manage_stock' => array_key_exists('manage_stock', (array) $product) ? (bool) $product['manage_stock'] : true,
                        'stock_status' => (string) ($product['stock_status'] ?? 'instock'),
                        'source' => [
                            'external_id' => (int) ($product['external_id'] ?? $product['id'] ?? 0),
                            'external_parent_id' => (int) ($product['external_parent_id'] ?? 0),
                            'source' => 'woocommerce',
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'is_active' => !empty($product['is_active']) ? 1 : 0,
                ]);

                $productId = (int) $this->pdo->lastInsertId();
                foreach (array_values((array) ($product['variants'] ?? $product['variations'] ?? [])) as $variantIndex => $variant) {
                    if (!is_array($variant)) {
                        continue;
                    }
                    $insertVariant->execute([
                        'store_id' => $storeId,
                        'product_id' => $productId,
                        'external_id' => $this->nullableInt($variant['external_id'] ?? $variant['id'] ?? null),
                        'sku' => $this->nullableString($variant['sku'] ?? null),
                        'name' => $this->nullableString($variant['name'] ?? null),
                        'regular_price' => $this->nullableDecimal($variant['regular_price'] ?? $variant['price'] ?? null),
                        'sale_price' => $this->nullableDecimal($variant['sale_price'] ?? null),
                        'stock' => $this->nullableInt($variant['stock_quantity'] ?? $variant['stock'] ?? null),
                        'stock_status' => (string) ($variant['stock_status'] ?? 'instock'),
                        'attributes_json' => json_encode($variant['attributes'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'sort_order' => (int) ($variant['sort_order'] ?? $variantIndex),
                        'is_active' => array_key_exists('is_active', $variant) ? (int) ((bool) $variant['is_active']) : 1,
                    ]);
                }
            }

            $site = (array) ($export['site'] ?? []);
            $counts = (array) ($export['counts'] ?? []);

            $saved = $this->connections->upsert($storeId, [
                'status' => 'imported',
                'plugin_status' => 'ok',
                'remote_site_name' => $site['name'] ?? null,
                'remote_home_url' => $site['home_url'] ?? null,
                'remote_wp_version' => $site['wp_version'] ?? null,
                'remote_wc_version' => $site['woo_version'] ?? null,
                'remote_product_count' => (int) ($counts['products'] ?? count($products)),
                'remote_category_count' => (int) ($counts['categories'] ?? count($categories)),
                'last_discovery_at' => date('Y-m-d H:i:s'),
                'last_import_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'connection' => $this->appendLocalMeta($saved),
            'imported' => [
                'categories' => count($categories),
                'products' => count($products),
            ],
            'remote' => [
                'site' => $site ?? [],
                'counts' => $counts ?? [],
                'generated_at' => $export['generated_at'] ?? null,
            ],
            'backup' => $backup,
            'source' => [
                'type' => 'woocommerce',
                'label' => 'WooCommerce plugin API',
            ],
        ];
    }

    public function listBackups(int $storeId): array
    {
        $items = [];
        foreach (glob($this->backupDir . '/store-' . $storeId . '-*.json') ?: [] as $file) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }
            $items[] = (array) ($decoded['meta'] ?? []);
        }

        usort($items, fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $items;
    }

    public function createBackup(int $storeId, string $type = 'manual', string $note = ''): array
    {
        $categories = $this->fetchStoreCategories($storeId);
        $products = $this->fetchStoreProducts($storeId);
        $variants = $this->fetchStoreVariants($storeId);

        $backupId = date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $meta = [
            'backup_id' => $backupId,
            'store_id' => $storeId,
            'type' => $type,
            'note' => $note,
            'categories' => count($categories),
            'products' => count($products),
            'variants' => count($variants),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $connectionSnapshot = $this->connections->findByStoreId($storeId);
        if (is_array($connectionSnapshot)) {
            unset($connectionSnapshot['plugin_token']);
        }

        $payload = [
            'meta' => $meta,
            'connection' => $connectionSnapshot,
            'settings' => $this->storeSettings($storeId),
            'categories' => $categories,
            'products' => $products,
            'variants' => $variants,
        ];

        file_put_contents($this->backupFile($storeId, $backupId), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $meta;
    }

    public function restoreBackup(int $storeId, string $backupId): array
    {
        $path = $this->backupFile($storeId, $backupId);
        if (!is_file($path)) {
            throw new RuntimeException('Záloha nebyla nalezena.');
        }

        $backup = json_decode((string) file_get_contents($path), true);
        if (!is_array($backup)) {
            throw new RuntimeException('Záloha je poškozená.');
        }

        $categories = array_values((array) ($backup['categories'] ?? []));
        $products = array_values((array) ($backup['products'] ?? []));
        $variants = array_values((array) ($backup['variants'] ?? []));

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM {$this->variantsTable} WHERE store_id = :store_id")->execute(['store_id' => $storeId]);
            $this->pdo->prepare("DELETE FROM {$this->productsTable} WHERE store_id = :store_id")->execute(['store_id' => $storeId]);
            $this->pdo->prepare("DELETE FROM {$this->categoriesTable} WHERE store_id = :store_id")->execute(['store_id' => $storeId]);
            $this->clearWooMapsIfPresent($storeId);

            $insertCategory = $this->pdo->prepare(
                "INSERT INTO {$this->categoriesTable}
                (id, store_id, parent_id, name, slug, description, image_url, sort_order, is_active, created_at, updated_at)
                VALUES
                (:id, :store_id, :parent_id, :name, :slug, :description, :image_url, :sort_order, :is_active, :created_at, :updated_at)"
            );

            foreach ($categories as $category) {
                $insertCategory->execute([
                    'id' => (int) $category['id'],
                    'store_id' => $storeId,
                    'parent_id' => $category['parent_id'] !== null ? (int) $category['parent_id'] : null,
                    'name' => (string) ($category['name'] ?? ''),
                    'slug' => (string) ($category['slug'] ?? ''),
                    'description' => (string) ($category['description'] ?? ''),
                    'image_url' => $this->nullableString($category['image_url'] ?? null),
                    'sort_order' => (int) ($category['sort_order'] ?? 0),
                    'is_active' => !empty($category['is_active']) ? 1 : 0,
                    'created_at' => (string) ($category['created_at'] ?? date('Y-m-d H:i:s')),
                    'updated_at' => (string) ($category['updated_at'] ?? date('Y-m-d H:i:s')),
                ]);
            }

            $insertProduct = $this->pdo->prepare(
                "INSERT INTO {$this->productsTable}
                (id, store_id, source_type, external_id, source_updated_at, category_id, sku, name, slug, type, status, short_description, description, regular_price, sale_price, stock, image_url, payload_json, is_active, created_at, updated_at)
                VALUES
                (:id, :store_id, :source_type, :external_id, :source_updated_at, :category_id, :sku, :name, :slug, :type, :status, :short_description, :description, :regular_price, :sale_price, :stock, :image_url, :payload_json, :is_active, :created_at, :updated_at)"
            );

            foreach ($products as $product) {
                $insertProduct->execute([
                    'id' => (int) $product['id'],
                    'store_id' => $storeId,
                    'source_type' => $this->nullableString($product['source_type'] ?? null),
                    'external_id' => $this->nullableInt($product['external_id'] ?? null),
                    'source_updated_at' => $this->nullableDateTime($product['source_updated_at'] ?? null),
                    'category_id' => $product['category_id'] !== null ? (int) $product['category_id'] : null,
                    'sku' => $this->nullableString($product['sku'] ?? null),
                    'name' => (string) ($product['name'] ?? ''),
                    'slug' => (string) ($product['slug'] ?? ''),
                    'type' => (string) ($product['type'] ?? 'simple'),
                    'status' => (string) ($product['status'] ?? 'draft'),
                    'short_description' => (string) ($product['short_description'] ?? ''),
                    'description' => (string) ($product['description'] ?? ''),
                    'regular_price' => $this->nullableDecimal($product['regular_price'] ?? null),
                    'sale_price' => $this->nullableDecimal($product['sale_price'] ?? null),
                    'stock' => $this->nullableInt($product['stock'] ?? null),
                    'image_url' => $this->nullableString($product['image_url'] ?? null),
                    'payload_json' => is_string($product['payload_json'] ?? null) ? $product['payload_json'] : json_encode($product['payload_json'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'is_active' => !empty($product['is_active']) ? 1 : 0,
                    'created_at' => (string) ($product['created_at'] ?? date('Y-m-d H:i:s')),
                    'updated_at' => (string) ($product['updated_at'] ?? date('Y-m-d H:i:s')),
                ]);
            }

            $insertVariant = $this->pdo->prepare(
                "INSERT INTO {$this->variantsTable}
                (id, store_id, product_id, external_id, sku, name, regular_price, sale_price, stock, stock_status, attributes_json, sort_order, is_active, created_at, updated_at)
                VALUES
                (:id, :store_id, :product_id, :external_id, :sku, :name, :regular_price, :sale_price, :stock, :stock_status, :attributes_json, :sort_order, :is_active, :created_at, :updated_at)"
            );
            foreach ($variants as $variant) {
                $insertVariant->execute([
                    'id' => (int) $variant['id'], 'store_id' => $storeId, 'product_id' => (int) $variant['product_id'],
                    'external_id' => $this->nullableInt($variant['external_id'] ?? null), 'sku' => $this->nullableString($variant['sku'] ?? null),
                    'name' => $this->nullableString($variant['name'] ?? null), 'regular_price' => $this->nullableDecimal($variant['regular_price'] ?? null),
                    'sale_price' => $this->nullableDecimal($variant['sale_price'] ?? null), 'stock' => $this->nullableInt($variant['stock'] ?? null),
                    'stock_status' => (string) ($variant['stock_status'] ?? 'instock'),
                    'attributes_json' => is_string($variant['attributes_json'] ?? null) ? $variant['attributes_json'] : json_encode($variant['attributes_json'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sort_order' => (int) ($variant['sort_order'] ?? 0), 'is_active' => !empty($variant['is_active']) ? 1 : 0,
                    'created_at' => (string) ($variant['created_at'] ?? date('Y-m-d H:i:s')), 'updated_at' => (string) ($variant['updated_at'] ?? date('Y-m-d H:i:s')),
                ]);
            }

            if (is_array($backup['settings'] ?? null)) {
                $this->saveStoreSettings($storeId, (array) $backup['settings']);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'restored' => true,
            'backup' => (array) ($backup['meta'] ?? []),
            'connection' => $this->connection($storeId),
        ];
    }

    public function storeSettings(int $storeId): array
    {
        $path = $this->settingsFile($storeId);
        $defaults = [
            'prices_include_tax' => 'yes',
            'tax_rates' => "21\n12\n0",
            'sync_interval_minutes' => 60,
            'source_type' => 'woocommerce',
            'notes' => '',
            'updated_at' => null,
        ];

        if (!is_file($path)) {
            return $defaults;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return array_merge($defaults, is_array($decoded) ? $decoded : []);
    }

    public function saveStoreSettings(int $storeId, array $data): array
    {
        $settings = [
            'prices_include_tax' => (($data['prices_include_tax'] ?? 'yes') === 'no') ? 'no' : 'yes',
            'tax_rates' => trim((string) ($data['tax_rates'] ?? "21\n12\n0")),
            'sync_interval_minutes' => max(5, (int) ($data['sync_interval_minutes'] ?? 60)),
            'source_type' => trim((string) ($data['source_type'] ?? 'woocommerce')) ?: 'woocommerce',
            'notes' => trim((string) ($data['notes'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        file_put_contents($this->settingsFile($storeId), json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $settings;
    }

    public function fetchPluginJson(string $baseUrl, string $token, string $path, string $errorMessage, bool $expectSuccess = true, string $method = 'GET', ?array $body = null): array
    {
        return $this->fetchJson(rtrim($baseUrl, '/') . '/' . ltrim($path, '/'), $token, $errorMessage, $expectSuccess, $method, $body);
    }

    private function fetchPagedExport(array $connection): array
    {
        $base = rtrim((string) $connection['wp_base_url'], '/') . '/wp-json/feedhub-connector/v1/export';
        $token = (string) $connection['plugin_token'];
        $first = $this->fetchJson($base . '?page=1&per_page=100', $token, 'Plugin export endpoint není dostupný nebo vrátil chybu.', true);
        $pages = max(1, (int) ($first['pagination']['pages'] ?? 1));
        $products = array_values((array) ($first['products'] ?? []));
        for ($page = 2; $page <= $pages; $page++) {
            $chunk = $this->fetchJson($base . '?page=' . $page . '&per_page=100', $token, 'Stránku exportu ' . $page . ' se nepodařilo načíst.', true);
            $products = array_merge($products, array_values((array) ($chunk['products'] ?? [])));
        }
        $first['products'] = $products;
        $first['counts']['products'] = (int) ($first['pagination']['total'] ?? count($products));
        return $first;
    }

    private function requireConnection(int $storeId): array
    {
        $connection = $this->connections->findByStoreId($storeId);
        if (!$connection) {
            throw new RuntimeException('Pro tento e-shop ještě není uložené připojení WooCommerce.');
        }
        if (empty($connection['wp_base_url']) || empty($connection['plugin_token'])) {
            throw new RuntimeException('Připojení není kompletně nastavené.');
        }
        return $connection;
    }

    private function fetchJson(string $url, string $token, string $errorMessage, bool $expectSuccess = true, string $method = 'GET', ?array $body = null): array
    {
        $method = strtoupper($method);
        $payload = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $headers = [
            'Accept: application/json',
            'X-FeedHub-Token: ' . $token,
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }

            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($payload !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
            }

            $raw = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $status === 0) {
                throw new RuntimeException($errorMessage . ($curlError !== '' ? ' (' . $curlError . ')' : ''));
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'timeout' => 300,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $payload ?? '',
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $raw = @file_get_contents($url, false, $context);
            $statusLine = $http_response_header[0] ?? '';
            preg_match('/\s(\d{3})\s/', $statusLine, $m);
            $status = (int) ($m[1] ?? 0);

            if ($raw === false || $status === 0) {
                throw new RuntimeException($errorMessage);
            }
        }

        if (strlen((string) $raw) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Plugin vrátil příliš velkou odpověď. Použij stránkovaný export.');
        }

        if ($status >= 400) {
            $decodedError = json_decode((string) $raw, true);
            $message = is_array($decodedError)
                ? (string) ($decodedError['message'] ?? $decodedError['error'] ?? $errorMessage)
                : $errorMessage;
            throw new RuntimeException($message);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Plugin odpověděl v neplatném formátu.');
        }

        if ($expectSuccess && (($decoded['success'] ?? false) !== true)) {
            throw new RuntimeException((string) ($decoded['message'] ?? 'Plugin vrátil neúspěšnou odpověď.'));
        }

        return $expectSuccess ? (array) ($decoded['data'] ?? []) : $decoded;
    }

    private function appendLocalMeta(array $connection): array
    {
        $latestBackup = $this->latestBackupMeta((int) ($connection['store_id'] ?? 0));
        if ($latestBackup) {
            $connection['last_backup_at'] = $latestBackup['created_at'] ?? null;
            $connection['last_backup_id'] = $latestBackup['backup_id'] ?? null;
        } else {
            $connection['last_backup_at'] = null;
            $connection['last_backup_id'] = null;
        }

        $connection['store_settings'] = $this->storeSettings((int) ($connection['store_id'] ?? 0));
        return $connection;
    }

    private function latestBackupMeta(int $storeId): ?array
    {
        $all = $this->listBackups($storeId);
        return $all[0] ?? null;
    }

    private function storeHasCatalog(int $storeId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 
            (SELECT COUNT(*) FROM {$this->categoriesTable} WHERE store_id = :store_id) AS cat_count,
            (SELECT COUNT(*) FROM {$this->productsTable} WHERE store_id = :store_id) AS prod_count");
        $stmt->execute(['store_id' => $storeId]);
        $row = $stmt->fetch() ?: [];
        return ((int) ($row['cat_count'] ?? 0) > 0) || ((int) ($row['prod_count'] ?? 0) > 0);
    }

    private function fetchStoreCategories(int $storeId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->categoriesTable} WHERE store_id = :store_id ORDER BY id ASC");
        $stmt->execute(['store_id' => $storeId]);
        return $stmt->fetchAll() ?: [];
    }

    private function fetchStoreProducts(int $storeId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->productsTable} WHERE store_id = :store_id ORDER BY id ASC");
        $stmt->execute(['store_id' => $storeId]);
        return $stmt->fetchAll() ?: [];
    }

    private function fetchStoreVariants(int $storeId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->variantsTable} WHERE store_id = :store_id ORDER BY product_id ASC, sort_order ASC, id ASC");
        $stmt->execute(['store_id' => $storeId]);
        return $stmt->fetchAll() ?: [];
    }

    private function clearWooMapsIfPresent(int $storeId): void
    {
        foreach (['product_woo_map', 'category_woo_map', 'woo_product_map', 'woo_category_map'] as $table) {
            try {
                $this->pdo->prepare("DELETE FROM {$table} WHERE store_id = :store_id")->execute(['store_id' => $storeId]);
            } catch (\Throwable $e) {
                // mapovací tabulky nemusí být nasazené ve všech prostředích
            }
        }
    }

    private function backupFile(int $storeId, string $backupId): string
    {
        return $this->backupDir . '/store-' . $storeId . '-' . preg_replace('/[^a-zA-Z0-9\-_]/', '', $backupId) . '.json';
    }

    private function settingsFile(int $storeId): string
    {
        return $this->settingsDir . '/store-' . $storeId . '.json';
    }

    private function normalizeBaseUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = rtrim($value, '/');
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('Konektor vyžaduje platnou HTTPS adresu.');
        }

        $host = (string) $parts['host'];
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            throw new RuntimeException('Doménu konektoru se nepodařilo ověřit.');
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Konektor nesmí směřovat na lokální nebo privátní adresu.');
            }
        }

        return $value;
    }

    private function slug(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9\-]+/u', '-', $ascii) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';
        return trim($value, '-') ?: 'item-' . substr(md5($value . microtime(true)), 0, 8);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function sanitizeHtml(string $html): string
    {
        $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><a><h2><h3><h4><blockquote>');
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? '';
        $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? '';
        return preg_replace('/(href\s*=\s*["\'])\s*(javascript:|data:text\/html)/iu', '$1#', $html) ?? '';
    }
}
