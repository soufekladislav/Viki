<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;
use RuntimeException;

final class CatalogRepository
{
    private string $productsTable;
    private string $categoriesTable;
    private string $productWooMapTable;
    private string $categoryWooMapTable;
    private string $variantsTable;

    public function __construct(private PDO $pdo, array $config)
    {
        $this->productsTable = $config['tables']['products'];
        $this->categoriesTable = $config['tables']['categories'];
        $this->productWooMapTable = $config['tables']['woo_product_map'] ?? ($config['tables']['product_woo_map'] ?? 'woo_product_map');
        $this->categoryWooMapTable = $config['tables']['woo_category_map'] ?? ($config['tables']['category_woo_map'] ?? 'woo_category_map');
        $this->variantsTable = $config['tables']['product_variants'] ?? 'product_variants';
    }

    public function products(int $storeId, array $filters): array
    {
        $where = ['p.store_id = :store_id'];
        $params = ['store_id' => $storeId];

        if (!empty($filters['q'])) {
            $where[] = '(p.name LIKE :q OR p.sku LIKE :q OR p.slug LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }

        if ($filters['active'] !== null && $filters['active'] !== '') {
            $where[] = 'p.is_active = :active';
            $params['active'] = (int) $filters['active'];
        }

        foreach (['type', 'status'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = 'p.' . $field . ' = :' . $field;
                $params[$field] = (string) $filters[$field];
            }
        }

        if (!empty($filters['woo_sync_status'])) {
            $where[] = 'COALESCE(pwm.sync_status, \'not_synced\') = :woo_sync_status';
            $params['woo_sync_status'] = (string) $filters['woo_sync_status'];
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(200, max(1, (int) ($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $needsWooJoin = !empty($filters['woo_sync_status']);
        $countSql = 'SELECT COUNT(*) AS total FROM ' . $this->productsTable . ' p'
            . ($needsWooJoin ? ' LEFT JOIN ' . $this->productWooMapTable . ' pwm ON pwm.store_id = p.store_id AND pwm.product_id = p.id' : '')
            . ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $sortMap = [
            'updated_at' => 'p.updated_at',
            'created_at' => 'p.created_at',
            'name' => 'p.name',
            'sku' => 'p.sku',
            'price' => 'p.regular_price',
            'stock' => 'p.stock',
        ];
        $sort = $sortMap[(string) ($filters['sort'] ?? '')] ?? 'p.updated_at';
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $sql = 'SELECT p.id, p.store_id, p.source_type, p.external_id, p.source_updated_at, p.category_id, p.sku, p.name, p.slug, p.type, p.status,
                       p.short_description, p.description, p.regular_price, p.sale_price, p.stock,
                       p.image_url, p.payload_json, p.is_active, p.created_at, p.updated_at,
                       c.name AS category_name,
                       pwm.woo_product_id,
                       pwm.sync_status AS woo_sync_status,
                       pwm.last_synced_at AS woo_last_synced_at,
                       pwm.last_error AS woo_last_error,
                       (SELECT COUNT(*) FROM ' . $this->variantsTable . ' pv WHERE pv.store_id = p.store_id AND pv.product_id = p.id AND pv.is_active = 1) AS variant_count,
                       (SELECT COALESCE(SUM(pv.stock), 0) FROM ' . $this->variantsTable . ' pv WHERE pv.store_id = p.store_id AND pv.product_id = p.id AND pv.is_active = 1) AS variant_stock
                FROM ' . $this->productsTable . ' p
                LEFT JOIN ' . $this->categoriesTable . ' c ON c.id = p.category_id
                LEFT JOIN ' . $this->productWooMapTable . ' pwm ON pwm.store_id = p.store_id AND pwm.product_id = p.id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY ' . $sort . ' ' . $direction . ', p.id ' . $direction . '
                LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $items = array_map(fn(array $row): array => $this->mapProductRow($row), $stmt->fetchAll() ?: []);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
            ],
        ];
    }

    public function findProduct(int $storeId, int $id): ?array
    {
        $sql = 'SELECT p.*, c.name AS category_name,
                       pwm.woo_product_id,
                       pwm.sync_status AS woo_sync_status,
                       pwm.last_synced_at AS woo_last_synced_at,
                       pwm.last_error AS woo_last_error
                FROM ' . $this->productsTable . ' p
                LEFT JOIN ' . $this->categoriesTable . ' c ON c.id = p.category_id
                LEFT JOIN ' . $this->productWooMapTable . ' pwm ON pwm.store_id = p.store_id AND pwm.product_id = p.id
                WHERE p.store_id = :store_id AND p.id = :id
                LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['store_id' => $storeId, 'id' => $id]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $product = $this->mapProductRow($row);
        $variants = $this->variantsForProduct($storeId, $id);
        if ($variants !== []) {
            $product['variants'] = $variants;
        }
        return $product;
    }

    public function createProduct(int $storeId, array $data): array
    {
        try {
            $this->pdo->beginTransaction();
            $sql = 'INSERT INTO ' . $this->productsTable . ' (
                        store_id, source_type, external_id, source_updated_at, category_id, sku, name, slug, type, status, short_description, description,
                        regular_price, sale_price, stock, image_url, payload_json, is_active, created_at, updated_at
                    ) VALUES (
                        :store_id, :source_type, :external_id, :source_updated_at, :category_id, :sku, :name, :slug, :type, :status, :short_description, :description,
                        :regular_price, :sale_price, :stock, :image_url, :payload_json, :is_active, NOW(), NOW()
                    )';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->productParams($storeId, $data));
            $productId = (int) $this->pdo->lastInsertId();
            $this->replaceProductVariants($storeId, $productId, (array) ($data['variants'] ?? []));
            $this->pdo->commit();

            return $this->findProduct($storeId, $productId)
                ?? throw new RuntimeException('Product create failed');
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->mapDatabaseException($e, 'Produkt se nepodařilo vytvořit.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateProduct(int $storeId, int $id, array $data): ?array
    {
        try {
            $this->pdo->beginTransaction();
            $sql = 'UPDATE ' . $this->productsTable . ' SET
                        source_type = :source_type,
                        external_id = :external_id,
                        source_updated_at = :source_updated_at,
                        category_id = :category_id,
                        sku = :sku,
                        name = :name,
                        slug = :slug,
                        type = :type,
                        status = :status,
                        short_description = :short_description,
                        description = :description,
                        regular_price = :regular_price,
                        sale_price = :sale_price,
                        stock = :stock,
                        image_url = :image_url,
                        payload_json = :payload_json,
                        is_active = :is_active,
                        updated_at = NOW()
                    WHERE store_id = :store_id AND id = :id';

            $stmt = $this->pdo->prepare($sql);
            $params = $this->productParams($storeId, $data) + ['id' => $id];
            $stmt->execute($params);
            $this->replaceProductVariants($storeId, $id, (array) ($data['variants'] ?? []));
            $this->pdo->commit();

            return $this->findProduct($storeId, $id);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->mapDatabaseException($e, 'Produkt se nepodařilo upravit.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteProduct(int $storeId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->productsTable . ' WHERE store_id = :store_id AND id = :id');
        return $stmt->execute(['store_id' => $storeId, 'id' => $id]);
    }

    public function bulkMoveProducts(int $storeId, array $ids, ?int $categoryId): int
    {
        if ($ids === []) {
            return 0;
        }

        $moved = 0;
        foreach ($ids as $id) {
            $product = $this->findProduct($storeId, (int) $id);
            if ($product === null) {
                continue;
            }

            $categoryIds = $categoryId !== null ? [$categoryId] : [];
            $payload = [
                'category_ids' => $categoryIds,
                'category_id' => $categoryId,
                'categoryIds' => $categoryIds,
                'gallery_images' => $product['galleryImages'] ?? [],
                'galleryImages' => $product['galleryImages'] ?? [],
                'attributes' => $product['attributes'] ?? new \stdClass(),
                'variants' => $product['variants'] ?? [],
                'image_url' => $product['image'] ?? '',
                'is_active' => (int) ($product['is_active'] ?? 1),
                'sku' => $product['sku'] ?? '',
                'name' => $product['name'] ?? '',
                'slug' => $product['slug'] ?? '',
                'type' => $product['type'] ?? 'simple',
                'status' => $product['status'] ?? 'draft',
                'short_description' => $product['shortDescription'] ?? '',
                'description' => $product['description'] ?? '',
                'regular_price' => $product['regularPrice'] ?? '',
                'sale_price' => $product['salePrice'] ?? '',
                'stock' => $product['stock'] ?? '',
                'virtual' => (bool) ($product['virtual'] ?? false),
                'downloadable' => (bool) ($product['downloadable'] ?? false),
                'manage_stock' => array_key_exists('manage_stock', $product) ? (bool) $product['manage_stock'] : true,
                'stock_status' => $product['stock_status'] ?? 'instock',
                'supplier' => $product['supplier'] ?? '',
                'purchase_price' => $product['purchase_price'] ?? '',
                'weight' => $product['weight'] ?? '',
                'length' => $product['length'] ?? '',
                'width' => $product['width'] ?? '',
                'height' => $product['height'] ?? '',
            ];

            $updated = $this->updateProduct($storeId, (int) $id, $payload);
            if ($updated !== null) {
                $moved++;
            }
        }

        return $moved;
    }

    public function bulkDeleteProducts(int $storeId, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'DELETE FROM ' . $this->productsTable . ' WHERE store_id = ? AND id IN (' . $placeholders . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$storeId], $ids));

        return $stmt->rowCount();
    }

    public function categories(int $storeId, ?int $active = null): array
    {
        $where = ['c.store_id = :store_id'];
        $params = ['store_id' => $storeId];

        if ($active !== null) {
            $where[] = 'c.is_active = :active';
            $params['active'] = $active;
        }

        $sql = 'SELECT c.id, c.store_id, c.parent_id, c.name, c.slug, c.description, c.image_url, c.sort_order, c.is_active, c.created_at, c.updated_at,
                       (SELECT COUNT(*) FROM ' . $this->productsTable . ' pc WHERE pc.store_id = c.store_id AND pc.category_id = c.id) AS direct_product_count,
                       cwm.woo_term_id AS woo_category_id,
                       cwm.woo_parent_term_id,
                       cwm.sync_status AS woo_sync_status,
                       cwm.last_synced_at AS woo_last_synced_at,
                       cwm.last_error AS woo_last_error
                FROM ' . $this->categoriesTable . ' c
                LEFT JOIN ' . $this->categoryWooMapTable . ' cwm ON cwm.store_id = c.store_id AND cwm.category_id = c.id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY COALESCE(c.parent_id, 0) ASC, c.sort_order ASC, c.name ASC, c.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn(array $row): array => $this->mapCategoryRow($row), $stmt->fetchAll() ?: []);
    }

    public function findCategory(int $storeId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.store_id, c.parent_id, c.name, c.slug, c.description, c.image_url, c.sort_order, c.is_active, c.created_at, c.updated_at,
                    cwm.woo_term_id AS woo_category_id,
                       cwm.woo_parent_term_id,
                    cwm.sync_status AS woo_sync_status,
                    cwm.last_synced_at AS woo_last_synced_at,
                    cwm.last_error AS woo_last_error
             FROM ' . $this->categoriesTable . ' c
             LEFT JOIN ' . $this->categoryWooMapTable . ' cwm ON cwm.store_id = c.store_id AND cwm.category_id = c.id
             WHERE c.store_id = :store_id AND c.id = :id
             LIMIT 1'
        );
        $stmt->execute(['store_id' => $storeId, 'id' => $id]);

        $row = $stmt->fetch();
        return $row ? $this->mapCategoryRow($row) : null;
    }

    public function createCategory(int $storeId, array $data): array
    {
        try {
            $sql = 'INSERT INTO ' . $this->categoriesTable . ' (
                        store_id, parent_id, name, slug, description, image_url, sort_order, is_active, created_at, updated_at
                    ) VALUES (
                        :store_id, :parent_id, :name, :slug, :description, :image_url, :sort_order, :is_active, NOW(), NOW()
                    )';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->categoryParams($storeId, $data));

            $id = (int) $this->pdo->lastInsertId();
            $this->resequenceCategorySiblings($storeId, $data['parent_id'] ?? null);

            return $this->findCategory($storeId, $id)
                ?? throw new RuntimeException('Category create failed');
        } catch (PDOException $e) {
            throw $this->mapDatabaseException($e, 'Kategorii se nepodařilo vytvořit.');
        }
    }

    public function updateCategory(int $storeId, int $id, array $data): ?array
    {
        try {
            $sql = 'UPDATE ' . $this->categoriesTable . ' SET
                        parent_id = :parent_id,
                        name = :name,
                        slug = :slug,
                        description = :description,
                        image_url = :image_url,
                        sort_order = :sort_order,
                        is_active = :is_active,
                        updated_at = NOW()
                    WHERE store_id = :store_id AND id = :id';

            $stmt = $this->pdo->prepare($sql);
            $params = $this->categoryParams($storeId, $data) + ['id' => $id];
            $stmt->execute($params);

            return $this->findCategory($storeId, $id);
        } catch (PDOException $e) {
            throw $this->mapDatabaseException($e, 'Kategorii se nepodařilo upravit.');
        }
    }

    public function moveCategory(int $storeId, int $categoryId, ?int $parentId, ?int $beforeId = null, ?int $sortOrder = null): array
    {
        $category = $this->findCategory($storeId, $categoryId);
        if ($category === null) {
            throw new RuntimeException('Kategorie nebyla nalezena.');
        }

        $oldParentId = $category['parent_id'] ?? null;
        $siblings = $this->categories($storeId, null);
        $targetSiblings = array_values(array_filter($siblings, function (array $item) use ($parentId, $categoryId): bool {
            if ((int) $item['id'] === $categoryId) {
                return false;
            }
            return ($item['parent_id'] ?? null) === $parentId;
        }));

        if ($beforeId !== null) {
            $position = null;
            foreach ($targetSiblings as $index => $sibling) {
                if ((int) $sibling['id'] === $beforeId) {
                    $position = $index;
                    break;
                }
            }

            if ($position === null) {
                $targetSiblings[] = $category;
            } else {
                array_splice($targetSiblings, $position, 0, [$category]);
            }
        } else {
            $category['sort_order'] = $sortOrder ?? ($this->getNextCategorySortOrder($storeId, $parentId));
            $targetSiblings[] = $category;
            usort($targetSiblings, function (array $a, array $b): int {
                $aOrder = (int) ($a['sort_order'] ?? 0);
                $bOrder = (int) ($b['sort_order'] ?? 0);
                return $aOrder <=> $bOrder ?: ((int) $a['id'] <=> (int) $b['id']);
            });
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . $this->categoriesTable . ' SET parent_id = :parent_id, sort_order = :sort_order, updated_at = NOW() WHERE store_id = :store_id AND id = :id'
            );

            $order = 10;
            foreach ($targetSiblings as $item) {
                $stmt->execute([
                    'parent_id' => $parentId,
                    'sort_order' => $order,
                    'store_id' => $storeId,
                    'id' => (int) $item['id'],
                ]);
                $order += 10;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->resequenceCategorySiblings($storeId, $oldParentId);
        $this->resequenceCategorySiblings($storeId, $parentId);

        return $this->findCategory($storeId, $categoryId)
            ?? throw new RuntimeException('Kategorie nebyla po přesunu nalezena.');
    }

    public function deleteCategory(int $storeId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->categoriesTable . ' WHERE store_id = :store_id AND id = :id');
        return $stmt->execute(['store_id' => $storeId, 'id' => $id]);
    }

    public function categoryHasChildren(int $storeId, int $categoryId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM ' . $this->categoriesTable . ' WHERE store_id = :store_id AND parent_id = :parent_id');
        $stmt->execute(['store_id' => $storeId, 'parent_id' => $categoryId]);
        return (int) ($stmt->fetch()['cnt'] ?? 0) > 0;
    }

    public function categoryHasProducts(int $storeId, int $categoryId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM ' . $this->productsTable . ' WHERE store_id = :store_id AND category_id = :category_id');
        $stmt->execute(['store_id' => $storeId, 'category_id' => $categoryId]);
        if ((int) ($stmt->fetch()['cnt'] ?? 0) > 0) {
            return true;
        }

        $stmt = $this->pdo->prepare('SELECT payload_json FROM ' . $this->productsTable . ' WHERE store_id = :store_id AND payload_json IS NOT NULL');
        $stmt->execute(['store_id' => $storeId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }

            $ids = array_map('intval', (array) ($payload['categoryIds'] ?? []));
            if (in_array($categoryId, $ids, true)) {
                return true;
            }
        }

        return false;
    }

    public function isCategoryDescendant(int $storeId, int $candidateChildId, int $possibleAncestorId): bool
    {
        $current = $this->findCategory($storeId, $candidateChildId);
        while ($current !== null && ($current['parent_id'] ?? null) !== null) {
            $parentId = (int) $current['parent_id'];
            if ($parentId === $possibleAncestorId) {
                return true;
            }
            $current = $this->findCategory($storeId, $parentId);
        }

        return false;
    }

    public function getNextCategorySortOrder(int $storeId, ?int $parentId): int
    {
        $sql = 'SELECT MAX(sort_order) AS max_sort FROM ' . $this->categoriesTable . ' WHERE store_id = :store_id AND ';
        $params = ['store_id' => $storeId];

        if ($parentId === null) {
            $sql .= 'parent_id IS NULL';
        } else {
            $sql .= 'parent_id = :parent_id';
            $params['parent_id'] = $parentId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $max = (int) ($stmt->fetch()['max_sort'] ?? 0);

        return $max > 0 ? $max + 10 : 10;
    }

    public function resequenceCategorySiblings(int $storeId, ?int $parentId): void
    {
        $sql = 'SELECT id FROM ' . $this->categoriesTable . ' WHERE store_id = :store_id AND ';
        $params = ['store_id' => $storeId];

        if ($parentId === null) {
            $sql .= 'parent_id IS NULL';
        } else {
            $sql .= 'parent_id = :parent_id';
            $params['parent_id'] = $parentId;
        }

        $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));

        if ($ids === []) {
            return;
        }

        $update = $this->pdo->prepare('UPDATE ' . $this->categoriesTable . ' SET sort_order = :sort_order, updated_at = NOW() WHERE store_id = :store_id AND id = :id');
        $order = 10;
        foreach ($ids as $id) {
            $update->execute([
                'sort_order' => $order,
                'store_id' => $storeId,
                'id' => $id,
            ]);
            $order += 10;
        }
    }

    public function productWooMap(int $storeId, int $productId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, store_id, product_id, woo_product_id, sync_status, last_synced_at, last_error, created_at, updated_at
             FROM ' . $this->productWooMapTable . '
             WHERE store_id = :store_id AND product_id = :product_id
             LIMIT 1'
        );
        $stmt->execute(['store_id' => $storeId, 'product_id' => $productId]);

        $row = $stmt->fetch();
        return $row ? $this->mapProductWooMapRow($row) : null;
    }

    public function categoryWooMap(int $storeId, int $categoryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, store_id, category_id, woo_term_id AS woo_category_id, woo_parent_term_id, sync_status, last_synced_at, last_error, created_at, updated_at
             FROM ' . $this->categoryWooMapTable . '
             WHERE store_id = :store_id AND category_id = :category_id
             LIMIT 1'
        );
        $stmt->execute(['store_id' => $storeId, 'category_id' => $categoryId]);

        $row = $stmt->fetch();
        return $row ? $this->mapCategoryWooMapRow($row) : null;
    }

    public function upsertProductWooMap(int $storeId, int $productId, array $data): array
    {
        $sql = 'INSERT INTO ' . $this->productWooMapTable . ' (
                    store_id, product_id, woo_product_id, sync_status, last_synced_at, last_error, created_at, updated_at
                ) VALUES (
                    :store_id, :product_id, :woo_product_id, :sync_status, :last_synced_at, :last_error, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    woo_product_id = VALUES(woo_product_id),
                    sync_status = VALUES(sync_status),
                    last_synced_at = VALUES(last_synced_at),
                    last_error = VALUES(last_error),
                    updated_at = NOW()';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'store_id' => $storeId,
            'product_id' => $productId,
            'woo_product_id' => $data['woo_product_id'] ?? null,
            'sync_status' => $data['sync_status'] ?? 'pending',
            'last_synced_at' => $data['last_synced_at'] ?? null,
            'last_error' => $data['last_error'] ?? null,
        ]);

        return $this->productWooMap($storeId, $productId)
            ?? throw new RuntimeException('Produkt Woo map se nepodařilo uložit.');
    }

    public function upsertCategoryWooMap(int $storeId, int $categoryId, array $data): array
    {
        $sql = 'INSERT INTO ' . $this->categoryWooMapTable . ' (
                    store_id, category_id, woo_term_id, woo_parent_term_id, sync_status, last_synced_at, last_error, created_at, updated_at
                ) VALUES (
                    :store_id, :category_id, :woo_term_id, :woo_parent_term_id, :sync_status, :last_synced_at, :last_error, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    woo_term_id = VALUES(woo_term_id),
                    woo_parent_term_id = VALUES(woo_parent_term_id),
                    sync_status = VALUES(sync_status),
                    last_synced_at = VALUES(last_synced_at),
                    last_error = VALUES(last_error),
                    updated_at = NOW()';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'store_id' => $storeId,
            'category_id' => $categoryId,
            'woo_term_id' => $data['woo_category_id'] ?? ($data['woo_term_id'] ?? null),
            'woo_parent_term_id' => $data['woo_parent_term_id'] ?? null,
            'sync_status' => $data['sync_status'] ?? 'pending',
            'last_synced_at' => $data['last_synced_at'] ?? null,
            'last_error' => $data['last_error'] ?? null,
        ]);

        return $this->categoryWooMap($storeId, $categoryId)
            ?? throw new RuntimeException('Kategorie Woo map se nepodařilo uložit.');
    }

    public function syncSummary(int $storeId): array
    {
        $productMapped = (int) ($this->pdo->query(
            'SELECT COUNT(*) AS cnt FROM ' . $this->productWooMapTable . ' WHERE store_id = ' . (int) $storeId . ' AND woo_product_id IS NOT NULL'
        )->fetch()['cnt'] ?? 0);

        $productTotal = (int) ($this->pdo->query(
            'SELECT COUNT(*) AS cnt FROM ' . $this->productsTable . ' WHERE store_id = ' . (int) $storeId
        )->fetch()['cnt'] ?? 0);

        $categoryMapped = (int) ($this->pdo->query(
            'SELECT COUNT(*) AS cnt FROM ' . $this->categoryWooMapTable . ' WHERE store_id = ' . (int) $storeId . ' AND woo_term_id IS NOT NULL'
        )->fetch()['cnt'] ?? 0);

        $categoryTotal = (int) ($this->pdo->query(
            'SELECT COUNT(*) AS cnt FROM ' . $this->categoriesTable . ' WHERE store_id = ' . (int) $storeId
        )->fetch()['cnt'] ?? 0);

        return [
            'products' => [
                'mapped' => $productMapped,
                'total' => $productTotal,
                'unmapped' => max(0, $productTotal - $productMapped),
            ],
            'categories' => [
                'mapped' => $categoryMapped,
                'total' => $categoryTotal,
                'unmapped' => max(0, $categoryTotal - $categoryMapped),
            ],
        ];
    }

    private function productParams(int $storeId, array $data): array
    {
        $payload = [
            'categoryIds' => $data['category_ids'] ?? [],
            'galleryImages' => $data['gallery_images'] ?? [],
            'attributes' => $data['attributes'] ?? new \stdClass(),
            'variants' => $data['variants'] ?? [],
            'virtual' => (bool) ($data['virtual'] ?? false),
            'downloadable' => (bool) ($data['downloadable'] ?? false),
            'manage_stock' => array_key_exists('manage_stock', $data) ? (bool) $data['manage_stock'] : true,
            'stock_status' => (string) ($data['stock_status'] ?? 'instock'),
            'supplier' => (string) ($data['supplier'] ?? ''),
            'purchase_price' => (string) ($data['purchase_price'] ?? ''),
            'weight' => (string) ($data['weight'] ?? ''),
            'length' => (string) ($data['length'] ?? ''),
            'width' => (string) ($data['width'] ?? ''),
            'height' => (string) ($data['height'] ?? ''),
        ];

        return [
            'store_id' => $storeId,
            'source_type' => $data['source_type'] ?? null,
            'external_id' => !empty($data['external_id']) ? (int) $data['external_id'] : null,
            'source_updated_at' => $data['source_updated_at'] ?? null,
            'category_id' => $data['category_id'] ?: null,
            'sku' => $data['sku'] ?? '',
            'name' => $data['name'],
            'slug' => $data['slug'],
            'type' => $data['type'] ?? 'simple',
            'status' => $data['status'] ?? 'draft',
            'short_description' => $data['short_description'] ?? '',
            'description' => $data['description'] ?? '',
            'regular_price' => $data['regular_price'] !== '' ? $data['regular_price'] : null,
            'sale_price' => $data['sale_price'] !== '' ? $data['sale_price'] : null,
            'stock' => $data['stock'] !== '' ? (int) $data['stock'] : null,
            'image_url' => $data['image_url'] ?? '',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ];
    }

    private function categoryParams(int $storeId, array $data): array
    {
        return [
            'store_id' => $storeId,
            'parent_id' => $data['parent_id'] ?: null,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? '',
            'image_url' => $data['image_url'] ?? '',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ];
    }

    private function mapProductRow(array $row): array
    {
        $payload = [];
        if (!empty($row['payload_json'])) {
            $decoded = json_decode((string) $row['payload_json'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $categoryIds = $payload['categoryIds'] ?? [];
        $categoryId = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null;

        if ($categoryId !== null && !in_array($categoryId, $categoryIds, true)) {
            array_unshift($categoryIds, $categoryId);
        }

        return [
            'id' => (int) $row['id'],
            'store_id' => (int) $row['store_id'],
            'source_type' => $row['source_type'] ?? null,
            'external_id' => isset($row['external_id']) ? (int) $row['external_id'] : null,
            'source_updated_at' => $row['source_updated_at'] ?? null,
            'category_id' => $categoryId,
            'categoryIds' => array_values(array_unique(array_map('intval', $categoryIds))),
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => (string) $row['name'],
            'slug' => (string) ($row['slug'] ?? ''),
            'type' => (string) ($row['type'] ?? 'simple'),
            'status' => (string) ($row['status'] ?? 'draft'),
            'shortDescription' => (string) ($row['short_description'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'regularPrice' => $row['regular_price'] !== null ? (string) $row['regular_price'] : '',
            'salePrice' => $row['sale_price'] !== null ? (string) $row['sale_price'] : '',
            'stock' => $row['stock'] !== null ? (string) $row['stock'] : '',
            'image' => (string) ($row['image_url'] ?? ''),
            'galleryImages' => array_values($payload['galleryImages'] ?? []),
            'attributes' => $payload['attributes'] ?? new \stdClass(),
            'variants' => array_values($payload['variants'] ?? []),
            'virtual' => (bool) ($payload['virtual'] ?? false),
            'downloadable' => (bool) ($payload['downloadable'] ?? false),
            'manage_stock' => array_key_exists('manage_stock', $payload) ? (bool) $payload['manage_stock'] : true,
            'stock_status' => (string) ($payload['stock_status'] ?? 'instock'),
            'supplier' => (string) ($payload['supplier'] ?? ''),
            'purchase_price' => (string) ($payload['purchase_price'] ?? ''),
            'weight' => (string) ($payload['weight'] ?? ''),
            'length' => (string) ($payload['length'] ?? ''),
            'width' => (string) ($payload['width'] ?? ''),
            'height' => (string) ($payload['height'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'category_name' => $row['category_name'] ?? null,
            'woo_product_id' => isset($row['woo_product_id']) && $row['woo_product_id'] !== null ? (int) $row['woo_product_id'] : null,
            'woo_sync_status' => (string) ($row['woo_sync_status'] ?? 'unmapped'),
            'woo_last_synced_at' => $row['woo_last_synced_at'] ?? null,
            'woo_last_error' => $row['woo_last_error'] ?? null,
            'variant_count' => (int) ($row['variant_count'] ?? count((array) ($payload['variants'] ?? []))),
            'variant_stock' => (int) ($row['variant_stock'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function replaceProductVariants(int $storeId, int $productId, array $variants): void
    {
        $delete = $this->pdo->prepare('DELETE FROM ' . $this->variantsTable . ' WHERE store_id = :store_id AND product_id = :product_id');
        $delete->execute(['store_id' => $storeId, 'product_id' => $productId]);
        if ($variants === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO ' . $this->variantsTable . ' (
                store_id, product_id, external_id, sku, name, regular_price, sale_price, stock,
                stock_status, attributes_json, sort_order, is_active, created_at, updated_at
             ) VALUES (
                :store_id, :product_id, :external_id, :sku, :name, :regular_price, :sale_price, :stock,
                :stock_status, :attributes_json, :sort_order, :is_active, NOW(), NOW()
             )'
        );

        foreach (array_values($variants) as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $insert->execute([
                'store_id' => $storeId,
                'product_id' => $productId,
                'external_id' => !empty($variant['external_id']) ? (int) $variant['external_id'] : null,
                'sku' => trim((string) ($variant['sku'] ?? '')) !== '' ? trim((string) $variant['sku']) : null,
                'name' => $variant['name'] ?? ($variant['label'] ?? null),
                'regular_price' => ($variant['regular_price'] ?? $variant['price'] ?? '') !== '' ? ($variant['regular_price'] ?? $variant['price']) : null,
                'sale_price' => ($variant['sale_price'] ?? '') !== '' ? $variant['sale_price'] : null,
                'stock' => ($variant['stock'] ?? '') !== '' ? (int) $variant['stock'] : null,
                'stock_status' => (string) ($variant['stock_status'] ?? 'instock'),
                'attributes_json' => json_encode($variant['attributes'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => (int) ($variant['sort_order'] ?? $index),
                'is_active' => array_key_exists('is_active', $variant) ? (int) ((bool) $variant['is_active']) : 1,
            ]);
        }
    }

    private function variantsForProduct(int $storeId, int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT external_id, sku, name, regular_price, sale_price, stock, stock_status, attributes_json, sort_order, is_active
             FROM ' . $this->variantsTable . '
             WHERE store_id = :store_id AND product_id = :product_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['store_id' => $storeId, 'product_id' => $productId]);
        return array_map(static function (array $row): array {
            $attributes = json_decode((string) ($row['attributes_json'] ?? '{}'), true);
            return [
                'external_id' => isset($row['external_id']) ? (int) $row['external_id'] : null,
                'sku' => (string) ($row['sku'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'label' => (string) ($row['name'] ?? ''),
                'regular_price' => $row['regular_price'] !== null ? (string) $row['regular_price'] : '',
                'price' => $row['regular_price'] !== null ? (string) $row['regular_price'] : '',
                'sale_price' => $row['sale_price'] !== null ? (string) $row['sale_price'] : '',
                'stock' => $row['stock'] !== null ? (string) $row['stock'] : '',
                'stock_status' => (string) ($row['stock_status'] ?? 'instock'),
                'attributes' => is_array($attributes) ? $attributes : [],
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'is_active' => (int) ($row['is_active'] ?? 1),
            ];
        }, $stmt->fetchAll() ?: []);
    }

    private function mapCategoryRow(array $row): array
    {
        $parentId = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null;

        return [
            'id' => (int) $row['id'],
            'store_id' => (int) $row['store_id'],
            'parent_id' => $parentId,
            'parentId' => $parentId,
            'name' => (string) $row['name'],
            'slug' => (string) ($row['slug'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'image' => (string) ($row['image_url'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'direct_product_count' => (int) ($row['direct_product_count'] ?? 0),
            'woo_category_id' => isset($row['woo_category_id']) && $row['woo_category_id'] !== null ? (int) $row['woo_category_id'] : null,
            'woo_sync_status' => (string) ($row['woo_sync_status'] ?? 'unmapped'),
            'woo_last_synced_at' => $row['woo_last_synced_at'] ?? null,
            'woo_last_error' => $row['woo_last_error'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function mapProductWooMapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'store_id' => (int) $row['store_id'],
            'product_id' => (int) $row['product_id'],
            'woo_product_id' => $row['woo_product_id'] !== null ? (int) $row['woo_product_id'] : null,
            'sync_status' => (string) ($row['sync_status'] ?? 'pending'),
            'last_synced_at' => $row['last_synced_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function mapCategoryWooMapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'store_id' => (int) $row['store_id'],
            'category_id' => (int) $row['category_id'],
            'woo_category_id' => $row['woo_category_id'] !== null ? (int) $row['woo_category_id'] : null,
            'sync_status' => (string) ($row['sync_status'] ?? 'pending'),
            'last_synced_at' => $row['last_synced_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function mapDatabaseException(PDOException $e, string $fallback): RuntimeException
    {
        $message = $fallback;
        $driverMessage = strtolower((string) ($e->getMessage() ?? ''));

        if (str_contains($driverMessage, 'duplicate')) {
            $message = 'Záznam se stejným unikátním údajem už existuje.';
        } elseif (str_contains($driverMessage, 'foreign key')) {
            $message = 'Odkazovaná kategorie nebo vazba neexistuje.';
        } elseif (str_contains($driverMessage, 'cannot be null')) {
            $message = 'Chybí povinný údaj.';
        }

        return new RuntimeException($message, 0, $e);
    }
}
