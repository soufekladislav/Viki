CREATE TABLE IF NOT EXISTS woo_product_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  woo_product_id BIGINT UNSIGNED NULL,
  woo_parent_id BIGINT UNSIGNED NULL,
  sync_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  last_synced_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_woo_product_map_store_product (store_id, product_id),
  KEY idx_woo_product_map_store_woo (store_id, woo_product_id),
  KEY idx_woo_product_map_status (sync_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS woo_category_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  woo_term_id BIGINT UNSIGNED NULL,
  woo_parent_term_id BIGINT UNSIGNED NULL,
  sync_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  last_synced_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_woo_category_map_store_category (store_id, category_id),
  KEY idx_woo_category_map_store_woo (store_id, woo_term_id),
  KEY idx_woo_category_map_status (sync_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
