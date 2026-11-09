<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\CatalogService;
use RuntimeException;
use Throwable;

final class CatalogController
{
    public function __construct(private CatalogService $service) {}

    public function products(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        return Response::json([
            'success' => true,
            'data' => $this->service->products($storeId, [
                'page' => (int)$request->input('page', 1),
                'limit' => (int)$request->input('limit', 50),
                'q' => (string)$request->input('q', ''),
                'category_id' => (int)$request->input('category_id', 0),
                'active' => $request->input('active'),
                'type' => (string)$request->input('type', ''),
                'status' => (string)$request->input('status', ''),
                'woo_sync_status' => (string)$request->input('woo_sync_status', ''),
                'sort' => (string)$request->input('sort', 'updated_at'),
                'direction' => (string)$request->input('direction', 'desc'),
            ]),
        ]);
    }

    public function product(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];
        $item = $this->service->product($storeId, (int)$request->attribute('id'));

        return $item
            ? Response::json(['success' => true, 'data' => $item])
            : Response::json(['success' => false, 'error' => 'Not found'], 404);
    }

    public function createProduct(Request $request): Response
    {
        if (trim((string)$request->input('name', '')) === '') {
            return Response::json(['success' => false, 'message' => 'Název produktu je povinný.'], 422);
        }

        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        try {
            $item = $this->service->createProduct($storeId, $request->all());
            return Response::json(['success' => true, 'data' => $item], 201);
        } catch (RuntimeException $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Uložení produktu se nezdařilo.'], 500);
        }
    }

    public function updateProduct(Request $request): Response
    {
        if (trim((string)$request->input('name', '')) === '') {
            return Response::json(['success' => false, 'message' => 'Název produktu je povinný.'], 422);
        }

        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        try {
            $item = $this->service->updateProduct($storeId, (int)$request->attribute('id'), $request->all());

            return $item
                ? Response::json(['success' => true, 'data' => $item])
                : Response::json(['success' => false, 'error' => 'Not found'], 404);
        } catch (RuntimeException $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Aktualizace produktu se nezdařila.'], 500);
        }
    }

    public function deleteProduct(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];
        $this->service->deleteProduct($storeId, (int)$request->attribute('id'));

        return Response::json(['success' => true]);
    }

    public function bulkMove(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        $count = $this->service->bulkMoveProducts(
            $storeId,
            array_map('intval', (array)$request->input('ids', [])),
            $request->input('category_id') ? (int)$request->input('category_id') : null
        );

        return Response::json(['success' => true, 'data' => ['affected' => $count]]);
    }

    public function bulkDelete(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        $count = $this->service->bulkDeleteProducts(
            $storeId,
            array_map('intval', (array)$request->input('ids', []))
        );

        return Response::json(['success' => true, 'data' => ['affected' => $count]]);
    }

    public function categories(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        return Response::json([
            'success' => true,
            'data' => $this->service->categories($storeId, [
                'active' => $request->input('active'),
                'tree' => (int)$request->input('tree', 0),
            ]),
        ]);
    }

    public function category(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];
        $item = $this->service->category($storeId, (int)$request->attribute('id'));

        return $item
            ? Response::json(['success' => true, 'data' => $item])
            : Response::json(['success' => false, 'error' => 'Not found'], 404);
    }

    public function createCategory(Request $request): Response
    {
        if (trim((string)$request->input('name', '')) === '') {
            return Response::json(['success' => false, 'message' => 'Název kategorie je povinný.'], 422);
        }

        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        try {
            $item = $this->service->createCategory($storeId, $request->all());
            return Response::json(['success' => true, 'data' => $item], 201);
        } catch (RuntimeException $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Uložení kategorie se nezdařilo.'], 500);
        }
    }

    public function updateCategory(Request $request): Response
    {
        if (trim((string)$request->input('name', '')) === '') {
            return Response::json(['success' => false, 'message' => 'Název kategorie je povinný.'], 422);
        }

        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        try {
            $item = $this->service->updateCategory($storeId, (int)$request->attribute('id'), $request->all());

            return $item
                ? Response::json(['success' => true, 'data' => $item])
                : Response::json(['success' => false, 'error' => 'Not found'], 404);
        } catch (RuntimeException $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Aktualizace kategorie se nezdařila.'], 500);
        }
    }

    public function reorderCategory(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        try {
            $item = $this->service->reorderCategory($storeId, [
                'id' => (int)$request->input('id', 0),
                'parent_id' => $request->input('parent_id'),
                'before_id' => $request->input('before_id'),
                'sort_order' => $request->input('sort_order'),
            ]);

            return Response::json(['success' => true, 'data' => $item]);
        } catch (RuntimeException $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Přesun kategorie se nezdařil.'], 500);
        }
    }


    public function syncSummary(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        return Response::json([
            'success' => true,
            'data' => $this->service->syncSummary($storeId),
        ]);
    }

    public function upsertProductWooMap(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        try {
            $item = $this->service->upsertProductWooMap($storeId, (int)$request->attribute('id'), $request->all());
            return Response::json(['success' => true, 'data' => $item]);
        } catch (RuntimeException $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Uložení Woo mapy produktu se nezdařilo.'], 500);
        }
    }

    public function upsertCategoryWooMap(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];

        try {
            $item = $this->service->upsertCategoryWooMap($storeId, (int)$request->attribute('id'), $request->all());
            return Response::json(['success' => true, 'data' => $item]);
        } catch (RuntimeException $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Uložení Woo mapy kategorie se nezdařilo.'], 500);
        }
    }

    public function deleteCategory(Request $request): Response
    {
        $storeId = (int)$request->attribute('auth')['current_store']['id'];
        $result = $this->service->deleteCategory($storeId, (int)$request->attribute('id'));

        return ($result['success'] ?? false)
            ? Response::json(['success' => true])
            : Response::json(['success' => false, 'message' => $result['message'] ?? 'Delete failed'], (int)($result['status'] ?? 422));
    }
}
