<?php
declare(strict_types=1);

return [
    'connection' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_NAME', ''),
        'username' => env('DB_USER', ''),
        'password' => env('DB_PASS', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
    ],
    'tables' => [
        'users' => 'users',
        'stores' => 'stores',
        'user_store_permissions' => 'user_store_permissions',
        'products' => 'products',
        'categories' => 'categories',
        'product_variants' => 'product_variants',
        'store_connections' => 'store_connections',
        'woo_product_map' => 'woo_product_map',
        'woo_category_map' => 'woo_category_map',
        'schema_migrations' => 'schema_migrations',
    ],
];
