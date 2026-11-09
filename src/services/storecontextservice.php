<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\StoreRepository;

final class StoreContextService
{
    public function __construct(
        private StoreRepository $storeRepository,
        private AuthService $authService
    ) {}

    public function storesForUser(int $userId): array
    {
        if ($this->authService->isSuperadminByUserId($userId)) {
            return $this->storeRepository->allActiveStores();
        }

        return $this->storeRepository->storesForUser($userId);
    }

    public function switchStore(int $userId, int $storeId): array
    {
        $result = $this->authService->issueForStore($userId, $storeId);

        if (!$result) {
            return [
                'success' => false,
                'status' => 403,
                'error' => 'Forbidden',
                'message' => 'Store není dostupný pro tohoto uživatele.',
            ];
        }

        return [
            'success' => true,
            'data' => [
                'token' => $result['token'],
                'expires_at' => $result['payload']['expires_at'],
                'current_store' => $result['payload']['current_store'],
                'permissions' => $result['payload']['permissions'],
            ],
        ];
    }
}