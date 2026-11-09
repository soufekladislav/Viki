<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\CatalogRepository;
use RuntimeException;
use stdClass;

final class CatalogService
{
    public function __construct(private CatalogRepository $repo) {}

    public function products(int $storeId, array $filters): array
    {
        return $this->repo->products($storeId, $filters);
    }

    public function product(int $storeId, int $id): ?array
    {
        return $this->repo->findProduct($storeId, $id);
    }

    public function createProduct(int $storeId, array $data): array
    {
        $normalized = $this->normalizeProduct($data);
        $this->validateProduct($storeId, $normalized);

        if ($normalized['slug'] === '') {
            $normalized['slug'] = $this->slugify($normalized['name']);
        }

        return $this->repo->createProduct($storeId, $normalized);
    }

    public function updateProduct(int $storeId, int $id, array $data): ?array
    {
        $normalized = $this->normalizeProduct($data);
        $this->validateProduct($storeId, $normalized);

        if ($normalized['slug'] === '') {
            $normalized['slug'] = $this->slugify($normalized['name']);
        }

        return $this->repo->updateProduct($storeId, $id, $normalized);
    }

    public function deleteProduct(int $storeId, int $id): bool
    {
        return $this->repo->deleteProduct($storeId, $id);
    }

    public function bulkMoveProducts(int $storeId, array $ids, ?int $categoryId): int
    {
        if ($categoryId !== null && $this->repo->findCategory($storeId, $categoryId) === null) {
            throw new RuntimeException('Cílová kategorie neexistuje.');
        }

        return $this->repo->bulkMoveProducts($storeId, $ids, $categoryId);
    }

    public function bulkDeleteProducts(int $storeId, array $ids): int
    {
        return $this->repo->bulkDeleteProducts($storeId, $ids);
    }

    public function categories(int $storeId, array $filters): array
    {
        $active = ($filters['active'] !== null && $filters['active'] !== '') ? (int)$filters['active'] : null;
        $items = $this->repo->categories($storeId, $active);

        return [
            'items' => ((int)($filters['tree'] ?? 0) === 1)
                ? $this->buildTree($items)
                : $items,
        ];
    }

    public function category(int $storeId, int $id): ?array
    {
        return $this->repo->findCategory($storeId, $id);
    }

    public function createCategory(int $storeId, array $data): array
    {
        $normalized = $this->normalizeCategory($data);

        if ($normalized['slug'] === '') {
            $normalized['slug'] = $this->slugify($normalized['name']);
        }

        if ($normalized['parent_id'] !== null) {
            if ($this->repo->findCategory($storeId, $normalized['parent_id']) === null) {
                throw new RuntimeException('Nadřazená kategorie neexistuje.');
            }
        }

        if ($normalized['sort_order'] <= 0) {
            $normalized['sort_order'] = $this->repo->getNextCategorySortOrder($storeId, $normalized['parent_id']);
        }

        return $this->repo->createCategory($storeId, $normalized);
    }

    public function updateCategory(int $storeId, int $id, array $data): ?array
    {
        $current = $this->repo->findCategory($storeId, $id);
        if ($current === null) {
            return null;
        }

        $normalized = $this->normalizeCategory($data);

        if ($normalized['slug'] === '') {
            $normalized['slug'] = $this->slugify($normalized['name']);
        }

        if ($normalized['parent_id'] === $id) {
            throw new RuntimeException('Kategorie nemůže být sama sobě nadřazená.');
        }

        if ($normalized['parent_id'] !== null) {
            if ($this->repo->findCategory($storeId, $normalized['parent_id']) === null) {
                throw new RuntimeException('Nadřazená kategorie neexistuje.');
            }
            if ($this->repo->isCategoryDescendant($storeId, $normalized['parent_id'], $id)) {
                throw new RuntimeException('Nelze přesunout kategorii do její podkategorie.');
            }
        }

        if ($normalized['sort_order'] <= 0) {
            $normalized['sort_order'] = $this->repo->getNextCategorySortOrder($storeId, $normalized['parent_id']);
        }

        $updated = $this->repo->updateCategory($storeId, $id, $normalized);
        if ($updated === null) {
            return null;
        }

        $this->repo->resequenceCategorySiblings($storeId, $current['parent_id'] ?? null);
        $this->repo->resequenceCategorySiblings($storeId, $updated['parent_id'] ?? null);

        return $this->repo->findCategory($storeId, $id);
    }

    public function reorderCategory(int $storeId, array $data): array
    {
        $categoryId = (int)($data['id'] ?? 0);
        if ($categoryId <= 0) {
            throw new RuntimeException('Chybí ID kategorie.');
        }

        $category = $this->repo->findCategory($storeId, $categoryId);
        if ($category === null) {
            throw new RuntimeException('Kategorie nebyla nalezena.');
        }

        $parentId = $data['parent_id'];
        $parentId = ($parentId === null || $parentId === '' || (int)$parentId <= 0) ? null : (int)$parentId;
        $beforeId = $data['before_id'];
        $beforeId = ($beforeId === null || $beforeId === '' || (int)$beforeId <= 0) ? null : (int)$beforeId;

        if ($parentId === $categoryId) {
            throw new RuntimeException('Kategorie nemůže být sama sobě nadřazená.');
        }

        if ($parentId !== null) {
            $parent = $this->repo->findCategory($storeId, $parentId);
            if ($parent === null) {
                throw new RuntimeException('Cílová nadřazená kategorie neexistuje.');
            }
            if ($this->repo->isCategoryDescendant($storeId, $parentId, $categoryId)) {
                throw new RuntimeException('Nelze přesunout kategorii do její podkategorie.');
            }
        }

        if ($beforeId !== null) {
            $before = $this->repo->findCategory($storeId, $beforeId);
            if ($before === null) {
                throw new RuntimeException('Referenční kategorie nebyla nalezena.');
            }
            $beforeParentId = $before['parent_id'] ?? null;
            if ($beforeParentId !== $parentId) {
                throw new RuntimeException('Referenční kategorie není ve stejné úrovni stromu.');
            }
        }

        return $this->repo->moveCategory(
            $storeId,
            $categoryId,
            $parentId,
            $beforeId,
            isset($data['sort_order']) && $data['sort_order'] !== '' ? (int)$data['sort_order'] : null
        );
    }


    public function productWooMap(int $storeId, int $productId): ?array
    {
        return $this->repo->productWooMap($storeId, $productId);
    }

    public function categoryWooMap(int $storeId, int $categoryId): ?array
    {
        return $this->repo->categoryWooMap($storeId, $categoryId);
    }

    public function upsertProductWooMap(int $storeId, int $productId, array $data): array
    {
        if ($this->repo->findProduct($storeId, $productId) === null) {
            throw new RuntimeException('Produkt nebyl nalezen.');
        }

        return $this->repo->upsertProductWooMap($storeId, $productId, [
            'woo_product_id' => isset($data['woo_product_id']) && $data['woo_product_id'] !== '' ? (int)$data['woo_product_id'] : null,
            'sync_status' => trim((string)($data['sync_status'] ?? 'pending')) ?: 'pending',
            'last_synced_at' => $data['last_synced_at'] ?? null,
            'last_error' => $this->normalizeNullableText($data['last_error'] ?? null),
        ]);
    }

    public function upsertCategoryWooMap(int $storeId, int $categoryId, array $data): array
    {
        if ($this->repo->findCategory($storeId, $categoryId) === null) {
            throw new RuntimeException('Kategorie nebyla nalezena.');
        }

        return $this->repo->upsertCategoryWooMap($storeId, $categoryId, [
            'woo_category_id' => isset($data['woo_category_id']) && $data['woo_category_id'] !== '' ? (int)$data['woo_category_id'] : null,
            'sync_status' => trim((string)($data['sync_status'] ?? 'pending')) ?: 'pending',
            'last_synced_at' => $data['last_synced_at'] ?? null,
            'last_error' => $this->normalizeNullableText($data['last_error'] ?? null),
        ]);
    }

    public function syncSummary(int $storeId): array
    {
        return $this->repo->syncSummary($storeId);
    }

    public function deleteCategory(int $storeId, int $id): array
    {
        if ($this->repo->findCategory($storeId, $id) === null) {
            return ['success' => false, 'status' => 404, 'message' => 'Kategorie nebyla nalezena.'];
        }

        if ($this->repo->categoryHasChildren($storeId, $id)) {
            return ['success' => false, 'status' => 422, 'message' => 'Kategorie má podkategorie. Nejdříve je přesuň nebo smaž.'];
        }

        if ($this->repo->categoryHasProducts($storeId, $id)) {
            return ['success' => false, 'status' => 422, 'message' => 'Kategorie obsahuje produkty. Nejdříve je přesuň nebo smaž.'];
        }

        $deleted = $this->repo->deleteCategory($storeId, $id);
        return $deleted
            ? ['success' => true]
            : ['success' => false, 'status' => 500, 'message' => 'Smazání kategorie se nezdařilo.'];
    }

    private function normalizeProduct(array $data): array
    {
        $categoryIds = array_values(array_unique(array_map(
            'intval',
            array_filter((array)($data['categoryIds'] ?? $data['category_ids'] ?? []), fn($v) => $v !== null && $v !== '')
        )));

        $primaryCategoryId = $data['category_id'] ?? ($categoryIds[0] ?? null);

        return [
            'name' => trim((string)($data['name'] ?? '')),
            'slug' => trim((string)($data['slug'] ?? '')),
            'sku' => trim((string)($data['sku'] ?? '')),
            'type' => trim((string)($data['type'] ?? 'simple')),
            'status' => trim((string)($data['status'] ?? 'draft')),
            'short_description' => $this->sanitizeProductHtml((string)($data['shortDescription'] ?? $data['short_description'] ?? '')),
            'description' => $this->sanitizeProductHtml((string)($data['description'] ?? '')),
            'regular_price' => (string)($data['regularPrice'] ?? $data['regular_price'] ?? ''),
            'sale_price' => (string)($data['salePrice'] ?? $data['sale_price'] ?? ''),
            'stock' => (string)($data['stock'] ?? ''),
            'virtual' => !empty($data['virtual']),
            'downloadable' => !empty($data['downloadable']),
            'manage_stock' => array_key_exists('manage_stock', $data) ? (bool)$data['manage_stock'] : true,
            'stock_status' => trim((string)($data['stock_status'] ?? 'instock')),
            'supplier' => trim((string)($data['supplier'] ?? '')),
            'purchase_price' => (string)($data['purchasePrice'] ?? $data['purchase_price'] ?? ''),
            'weight' => (string)($data['weight'] ?? ''),
            'length' => (string)($data['length'] ?? ''),
            'width' => (string)($data['width'] ?? ''),
            'height' => (string)($data['height'] ?? ''),
            'category_ids' => $categoryIds,
            'category_id' => $primaryCategoryId ? (int)$primaryCategoryId : null,
            'image_url' => (string)($data['image'] ?? $data['image_url'] ?? ''),
            'gallery_images' => array_values((array)($data['galleryImages'] ?? $data['gallery_images'] ?? [])),
            'attributes' => $data['attributes'] ?? new stdClass(),
            'variants' => array_values((array)($data['variants'] ?? [])),
            'is_active' => (int)($data['is_active'] ?? 1),
            'source_type' => $this->normalizeNullableText($data['source_type'] ?? null),
            'external_id' => !empty($data['external_id']) ? (int) $data['external_id'] : null,
            'source_updated_at' => $this->normalizeNullableText($data['source_updated_at'] ?? null),
        ];
    }

    private function validateProduct(int $storeId, array $product): void
    {
        if ($product['name'] === '') {
            throw new RuntimeException('Název produktu je povinný.');
        }
        if (!in_array($product['type'], ['simple', 'variable'], true)) {
            throw new RuntimeException('Podporovaný typ produktu je simple nebo variable.');
        }
        if (!in_array($product['status'], ['draft', 'publish', 'pending', 'private'], true)) {
            throw new RuntimeException('Produkt má nepodporovaný stav.');
        }
        foreach (['regular_price', 'sale_price'] as $field) {
            $value = $product[$field];
            if ($value !== '' && (!is_numeric($value) || (float) $value < 0)) {
                throw new RuntimeException('Cena musí být nezáporné číslo.');
            }
        }
        if ($product['stock'] !== '' && !is_numeric($product['stock'])) {
            throw new RuntimeException('Sklad musí být celé číslo.');
        }
        $variantSkus = [];
        foreach ($product['variants'] as $variant) {
            if (!is_array($variant)) {
                throw new RuntimeException('Varianta má neplatný formát.');
            }
            foreach (['regular_price', 'price', 'sale_price'] as $field) {
                $value = $variant[$field] ?? '';
                if ($value !== '' && (!is_numeric($value) || (float) $value < 0)) {
                    throw new RuntimeException('Cena varianty musí být nezáporné číslo.');
                }
            }
            $stock = $variant['stock'] ?? '';
            if ($stock !== '' && filter_var($stock, FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException('Sklad varianty musí být celé číslo.');
            }
            $sku = mb_strtolower(trim((string) ($variant['sku'] ?? '')));
            if ($sku !== '' && in_array($sku, $variantSkus, true)) {
                throw new RuntimeException('SKU variant musí být v rámci produktu unikátní.');
            }
            if ($sku !== '') {
                $variantSkus[] = $sku;
            }
        }
        foreach ($product['category_ids'] as $categoryId) {
            if ($this->repo->findCategory($storeId, (int) $categoryId) === null) {
                throw new RuntimeException('Jedna z vybraných kategorií neexistuje.');
            }
        }
    }

    private function sanitizeProductHtml(string $html): string
    {
        $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><h4><a><span><table><thead><tbody><tr><th><td>');
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? '';
        $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? '';
        $html = preg_replace('/(href|src)\s*=\s*(["\'])\s*(?:javascript|data\s*:\s*text\/html)[^"\']*\2/iu', '$1="#"', $html) ?? '';
        return trim($html);
    }


    private function normalizeNullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function normalizeCategory(array $data): array
    {
        return [
            'name' => trim((string)($data['name'] ?? '')),
            'slug' => trim((string)($data['slug'] ?? '')),
            'parent_id' => ($data['parentId'] ?? $data['parent_id'] ?? null)
                ? (int)($data['parentId'] ?? $data['parent_id'])
                : null,
            'description' => (string)($data['description'] ?? ''),
            'image_url' => (string)($data['image'] ?? $data['image_url'] ?? ''),
            'sort_order' => (int)($data['sort_order'] ?? 0),
            'is_active' => (int)($data['is_active'] ?? 1),
        ];
    }

    private function buildTree(array $items, ?int $parentId = null): array
    {
        $branch = [];

        foreach ($items as $item) {
            if (($item['parent_id'] ?? null) === $parentId) {
                $children = $this->buildTree($items, (int)$item['id']);
                if ($children !== []) {
                    $item['children'] = $children;
                }
                $branch[] = $item;
            }
        }

        return $branch;
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $trans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $trans !== false ? $trans : $value;
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
        return trim($value, '-');
    }
}
