<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\CatalogRepository;
use App\Repositories\StoreConnectionRepository;
use App\Repositories\WooMapRepository;
use RuntimeException;

final class WooSyncService
{
    public function __construct(
        private CatalogRepository $catalog,
        private StoreConnectionRepository $connections,
        private WooMapRepository $maps,
        private WooConnectorService $connector
    ) {}

    public function syncSummary(int $storeId): array
    {
        $connection = $this->connections->findByStoreId($storeId);
        if ($connection !== null) {
            $connection['token_configured'] = !empty($connection['plugin_token']) || !empty($connection['token_configured']);
            unset($connection['plugin_token']);
        }
        return [
            'connection' => $connection,
            'mapping' => $this->maps->summary($storeId),
        ];
    }

    public function setProductMap(int $storeId, int $productId, array $data): array
    {
        if ($this->catalog->findProduct($storeId, $productId) === null) {
            throw new RuntimeException('Produkt nebyl nalezen.');
        }

        return $this->maps->upsertProductMap($storeId, $productId, [
            'woo_product_id' => $data['woo_product_id'] ?? null,
            'woo_parent_id' => $data['woo_parent_id'] ?? null,
            'sync_status' => $data['sync_status'] ?? 'pending',
            'last_synced_at' => $data['last_synced_at'] ?? null,
            'last_error' => $data['last_error'] ?? null,
        ]);
    }

    public function setCategoryMap(int $storeId, int $categoryId, array $data): array
    {
        if ($this->catalog->findCategory($storeId, $categoryId) === null) {
            throw new RuntimeException('Kategorie nebyla nalezena.');
        }

        return $this->maps->upsertCategoryMap($storeId, $categoryId, [
            'woo_term_id' => $data['woo_term_id'] ?? null,
            'woo_parent_term_id' => $data['woo_parent_term_id'] ?? null,
            'sync_status' => $data['sync_status'] ?? 'pending',
            'last_synced_at' => $data['last_synced_at'] ?? null,
            'last_error' => $data['last_error'] ?? null,
        ]);
    }

    public function productPayload(int $storeId, int $productId): array
    {
        $product = $this->catalog->findProduct($storeId, $productId);
        if ($product === null) {
            throw new RuntimeException('Produkt nebyl nalezen.');
        }

        $map = $this->maps->productMap($storeId, $productId);
        $categories = [];
        foreach ((array) ($product['categoryIds'] ?? []) as $categoryId) {
            $category = $this->catalog->findCategory($storeId, (int) $categoryId);
            if ($category === null) {
                continue;
            }
            $categoryMap = $this->maps->categoryMap($storeId, (int) $category['id']);
            $categories[] = [
                'app_category_id' => (int) $category['id'],
                'woo_term_id' => $categoryMap['woo_term_id'] ?? null,
                'name' => (string) $category['name'],
                'slug' => (string) ($category['slug'] ?? ''),
                'parent_app_category_id' => $category['parent_id'] ?? null,
                'parent_woo_term_id' => $categoryMap['woo_parent_term_id'] ?? null,
            ];
        }

        $images = [];
        $primaryImage = trim((string) ($product['image'] ?? ''));
        if ($primaryImage !== '') {
            $images[] = ['url' => $primaryImage, 'role' => 'featured'];
        }

        foreach ((array) ($product['galleryImages'] ?? []) as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $images[] = ['url' => $url, 'role' => 'gallery'];
        }

        $images = array_values(array_map('unserialize', array_unique(array_map('serialize', $images))));

        return [
            'store_product_id' => (int) $product['id'],
            'woo_product_id' => $map['woo_product_id'] ?? null,
            'woo_parent_id' => $map['woo_parent_id'] ?? null,
            'type' => (string) ($product['type'] ?? 'simple'),
            'name' => (string) ($product['name'] ?? ''),
            'slug' => (string) ($product['slug'] ?? ''),
            'sku' => (string) ($product['sku'] ?? ''),
            'status' => ((int) ($product['is_active'] ?? 1) === 1 && (($product['status'] ?? 'draft') !== 'draft')) ? 'publish' : (string) ($product['status'] ?? 'draft'),
            'description' => (string) ($product['description'] ?? ''),
            'short_description' => (string) ($product['shortDescription'] ?? ''),
            'regular_price' => (string) ($product['regularPrice'] ?? ''),
            'sale_price' => (string) ($product['salePrice'] ?? ''),
            'manage_stock' => (bool) ($product['manage_stock'] ?? true),
            'stock_quantity' => $product['stock'] === '' ? null : (int) $product['stock'],
            'stock_status' => (string) ($product['stock_status'] ?? 'instock'),
            'categories' => $categories,
            'images' => $images,
            'attributes' => array_values((array) ($product['attributes'] ?? [])),
            'variants' => array_values((array) ($product['variants'] ?? [])),
        ];
    }

    public function syncProduct(int $storeId, int $productId): array
    {
        $connection = $this->connections->findByStoreId($storeId);
        if (!$connection || empty($connection['wp_base_url']) || empty($connection['plugin_token'])) {
            throw new RuntimeException('Pro tento e-shop chybí WooCommerce připojení.');
        }

        $payload = $this->productPayload($storeId, $productId);

        try {
            $response = $this->connector->fetchPluginJson(
                (string) $connection['wp_base_url'],
                (string) $connection['plugin_token'],
                '/wp-json/feedhub-connector/v1/product/upsert',
                'Viki Connector nepodporuje zápis produktu nebo vrátil chybu.',
                true,
                'POST',
                $payload
            );
        } catch (RuntimeException $e) {
            $this->maps->upsertProductMap($storeId, $productId, [
                'sync_status' => 'error',
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $wooProductId = isset($response['woo_product_id']) && $response['woo_product_id'] !== null
            ? (int) $response['woo_product_id']
            : null;

        $syncedAt = date('Y-m-d H:i:s');

        foreach ((array) ($response['category_mappings'] ?? []) as $categoryMapping) {
            $appCategoryId = isset($categoryMapping['app_category_id']) ? (int) $categoryMapping['app_category_id'] : 0;
            if ($appCategoryId <= 0) {
                continue;
            }

            $this->maps->upsertCategoryMap($storeId, $appCategoryId, [
                'woo_term_id' => isset($categoryMapping['woo_term_id']) ? (int) $categoryMapping['woo_term_id'] : null,
                'woo_parent_term_id' => isset($categoryMapping['woo_parent_term_id']) ? (int) $categoryMapping['woo_parent_term_id'] : null,
                'sync_status' => 'synced',
                'last_synced_at' => $syncedAt,
                'last_error' => null,
            ]);
        }

        $map = $this->maps->upsertProductMap($storeId, $productId, [
            'woo_product_id' => $wooProductId,
            'woo_parent_id' => isset($response['woo_parent_id']) ? (int) $response['woo_parent_id'] : null,
            'sync_status' => 'synced',
            'last_synced_at' => $syncedAt,
            'last_error' => null,
        ]);

        return [
            'payload' => $payload,
            'plugin_response' => $response,
            'map' => $map,
        ];
    }
}
