-- Viki 0.8.0 – referenční SQL.
-- Doporučený způsob aplikace je: php bin/migrate.php

CREATE TABLE IF NOT EXISTS schema_migrations (
  migration VARCHAR(190) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stores ADD COLUMN IF NOT EXISTS logo_url VARCHAR(500) NULL AFTER code;
ALTER TABLE products ADD COLUMN IF NOT EXISTS source_type VARCHAR(50) NULL AFTER store_id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS external_id BIGINT UNSIGNED NULL AFTER source_type;
ALTER TABLE products ADD COLUMN IF NOT EXISTS source_updated_at DATETIME NULL AFTER external_id;

CREATE TABLE IF NOT EXISTS product_variants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  external_id BIGINT UNSIGNED NULL,
  sku VARCHAR(190) NULL,
  name VARCHAR(255) NULL,
  regular_price DECIMAL(12,2) NULL,
  sale_price DECIMAL(12,2) NULL,
  stock INT NULL,
  stock_status VARCHAR(40) NOT NULL DEFAULT 'instock',
  attributes_json JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_variants_store_product (store_id, product_id),
  KEY idx_variants_store_external (store_id, external_id),
  KEY idx_variants_store_sku (store_id, sku),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  context_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_store_created (store_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
