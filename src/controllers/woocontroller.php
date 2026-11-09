<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\StoreRepository;
use App\Services\WooConnectorService;
use App\Services\WooSyncService;

final class WooController
{
    public function __construct(
        private WooConnectorService $woo,
        private WooSyncService $sync,
        private StoreRepository $stores
    ) {}

    public function index(Request $request): Response
    {
        $mapped = [];
        foreach ($this->woo->allConnections() as $item) {
            $storeId = (int) ($item['store_id'] ?? 0);
            if ($storeId > 0 && $this->canAccessStore($request, $storeId)) {
                $mapped[(string) $storeId] = $this->woo->publicConnection($item);
            }
        }

        return Response::json([
            'success' => true,
            'data' => $mapped,
        ]);
    }

    public function show(Request $request): Response
    {
        $storeId = (int) $request->attribute('id', 0);
        if ($storeId <= 0) {
            return Response::json(['success' => false, 'message' => 'Neplatné ID e-shopu.'], 422);
        }
        if (!$this->canAccessStore($request, $storeId)) {
            return Response::json(['success' => false, 'message' => 'K tomuto e-shopu nemáte přístup.'], 403);
        }

        return Response::json([
            'success' => true,
            'data' => $this->woo->publicConnection($this->woo->connection($storeId)),
        ]);
    }

    public function save(Request $request): Response
    {
        if (!$this->isSuperadmin($request)) {
            return Response::json(['success' => false, 'message' => 'Pouze superadmin může měnit připojení e-shopu.'], 403);
        }

        $storeId = (int) $request->attribute('id', 0);
        if ($storeId <= 0 || !$this->stores->findById($storeId)) {
            return Response::json(['success' => false, 'message' => 'E-shop nebyl nalezen.'], 404);
        }

        try {
            $connection = $this->woo->saveConnection($storeId, $request->body);
            return Response::json(['success' => true, 'data' => $this->woo->publicConnection($connection)]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function discover(Request $request): Response
    {
        if (!$this->isSuperadmin($request)) {
            return Response::json(['success' => false, 'message' => 'Pouze superadmin může ověřovat připojení.'], 403);
        }

        $storeId = (int) $request->attribute('id', 0);
        if ($storeId <= 0 || !$this->stores->findById($storeId)) {
            return Response::json(['success' => false, 'message' => 'E-shop nebyl nalezen.'], 404);
        }

        try {
            $result = $this->woo->discover($storeId);
            $result['connection'] = $this->woo->publicConnection($result['connection'] ?? null);
            return Response::json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function firstImport(Request $request): Response
    {
        if (!$this->isSuperadmin($request)) {
            return Response::json(['success' => false, 'message' => 'Pouze superadmin může spouštět první import.'], 403);
        }

        $storeId = (int) $request->attribute('id', 0);
        if ($storeId <= 0 || !$this->stores->findById($storeId)) {
            return Response::json(['success' => false, 'message' => 'E-shop nebyl nalezen.'], 404);
        }

        try {
            $result = $this->woo->firstImport($storeId);
            $result['connection'] = $this->woo->publicConnection($result['connection'] ?? null);
            return Response::json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function backups(Request $request): Response
    {
        $storeId = (int) $request->attribute('id', 0);
        if ($storeId <= 0 || !$this->stores->findById($storeId)) {
            return Response::json(['success' => false, 'message' => 'E-shop nebyl nalezen.'], 404);
        }
        if (!$this->canAccessStore($request, $storeId)) {
            return Response::json(['success' => false, 'message' => 'K tomuto e-shopu nemáte přístup.'], 403);
        }

        return Response::json([
            'success' => true,
            'data' => $this->woo->listBackups($storeId),
        ]);
    }

    public function createBackup(Request $request): Response
    {
        if (!$this->isSuperadmin($request)) {
            return Response::json(['success' => false, 'message' => 'Pouze superadmin může vytvářet zálohy.'], 403);
        }

        $storeId = (int) $request->attribute('id', 0);
        if ($storeId <= 0 || !$this->stores->findById($storeId)) {
            return Response::json(['success' => false, 'message' => 'E-shop nebyl nalezen.'], 404);
        }

        try {
            $type = (string) ($request->body['type'] ?? 'manual');
            $note = (string) ($request->body['note'] ?? 'Ruční záloha z administrace');
            return Response::json([
                'success' => true,
                'data' => $this->woo->createBackup($storeId, $type, $note),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function restoreBackup(Request $request): Response
    {
        if (!$this->isSuperadmin($request)) {
            return Response::json(['success' => false, 'message' => 'Pouze superadmin může obnovovat zálohy.'], 403);
        }

        $storeId = (int) $request->attribute('id', 0);
        if ($storeId <= 0 || !$this->stores->findById($storeId)) {
            return Response::json(['success' => false, 'message' => 'E-shop nebyl nalezen.'], 404);
        }

        $backupId = (string) ($request->body['backup_id'] ?? '');
        if ($backupId === '') {
            return Response::json(['success' => false, 'message' => 'Chybí ID zálohy.'], 422);
        }

        try {
            $result = $this->woo->restoreBackup($storeId, $backupId);
            $result['connection'] = $this->woo->publicConnection($result['connection'] ?? null);
            return Response::json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function settings(Request $request): Response
    {
        $storeId = (int) $request->attribute('id', 0);
        if ($storeId <= 0 || !$this->stores->findById($storeId)) {
            return Response::json(['success' => false, 'message' => 'E-shop nebyl nalezen.'], 404);
        }
        if (!$this->canAccessStore($request, $storeId)) {
            return Response::json(['success' => false, 'message' => 'K tomuto e-shopu nemáte přístup.'], 403);
        }

        return Response::json([
            'success' => true,
            'data' => $this->woo->storeSettings($storeId),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        if (!$this->isSuperadmin($request)) {
            return Response::json(['success' => false, 'message' => 'Pouze superadmin může měnit nastavení e-shopu.'], 403);
        }

        $storeId = (int) $request->attribute('id', 0);
        if ($storeId <= 0 || !$this->stores->findById($storeId)) {
            return Response::json(['success' => false, 'message' => 'E-shop nebyl nalezen.'], 404);
        }

        try {
            return Response::json([
                'success' => true,
                'data' => $this->woo->saveStoreSettings($storeId, $request->body),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function syncSummary(Request $request): Response
    {
        $storeId = (int) $request->attribute('auth')['current_store']['id'];
        return Response::json([
            'success' => true,
            'data' => $this->sync->syncSummary($storeId),
        ]);
    }

    public function saveProductMap(Request $request): Response
    {
        $storeId = (int) $request->attribute('auth')['current_store']['id'];
        $productId = (int) $request->attribute('id', 0);

        try {
            return Response::json([
                'success' => true,
                'data' => $this->sync->setProductMap($storeId, $productId, $request->body),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function saveCategoryMap(Request $request): Response
    {
        $storeId = (int) $request->attribute('auth')['current_store']['id'];
        $categoryId = (int) $request->attribute('id', 0);

        try {
            return Response::json([
                'success' => true,
                'data' => $this->sync->setCategoryMap($storeId, $categoryId, $request->body),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function productPayload(Request $request): Response
    {
        $storeId = (int) $request->attribute('auth')['current_store']['id'];
        $productId = (int) $request->attribute('id', 0);

        try {
            return Response::json([
                'success' => true,
                'data' => $this->sync->productPayload($storeId, $productId),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function syncProduct(Request $request): Response
    {
        $storeId = (int) $request->attribute('auth')['current_store']['id'];
        $productId = (int) $request->attribute('id', 0);

        try {
            return Response::json([
                'success' => true,
                'data' => $this->sync->syncProduct($storeId, $productId),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function isSuperadmin(Request $request): bool
    {
        $auth = (array) $request->attribute('auth', []);
        return (bool) (($auth['user']['is_superadmin'] ?? false));
    }

    private function canAccessStore(Request $request, int $storeId): bool
    {
        $auth = (array) $request->attribute('auth', []);
        if ((bool) ($auth['user']['is_superadmin'] ?? false)) {
            return $this->stores->findById($storeId) !== null;
        }
        $userId = (int) ($auth['user']['id'] ?? 0);
        return $userId > 0 && $this->stores->storeForUser($userId, $storeId) !== null;
    }
}
