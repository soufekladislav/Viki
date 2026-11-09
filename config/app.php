<?php
declare(strict_types=1);

$environment = (string) env('APP_ENV', 'production');
$key = (string) env('APP_KEY', 'change-me');

return [
    'name' => 'Viki',
    'version' => '0.8.2',
    'env' => $environment,
    'debug' => env('APP_DEBUG', 'false') === 'true',
    'url' => env('APP_URL', ''),
    'key' => $key,
    'app_key_secure' => strlen($key) >= 32 && $key !== 'replace-with-at-least-32-random-characters',
    'token_ttl_minutes' => (int) env('TOKEN_TTL_MINUTES', '720'),
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $value): string => rtrim(trim($value), '/'),
        explode(',', (string) env('ALLOWED_ORIGINS', env('APP_URL', '')))
    ))),

    'superadmin_emails' => array_values(array_filter(array_map(
        static fn (string $value): string => mb_strtolower(trim($value)),
        explode(',', (string) env('SUPERADMIN_EMAILS', ''))
    ))),

    'superadmin_permissions' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env(
            'SUPERADMIN_PERMISSIONS',
            'store.access,store.context,permissions.read,catalog.read,catalog.write,products.read,products.write,categories.read,categories.write'
        ))
    ))),
];
