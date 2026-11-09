<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\StoreRepository;
use App\Repositories\UserRepository;
use Throwable;

final class UserController
{
    public function __construct(
        private UserRepository $users,
        private StoreRepository $stores
    ) {}

    public function index(Request $request): Response
    {
        $auth = $request->attribute('auth');

        if (!(bool) ($auth['user']['is_superadmin'] ?? false)) {
            return Response::json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Pouze superadmin může zobrazit seznam uživatelů.',
            ], 403);
        }

        $items = $this->users->listUsersWithAssignments();

        $data = array_map(function (array $user): array {
            $user['roles'] = array_map(function (array $assignment): array {
                return [
                    'store_id' => $assignment['store_id'],
                    'store_name' => $assignment['store_name'],
                    'store_code' => $assignment['store_code'],
                    'role' => $this->detectRoleFromPermissions($assignment['permissions']),
                    'permissions' => $assignment['permissions'],
                ];
            }, $user['assignments']);

            return $user;
        }, $items);

        return Response::json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function create(Request $request): Response
    {
        $auth = $request->attribute('auth');

        if (!(bool) ($auth['user']['is_superadmin'] ?? false)) {
            return Response::json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Pouze superadmin může vytvářet uživatele.',
            ], 403);
        }

        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $fullName = trim((string) $request->input('full_name', ''));
        $password = (string) $request->input('password', '');
        $isActive = (int) ((bool) $request->input('is_active', true));
        $assignmentsInput = $request->input('assignments', []);

        if ($email === '' || $password === '') {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Pole email a password jsou povinná.',
            ], 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'E-mail nemá platný formát.',
            ], 422);
        }

        if (mb_strlen($password) < 8) {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Heslo musí mít alespoň 8 znaků.',
            ], 422);
        }

        if ($this->users->emailExists($email)) {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Uživatel s tímto e-mailem už existuje.',
            ], 422);
        }

        if (!is_array($assignmentsInput)) {
            return Response::json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'assignments musí být pole.',
            ], 422);
        }

        $assignments = [];

        foreach ($assignmentsInput as $index => $assignment) {
            if (!is_array($assignment)) {
                return Response::json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'message' => "assignments[{$index}] musí být objekt.",
                ], 422);
            }

            $storeId = (int) ($assignment['store_id'] ?? 0);
            $role = trim(mb_strtolower((string) ($assignment['role'] ?? 'viewer')));

            if ($storeId <= 0) {
                return Response::json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'message' => "assignments[{$index}].store_id je povinné.",
                ], 422);
            }

            if ($this->stores->findById($storeId) === null) {
                return Response::json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'message' => "Store #{$storeId} neexistuje.",
                ], 422);
            }

            $permissions = $this->permissionsForRole($role);

            if ($permissions === []) {
                return Response::json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'message' => "assignments[{$index}].role není podporovaná role.",
                ], 422);
            }

            $assignments[] = [
                'store_id' => $storeId,
                'role' => $role,
                'permissions' => $permissions,
            ];
        }

        try {
            $user = $this->users->createWithAssignments([
                'email' => $email,
                'full_name' => $fullName !== '' ? $fullName : null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'is_active' => $isActive,
            ], $assignments);
        } catch (Throwable $e) {
            return Response::json([
                'success' => false,
                'error' => 'Server error',
                'message' => 'Uživatele se nepodařilo vytvořit.',
            ], 500);
        }

        $user['roles'] = array_map(function (array $assignment): array {
            return [
                'store_id' => $assignment['store_id'],
                'store_name' => $assignment['store_name'],
                'store_code' => $assignment['store_code'],
                'role' => $this->detectRoleFromPermissions($assignment['permissions']),
                'permissions' => $assignment['permissions'],
            ];
        }, $user['assignments'] ?? []);

        return Response::json([
            'success' => true,
            'data' => $user,
        ], 201);
    }

    private function permissionsForRole(string $role): array
    {
        return match ($role) {
            'admin' => [
                'store.access',
                'store.context',
                'permissions.read',
                'catalog.read',
                'catalog.write',
                'products.read',
                'products.write',
                'categories.read',
                'categories.write',
            ],
            'manager' => [
                'store.access',
                'store.context',
                'catalog.read',
                'catalog.write',
                'products.read',
                'products.write',
                'categories.read',
                'categories.write',
            ],
            'editor' => [
                'store.access',
                'store.context',
                'catalog.read',
                'catalog.write',
                'products.read',
                'products.write',
                'categories.read',
                'categories.write',
            ],
            'viewer' => [
                'store.access',
                'store.context',
                'catalog.read',
                'products.read',
                'categories.read',
            ],
            default => [],
        };
    }

    private function detectRoleFromPermissions(array $permissions): string
    {
        $normalized = array_values(array_unique($permissions));
        sort($normalized);

        foreach (['admin', 'manager', 'editor', 'viewer'] as $role) {
            $candidate = $this->permissionsForRole($role);
            sort($candidate);

            if ($candidate === $normalized) {
                return $role;
            }
        }

        return 'custom';
    }
}