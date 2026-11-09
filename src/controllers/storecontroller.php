<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\StoreRepository;

final class StoreController
{
    public function __construct(private StoreRepository $stores) {}

    public function index(Request $request): Response
    {
        $auth = $request->attribute('auth');
        $userId = (int) ($auth['user']['id'] ?? 0);
        $isSuperadmin = (bool) ($auth['user']['is_superadmin'] ?? false);

        $data = $isSuperadmin
            ? $this->stores->allStores()
            : $this->stores->storesForUser($userId);

        return Response::json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function show(Request $request): Response
    {
        $auth = $request->attribute('auth');
        $userId = (int) ($auth['user']['id'] ?? 0);
        $isSuperadmin = (bool) ($auth['user']['is_superadmin'] ?? false);
        $storeId = (int) $request->attribute('id', 0);

        if ($storeId <= 0) {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Neplatné ID store.',
            ], 422);
        }

        $store = $isSuperadmin
            ? $this->stores->findById($storeId)
            : $this->stores->storeForUser($userId, $storeId);

        if (!$store) {
            return Response::json([
                'success' => false,
                'error' => 'Not found',
                'message' => 'Store nebyl nalezen.',
            ], 404);
        }

        return Response::json([
            'success' => true,
            'data' => $store,
        ]);
    }

    public function create(Request $request): Response
    {
        $auth = $request->attribute('auth');

        if (!(bool) ($auth['user']['is_superadmin'] ?? false)) {
            return Response::json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Pouze superadmin může zakládat e-shopy.',
            ], 403);
        }

        $name = trim((string) $request->input('name', ''));
        $code = $this->normalizeCode((string) $request->input('code', ''));
        $logoUrl = $this->normalizeNullableString($request->input('logo_url'));
        $isActive = (int) ((bool) $request->input('is_active', true));

        if ($name === '' || $code === '') {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Pole name a code jsou povinná.',
            ], 422);
        }

        if ($this->stores->existsByCode($code)) {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Store code už existuje.',
            ], 422);
        }

        $storeId = $this->stores->create([
            'name' => $name,
            'code' => $code,
            'logo_url' => $logoUrl,
            'is_active' => $isActive,
        ]);

        $store = $this->stores->findById($storeId);

        return Response::json([
            'success' => true,
            'data' => $store,
        ], 201);
    }

    public function update(Request $request): Response
    {
        $auth = $request->attribute('auth');

        if (!(bool) ($auth['user']['is_superadmin'] ?? false)) {
            return Response::json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Pouze superadmin může upravovat e-shopy.',
            ], 403);
        }

        $storeId = (int) $request->attribute('id', 0);

        if ($storeId <= 0) {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Neplatné ID store.',
            ], 422);
        }

        $existing = $this->stores->findById($storeId);

        if (!$existing) {
            return Response::json([
                'success' => false,
                'error' => 'Not found',
                'message' => 'Store nebyl nalezen.',
            ], 404);
        }

        $name = trim((string) $request->input('name', $existing['name']));
        $code = $this->normalizeCode((string) $request->input('code', $existing['code']));
        $logoUrl = array_key_exists('logo_url', $request->body)
            ? $this->normalizeNullableString($request->input('logo_url'))
            : ($existing['logo_url'] ?? null);
        $isActive = array_key_exists('is_active', $request->body)
            ? (int) ((bool) $request->input('is_active'))
            : (int) $existing['is_active'];

        if ($name === '' || $code === '') {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Pole name a code jsou povinná.',
            ], 422);
        }

        if ($this->stores->existsByCode($code, $storeId)) {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Store code už používá jiný e-shop.',
            ], 422);
        }

        $this->stores->update($storeId, [
            'name' => $name,
            'code' => $code,
            'logo_url' => $logoUrl,
            'is_active' => $isActive,
        ]);

        return Response::json([
            'success' => true,
            'data' => $this->stores->findById($storeId),
        ]);
    }

    private function normalizeCode(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9\-]+/u', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}