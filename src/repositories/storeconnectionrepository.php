<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class StoreConnectionRepository
{
    private string $table;
    private string $encryptionKey;

    public function __construct(private PDO $pdo, array $config, array $appConfig = [])
    {
        $this->table = $config['tables']['store_connections'] ?? 'store_connections';
        $this->encryptionKey = hash('sha256', (string) ($appConfig['key'] ?? ''), true);
    }

    public function all(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM {$this->table} ORDER BY store_id ASC");
        $rows = $stmt->fetchAll() ?: [];
        return array_map(fn(array $row): array => $this->mapRow($row), $rows);
    }

    public function findByStoreId(int $storeId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE store_id = :store_id LIMIT 1");
        $stmt->execute(['store_id' => $storeId]);
        $row = $stmt->fetch();
        return $row ? $this->mapRow($row) : null;
    }

    public function upsert(int $storeId, array $data): array
    {
        $existing = $this->findByStoreId($storeId);
        $payload = [
            'store_id' => $storeId,
            'connection_type' => $data['connection_type'] ?? ($existing['connection_type'] ?? 'woocommerce_plugin'),
            'mode' => $data['mode'] ?? ($existing['mode'] ?? 'plugin_api'),
            'status' => $data['status'] ?? ($existing['status'] ?? 'draft'),
            'wp_base_url' => $data['wp_base_url'] ?? ($existing['wp_base_url'] ?? null),
            'plugin_token' => array_key_exists('plugin_token', $data)
                ? $this->encryptSecret($this->nullableString($data['plugin_token']))
                : $this->encryptSecret($existing['plugin_token'] ?? null),
            'plugin_status' => $data['plugin_status'] ?? ($existing['plugin_status'] ?? 'not_tested'),
            'remote_site_name' => $data['remote_site_name'] ?? ($existing['remote_site_name'] ?? null),
            'remote_home_url' => $data['remote_home_url'] ?? ($existing['remote_home_url'] ?? null),
            'remote_wp_version' => $data['remote_wp_version'] ?? ($existing['remote_wp_version'] ?? null),
            'remote_wc_version' => $data['remote_wc_version'] ?? ($existing['remote_wc_version'] ?? null),
            'remote_product_count' => isset($data['remote_product_count']) ? (int) $data['remote_product_count'] : (int) ($existing['remote_product_count'] ?? 0),
            'remote_category_count' => isset($data['remote_category_count']) ? (int) $data['remote_category_count'] : (int) ($existing['remote_category_count'] ?? 0),
            'last_discovery_at' => $data['last_discovery_at'] ?? ($existing['last_discovery_at'] ?? null),
            'last_import_at' => $data['last_import_at'] ?? ($existing['last_import_at'] ?? null),
            'last_error' => $data['last_error'] ?? ($existing['last_error'] ?? null),
        ];

        if ($existing) {
            $sql = "UPDATE {$this->table}
                    SET connection_type = :connection_type,
                        mode = :mode,
                        status = :status,
                        wp_base_url = :wp_base_url,
                        plugin_token = :plugin_token,
                        plugin_status = :plugin_status,
                        remote_site_name = :remote_site_name,
                        remote_home_url = :remote_home_url,
                        remote_wp_version = :remote_wp_version,
                        remote_wc_version = :remote_wc_version,
                        remote_product_count = :remote_product_count,
                        remote_category_count = :remote_category_count,
                        last_discovery_at = :last_discovery_at,
                        last_import_at = :last_import_at,
                        last_error = :last_error,
                        updated_at = NOW()
                    WHERE store_id = :store_id
                    LIMIT 1";
        } else {
            $sql = "INSERT INTO {$this->table} (
                        store_id, connection_type, mode, status, wp_base_url, plugin_token, plugin_status,
                        remote_site_name, remote_home_url, remote_wp_version, remote_wc_version,
                        remote_product_count, remote_category_count, last_discovery_at, last_import_at, last_error,
                        created_at, updated_at
                    ) VALUES (
                        :store_id, :connection_type, :mode, :status, :wp_base_url, :plugin_token, :plugin_status,
                        :remote_site_name, :remote_home_url, :remote_wp_version, :remote_wc_version,
                        :remote_product_count, :remote_category_count, :last_discovery_at, :last_import_at, :last_error,
                        NOW(), NOW()
                    )";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($payload);

        return $this->findByStoreId($storeId) ?? $payload;
    }

    private function mapRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'store_id' => (int) $row['store_id'],
            'connection_type' => (string) ($row['connection_type'] ?? 'woocommerce_plugin'),
            'mode' => (string) ($row['mode'] ?? 'plugin_api'),
            'status' => (string) ($row['status'] ?? 'draft'),
            'wp_base_url' => $row['wp_base_url'] !== null ? (string) $row['wp_base_url'] : null,
            'plugin_token' => $this->decryptSecret($row['plugin_token'] ?? null),
            'token_configured' => !empty($row['plugin_token']),
            'plugin_status' => (string) ($row['plugin_status'] ?? 'not_tested'),
            'remote_site_name' => $row['remote_site_name'] !== null ? (string) $row['remote_site_name'] : null,
            'remote_home_url' => $row['remote_home_url'] !== null ? (string) $row['remote_home_url'] : null,
            'remote_wp_version' => $row['remote_wp_version'] !== null ? (string) $row['remote_wp_version'] : null,
            'remote_wc_version' => $row['remote_wc_version'] !== null ? (string) $row['remote_wc_version'] : null,
            'remote_product_count' => (int) ($row['remote_product_count'] ?? 0),
            'remote_category_count' => (int) ($row['remote_category_count'] ?? 0),
            'last_discovery_at' => $row['last_discovery_at'] ?? null,
            'last_import_at' => $row['last_import_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function encryptSecret(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (str_starts_with($value, 'enc:v1:')) {
            return $value;
        }
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('Server nepodporuje bezpečné šifrování konektorových tokenů.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Konektorový token se nepodařilo zašifrovat.');
        }

        return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    private function decryptSecret(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null || !str_starts_with($value, 'enc:v1:')) {
            return $value;
        }

        $binary = base64_decode(substr($value, 7), true);
        if ($binary === false || strlen($binary) < 29 || !function_exists('openssl_decrypt')) {
            return null;
        }

        $iv = substr($binary, 0, 12);
        $tag = substr($binary, 12, 16);
        $ciphertext = substr($binary, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }
}
