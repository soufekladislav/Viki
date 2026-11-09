<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\StoreRepository;
use App\Repositories\UserRepository;

final class AuthService
{
    public function __construct(
        private UserRepository $userRepository,
        private StoreRepository $storeRepository,
        private array $config
    ) {}

    public function login(string $email, string $password, ?int $storeId = null): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (
            !$user
            || (int) $user['is_active'] !== 1
            || !password_verify($password, (string) $user['password_hash'])
        ) {
            return [
                'success' => false,
                'status' => 401,
                'error' => 'Unauthorized',
                'message' => 'Neplatný email nebo heslo.',
            ];
        }

        $isSuperadmin = $this->isSuperadminUser($user);

        $stores = $isSuperadmin
            ? $this->storeRepository->allActiveStores()
            : $this->storeRepository->storesForUser((int) $user['id']);

        if ($stores === []) {
            return [
                'success' => false,
                'status' => 403,
                'error' => 'Forbidden',
                'message' => 'Uživatel nemá přístup do žádného store.',
            ];
        }

        $currentStore = $stores[0];

        if ($storeId !== null) {
            foreach ($stores as $store) {
                if ((int) $store['id'] === $storeId) {
                    $currentStore = $store;
                    break;
                }
            }
        }

        $payload = $this->buildPayload($user, $currentStore, $isSuperadmin);

        return [
            'success' => true,
            'data' => [
                'token' => $this->encodeToken($payload),
                'expires_at' => $payload['expires_at'],
                'user' => $payload['user'],
                'current_store' => $payload['current_store'],
                'permissions' => $payload['permissions'],
                'stores' => $this->mapStoresForResponse($stores, $isSuperadmin),
            ],
        ];
    }

    public function validateToken(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        if (!hash_equals($this->sign($header . '.' . $payload), $signature)) {
            return null;
        }

        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
            return null;
        }

        $userId = (int) ($data['user']['id'] ?? 0);
        $storeId = (int) ($data['current_store']['id'] ?? 0);
        $user = $this->userRepository->findById($userId);
        if (!$user || (int) ($user['is_active'] ?? 0) !== 1 || $storeId <= 0) {
            return null;
        }

        $isSuperadmin = $this->isSuperadminUser($user);
        $store = $isSuperadmin
            ? $this->storeRepository->activeStoreById($storeId)
            : $this->storeRepository->storeForUser($userId, $storeId);
        if (!$store) {
            return null;
        }

        return [
            'user' => [
                'id' => $userId,
                'email' => (string) $user['email'],
                'full_name' => $user['full_name'] ?? null,
                'is_superadmin' => $isSuperadmin,
            ],
            'current_store' => [
                'id' => (int) $store['id'],
                'name' => (string) $store['name'],
                'code' => (string) $store['code'],
            ],
            'permissions' => $isSuperadmin
                ? $this->superadminPermissions()
                : array_values(array_unique($store['permissions'] ?? [])),
            'issued_at' => $data['iat'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ];
    }

    public function issueForStore(int $userId, int $storeId): ?array
    {
        $user = $this->userRepository->findById($userId);

        if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
            return null;
        }

        $isSuperadmin = $this->isSuperadminUser($user);

        $store = $isSuperadmin
            ? $this->storeRepository->activeStoreById($storeId)
            : $this->storeRepository->storeForUser($userId, $storeId);

        if (!$store) {
            return null;
        }

        $payload = $this->buildPayload($user, $store, $isSuperadmin);

        return [
            'token' => $this->encodeToken($payload),
            'payload' => $payload,
        ];
    }

    public function isSuperadminByUserId(int $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
            return false;
        }

        return $this->isSuperadminUser($user);
    }

    private function buildPayload(array $user, array $store, bool $isSuperadmin): array
    {
        $iat = time();
        $exp = $iat + ((int) $this->config['token_ttl_minutes'] * 60);

        $permissions = $isSuperadmin
            ? $this->superadminPermissions()
            : array_values(array_unique($store['permissions'] ?? []));

        return [
            'iss' => $this->config['url'] ?: 'feedhub',
            'iat' => $iat,
            'exp' => $exp,
            'expires_at' => date(DATE_ATOM, $exp),
            'user' => [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'full_name' => $user['full_name'] ?? null,
                'is_superadmin' => $isSuperadmin,
            ],
            'current_store' => [
                'id' => (int) $store['id'],
                'name' => $store['name'],
                'code' => $store['code'],
            ],
            'permissions' => $permissions,
        ];
    }

    private function mapStoresForResponse(array $stores, bool $isSuperadmin): array
    {
        return array_map(function (array $store) use ($isSuperadmin): array {
            return [
                'id' => (int) $store['id'],
                'name' => $store['name'],
                'code' => $store['code'],
                'is_active' => (int) $store['is_active'],
                'permissions' => $isSuperadmin
                    ? $this->superadminPermissions()
                    : array_values(array_unique($store['permissions'] ?? [])),
            ];
        }, $stores);
    }

    private function isSuperadminUser(array $user): bool
    {
        $email = mb_strtolower(trim((string) ($user['email'] ?? '')));

        if ($email === '') {
            return false;
        }

        return in_array($email, $this->superadminEmails(), true);
    }

    private function superadminEmails(): array
    {
        $emails = $this->config['superadmin_emails'] ?? [];
        return is_array($emails) ? $emails : [];
    }

    private function superadminPermissions(): array
    {
        $permissions = $this->config['superadmin_permissions'] ?? [];

        if (!is_array($permissions) || $permissions === []) {
            return [
                'store.access',
                'store.context',
                'permissions.read',
                'catalog.read',
                'catalog.write',
                'products.read',
                'products.write',
                'categories.read',
                'categories.write',
            ];
        }

        return array_values(array_unique(array_map(
            static fn (string $value): string => trim($value),
            $permissions
        )));
    }

    private function encodeToken(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $encodedHeader = $this->base64UrlEncode(
            json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $encodedPayload = $this->base64UrlEncode(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $encodedHeader . '.' . $encodedPayload . '.' . $this->sign($encodedHeader . '.' . $encodedPayload);
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $data, (string) $this->config['key'], true)
        );
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
