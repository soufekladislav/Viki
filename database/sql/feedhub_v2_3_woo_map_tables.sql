CREATE TABLE IF NOT EXISTS product_woo_map (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    woo_product_id BIGINT UNSIGNED NULL,
    sync_status VARCHAR(50) NOT NULL DEFAULT 'pending',
    last_synced_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_woo_map_store_product (store_id, product_id),
    KEY idx_product_woo_map_store_woo (store_id, woo_product_id),
    KEY idx_product_woo_map_status (sync_status),
    CONSTRAINT fk_product_woo_map_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_woo_map_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS category_woo_map (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    woo_category_id BIGINT UNSIGNED NULL,
    sync_status VARCHAR(50) NOT NULL DEFAULT 'pending',
    last_synced_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_category_woo_map_store_category (store_id, category_id),
    KEY idx_category_woo_map_store_woo (store_id, woo_category_id),
    KEY idx_category_woo_map_status (sync_status),
    CONSTRAINT fk_category_woo_map_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_woo_map_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
