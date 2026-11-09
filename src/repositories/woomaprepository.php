<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class WooMapRepository
{
    private string $productMapTable;
    private string $categoryMapTable;

    public function __construct(private PDO $pdo, array $config)
    {
        $this->productMapTable = $config['tables']['woo_product_map'] ?? 'woo_product_map';
        $this->categoryMapTable = $config['tables']['woo_category_map'] ?? 'woo_category_map';
    }

    public function productMap(int $storeId, int $productId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->productMapTable} WHERE store_id = :store_id AND product_id = :product_id LIMIT 1");
        $stmt->execute(['store_id' => $storeId, 'product_id' => $productId]);
        $row = $stmt->fetch();
        return $row ? $this->mapProductRow($row) : null;
    }

    public function categoryMap(int $storeId, int $categoryId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->categoryMapTable} WHERE store_id = :store_id AND category_id = :category_id LIMIT 1");
        $stmt->execute(['store_id' => $storeId, 'category_id' => $categoryId]);
        $row = $stmt->fetch();
        return $row ? $this->mapCategoryRow($row) : null;
    }

    public function categoryMapByWooTermId(int $storeId, int $wooTermId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->categoryMapTable} WHERE store_id = :store_id AND woo_term_id = :woo_term_id LIMIT 1");
        $stmt->execute(['store_id' => $storeId, 'woo_term_id' => $wooTermId]);
        $row = $stmt->fetch();
        return $row ? $this->mapCategoryRow($row) : null;
    }

    public function productMapByWooProductId(int $storeId, int $wooProductId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->productMapTable} WHERE store_id = :store_id AND woo_product_id = :woo_product_id LIMIT 1");
        $stmt->execute(['store_id' => $storeId, 'woo_product_id' => $wooProductId]);
        $row = $stmt->fetch();
        return $row ? $this->mapProductRow($row) : null;
    }

    public function upsertProductMap(int $storeId, int $productId, array $data): array
    {
        $existing = $this->productMap($storeId, $productId);
        $payload = [
            'store_id' => $storeId,
            'product_id' => $productId,
            'woo_product_id' => isset($data['woo_product_id']) && $data['woo_product_id'] !== '' ? (int) $data['woo_product_id'] : ($existing['woo_product_id'] ?? null),
            'woo_parent_id' => isset($data['woo_parent_id']) && $data['woo_parent_id'] !== '' ? (int) $data['woo_parent_id'] : ($existing['woo_parent_id'] ?? null),
            'sync_status' => (string) ($data['sync_status'] ?? ($existing['sync_status'] ?? 'pending')),
            'last_synced_at' => $data['last_synced_at'] ?? ($existing['last_synced_at'] ?? null),
            'last_error' => array_key_exists('last_error', $data) ? $this->nullableString($data['last_error']) : ($existing['last_error'] ?? null),
        ];

        if ($existing) {
            $sql = "UPDATE {$this->productMapTable}
                    SET woo_product_id = :woo_product_id,
                        woo_parent_id = :woo_parent_id,
                        sync_status = :sync_status,
                        last_synced_at = :last_synced_at,
                        last_error = :last_error,
                        updated_at = NOW()
                    WHERE store_id = :store_id AND product_id = :product_id
                    LIMIT 1";
        } else {
            $sql = "INSERT INTO {$this->productMapTable} (
                        store_id, product_id, woo_product_id, woo_parent_id, sync_status, last_synced_at, last_error, created_at, updated_at
                    ) VALUES (
                        :store_id, :product_id, :woo_product_id, :woo_parent_id, :sync_status, :last_synced_at, :last_error, NOW(), NOW()
                    )";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($payload);
        return $this->productMap($storeId, $productId) ?? $payload;
    }

    public function upsertCategoryMap(int $storeId, int $categoryId, array $data): array
    {
        $existing = $this->categoryMap($storeId, $categoryId);
        $payload = [
            'store_id' => $storeId,
            'category_id' => $categoryId,
            'woo_term_id' => isset($data['woo_term_id']) && $data['woo_term_id'] !== '' ? (int) $data['woo_term_id'] : ($existing['woo_term_id'] ?? null),
            'woo_parent_term_id' => isset($data['woo_parent_term_id']) && $data['woo_parent_term_id'] !== '' ? (int) $data['woo_parent_term_id'] : ($existing['woo_parent_term_id'] ?? null),
            'sync_status' => (string) ($data['sync_status'] ?? ($existing['sync_status'] ?? 'pending')),
            'last_synced_at' => $data['last_synced_at'] ?? ($existing['last_synced_at'] ?? null),
            'last_error' => array_key_exists('last_error', $data) ? $this->nullableString($data['last_error']) : ($existing['last_error'] ?? null),
        ];

        if ($existing) {
            $sql = "UPDATE {$this->categoryMapTable}
                    SET woo_term_id = :woo_term_id,
                        woo_parent_term_id = :woo_parent_term_id,
                        sync_status = :sync_status,
                        last_synced_at = :last_synced_at,
                        last_error = :last_error,
                        updated_at = NOW()
                    WHERE store_id = :store_id AND category_id = :category_id
                    LIMIT 1";
        } else {
            $sql = "INSERT INTO {$this->categoryMapTable} (
                        store_id, category_id, woo_term_id, woo_parent_term_id, sync_status, last_synced_at, last_error, created_at, updated_at
                    ) VALUES (
                        :store_id, :category_id, :woo_term_id, :woo_parent_term_id, :sync_status, :last_synced_at, :last_error, NOW(), NOW()
                    )";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($payload);
        return $this->categoryMap($storeId, $categoryId) ?? $payload;
    }

    public function summary(int $storeId): array
    {
        $productsTotal = (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE store_id = " . (int) $storeId)->fetchColumn();
        $categoriesTotal = (int) $this->pdo->query("SELECT COUNT(*) FROM categories WHERE store_id = " . (int) $storeId)->fetchColumn();

        $stmtProducts = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->productMapTable} WHERE store_id = :store_id AND woo_product_id IS NOT NULL");
        $stmtProducts->execute(['store_id' => $storeId]);
        $productsMapped = (int) $stmtProducts->fetchColumn();

        $stmtCategories = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->categoryMapTable} WHERE store_id = :store_id AND woo_term_id IS NOT NULL");
        $stmtCategories->execute(['store_id' => $storeId]);
        $categoriesMapped = (int) $stmtCategories->fetchColumn();

        return [
            'products_total' => $productsTotal,
            'products_mapped' => $productsMapped,
            'products_unmapped' => max(0, $productsTotal - $productsMapped),
            'categories_total' => $categoriesTotal,
            'categories_mapped' => $categoriesMapped,
            'categories_unmapped' => max(0, $categoriesTotal - $categoriesMapped),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function mapProductRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'store_id' => (int) $row['store_id'],
            'product_id' => (int) $row['product_id'],
            'woo_product_id' => $row['woo_product_id'] !== null ? (int) $row['woo_product_id'] : null,
            'woo_parent_id' => $row['woo_parent_id'] !== null ? (int) $row['woo_parent_id'] : null,
            'sync_status' => (string) ($row['sync_status'] ?? 'pending'),
            'last_synced_at' => $row['last_synced_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function mapCategoryRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'store_id' => (int) $row['store_id'],
            'category_id' => (int) $row['category_id'],
            'woo_term_id' => $row['woo_term_id'] !== null ? (int) $row['woo_term_id'] : null,
            'woo_parent_term_id' => $row['woo_parent_term_id'] !== null ? (int) $row['woo_parent_term_id'] : null,
            'sync_status' => (string) ($row['sync_status'] ?? 'pending'),
            'last_synced_at' => $row['last_synced_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
