<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

final class UserRepository
{
    private string $usersTable;
    private string $permissionsTable;
    private string $storesTable;

    public function __construct(private PDO $pdo, array $config)
    {
        $this->usersTable = $config['tables']['users'];
        $this->permissionsTable = $config['tables']['user_store_permissions'];
        $this->storesTable = $config['tables']['stores'];
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, email, full_name, password_hash, is_active
             FROM {$this->usersTable}
             WHERE email = :email
             LIMIT 1"
        );

        $stmt->execute(['email' => $email]);

        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, email, full_name, is_active
             FROM {$this->usersTable}
             WHERE id = :id
             LIMIT 1"
        );

        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT id
                FROM {$this->usersTable}
                WHERE email = :email";

        $params = ['email' => $email];

        if ($excludeId !== null) {
            $sql .= " AND id <> :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    public function listUsersWithAssignments(): array
    {
        $sql = "SELECT
                    u.id,
                    u.email,
                    u.full_name,
                    u.is_active,
                    u.created_at,
                    u.updated_at,
                    usp.store_id,
                    s.name AS store_name,
                    s.code AS store_code,
                    GROUP_CONCAT(DISTINCT usp.permission_key ORDER BY usp.permission_key SEPARATOR ',') AS permissions
                FROM {$this->usersTable} u
                LEFT JOIN {$this->permissionsTable} usp ON usp.user_id = u.id
                LEFT JOIN {$this->storesTable} s ON s.id = usp.store_id
                GROUP BY u.id, u.email, u.full_name, u.is_active, u.created_at, u.updated_at, usp.store_id, s.name, s.code
                ORDER BY u.email ASC, s.name ASC";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];

        $users = [];

        foreach ($rows as $row) {
            $userId = (int) $row['id'];

            if (!isset($users[$userId])) {
                $users[$userId] = [
                    'id' => $userId,
                    'email' => (string) $row['email'],
                    'full_name' => $row['full_name'] !== null ? (string) $row['full_name'] : null,
                    'is_active' => (int) $row['is_active'],
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                    'assignments' => [],
                ];
            }

            if (!empty($row['store_id'])) {
                $users[$userId]['assignments'][] = [
                    'store_id' => (int) $row['store_id'],
                    'store_name' => (string) ($row['store_name'] ?? ''),
                    'store_code' => (string) ($row['store_code'] ?? ''),
                    'permissions' => !empty($row['permissions'])
                        ? explode(',', (string) $row['permissions'])
                        : [],
                ];
            }
        }

        return array_values($users);
    }

    public function createWithAssignments(array $userData, array $assignments): array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->usersTable} (email, password_hash, full_name, is_active)
                 VALUES (:email, :password_hash, :full_name, :is_active)"
            );

            $stmt->execute([
                'email' => $userData['email'],
                'password_hash' => $userData['password_hash'],
                'full_name' => $userData['full_name'],
                'is_active' => $userData['is_active'],
            ]);

            $userId = (int) $this->pdo->lastInsertId();

            if ($assignments !== []) {
                $permStmt = $this->pdo->prepare(
                    "INSERT INTO {$this->permissionsTable} (user_id, store_id, permission_key)
                     VALUES (:user_id, :store_id, :permission_key)"
                );

                foreach ($assignments as $assignment) {
                    foreach ($assignment['permissions'] as $permissionKey) {
                        $permStmt->execute([
                            'user_id' => $userId,
                            'store_id' => (int) $assignment['store_id'],
                            'permission_key' => $permissionKey,
                        ]);
                    }
                }
            }

            $this->pdo->commit();

            return $this->findUserWithAssignments($userId) ?? [];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function findUserWithAssignments(int $userId): ?array
    {
        $sql = "SELECT
                    u.id,
                    u.email,
                    u.full_name,
                    u.is_active,
                    u.created_at,
                    u.updated_at,
                    usp.store_id,
                    s.name AS store_name,
                    s.code AS store_code,
                    GROUP_CONCAT(DISTINCT usp.permission_key ORDER BY usp.permission_key SEPARATOR ',') AS permissions
                FROM {$this->usersTable} u
                LEFT JOIN {$this->permissionsTable} usp ON usp.user_id = u.id
                LEFT JOIN {$this->storesTable} s ON s.id = usp.store_id
                WHERE u.id = :user_id
                GROUP BY u.id, u.email, u.full_name, u.is_active, u.created_at, u.updated_at, usp.store_id, s.name, s.code
                ORDER BY s.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $rows = $stmt->fetchAll() ?: [];

        if ($rows === []) {
            return null;
        }

        $user = [
            'id' => (int) $rows[0]['id'],
            'email' => (string) $rows[0]['email'],
            'full_name' => $rows[0]['full_name'] !== null ? (string) $rows[0]['full_name'] : null,
            'is_active' => (int) $rows[0]['is_active'],
            'created_at' => $rows[0]['created_at'] ?? null,
            'updated_at' => $rows[0]['updated_at'] ?? null,
            'assignments' => [],
        ];

        foreach ($rows as $row) {
            if (!empty($row['store_id'])) {
                $user['assignments'][] = [
                    'store_id' => (int) $row['store_id'],
                    'store_name' => (string) ($row['store_name'] ?? ''),
                    'store_code' => (string) ($row['store_code'] ?? ''),
                    'permissions' => !empty($row['permissions'])
                        ? explode(',', (string) $row['permissions'])
                        : [],
                ];
            }
        }

        return $user;
    }
}