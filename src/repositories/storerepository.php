<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class StoreRepository
{
    private string $storesTable;
    private string $permissionsTable;

    public function __construct(private PDO $pdo, array $config)
    {
        $this->storesTable = $config['tables']['stores'];
        $this->permissionsTable = $config['tables']['user_store_permissions'];
    }

    public function storesForUser(int $userId): array
    {
        $sql = "SELECT s.id, s.name, s.code, s.logo_url, s.is_active,
                       GROUP_CONCAT(DISTINCT usp.permission_key ORDER BY usp.permission_key SEPARATOR ',') AS permissions
                FROM {$this->storesTable} s
                INNER JOIN {$this->permissionsTable} usp ON usp.store_id = s.id
                WHERE usp.user_id = :user_id
                GROUP BY s.id, s.name, s.code, s.logo_url, s.is_active
                ORDER BY s.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $rows = $stmt->fetchAll() ?: [];

        return array_map([$this, 'mapStoreRow'], $rows);
    }

    public function storeForUser(int $userId, int $storeId): ?array
    {
        $sql = "SELECT s.id, s.name, s.code, s.logo_url, s.is_active,
                       GROUP_CONCAT(DISTINCT usp.permission_key ORDER BY usp.permission_key SEPARATOR ',') AS permissions
                FROM {$this->storesTable} s
                INNER JOIN {$this->permissionsTable} usp ON usp.store_id = s.id
                WHERE usp.user_id = :user_id
                  AND s.id = :store_id
                GROUP BY s.id, s.name, s.code, s.logo_url, s.is_active
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'store_id' => $storeId,
        ]);

        $row = $stmt->fetch();

        return $row ? $this->mapStoreRow($row) : null;
    }

    public function allActiveStores(): array
    {
        $sql = "SELECT s.id, s.name, s.code, s.logo_url, s.is_active
                FROM {$this->storesTable} s
                WHERE s.is_active = 1
                ORDER BY s.name ASC";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['logo_url'] = $row['logo_url'] !== null ? (string) $row['logo_url'] : null;
            $row['is_active'] = (int) $row['is_active'];
            $row['permissions'] = [];
            return $row;
        }, $rows);
    }

    public function allStores(): array
    {
        $sql = "SELECT s.id, s.name, s.code, s.logo_url, s.is_active
                FROM {$this->storesTable} s
                ORDER BY s.name ASC";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['logo_url'] = $row['logo_url'] !== null ? (string) $row['logo_url'] : null;
            $row['is_active'] = (int) $row['is_active'];
            $row['permissions'] = [];
            return $row;
        }, $rows);
    }

    public function activeStoreById(int $storeId): ?array
    {
        $sql = "SELECT s.id, s.name, s.code, s.logo_url, s.is_active
                FROM {$this->storesTable} s
                WHERE s.id = :store_id
                  AND s.is_active = 1
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['store_id' => $storeId]);

        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['logo_url'] = $row['logo_url'] !== null ? (string) $row['logo_url'] : null;
        $row['is_active'] = (int) $row['is_active'];
        $row['permissions'] = [];

        return $row;
    }

    public function findById(int $storeId): ?array
    {
        $sql = "SELECT s.id, s.name, s.code, s.logo_url, s.is_active, s.created_at, s.updated_at
                FROM {$this->storesTable} s
                WHERE s.id = :store_id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['store_id' => $storeId]);

        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['logo_url'] = $row['logo_url'] !== null ? (string) $row['logo_url'] : null;
        $row['is_active'] = (int) $row['is_active'];

        return $row;
    }

    public function existsByCode(string $code, ?int $excludeId = null): bool
    {
        $sql = "SELECT id
                FROM {$this->storesTable}
                WHERE code = :code";

        $params = ['code' => $code];

        if ($excludeId !== null) {
            $sql .= " AND id <> :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO {$this->storesTable} (name, code, logo_url, is_active)
                VALUES (:name, :code, :logo_url, :is_active)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'name' => $data['name'],
            'code' => $data['code'],
            'logo_url' => $data['logo_url'],
            'is_active' => $data['is_active'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $storeId, array $data): bool
    {
        $sql = "UPDATE {$this->storesTable}
                SET name = :name,
                    code = :code,
                    logo_url = :logo_url,
                    is_active = :is_active
                WHERE id = :id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            'id' => $storeId,
            'name' => $data['name'],
            'code' => $data['code'],
            'logo_url' => $data['logo_url'],
            'is_active' => $data['is_active'],
        ]);
    }

    private function mapStoreRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['logo_url'] = $row['logo_url'] !== null ? (string) $row['logo_url'] : null;
        $row['is_active'] = (int) $row['is_active'];
        $row['permissions'] = $row['permissions']
            ? explode(',', (string) $row['permissions'])
            : [];

        return $row;
    }
}