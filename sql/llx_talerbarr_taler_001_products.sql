--
-- llx_talerbarr_products_1.sql
-- #1 Batch of the creations, run when the user activates the plugin
--
CREATE TABLE IF NOT EXISTS llx_talerbarr_product_link (
    rowid                INT AUTO_INCREMENT PRIMARY KEY,
    entity               INT NOT NULL DEFAULT 1,

    -- Dolibarr side
    fk_product           INT NULL,                 -- -> llx_product.rowid
    product_ref_snap     VARCHAR(64) NULL,
    product_tms_snap     DATETIME NULL,

    -- Taler side identity
    taler_instance       VARCHAR(64) NOT NULL,     -- talerconfig key/id
    taler_product_id     VARCHAR(128) NOT NULL,
    taler_product_name   VARCHAR(128) NOT NULL,    -- Human-readable prod_name
    taler_description    VARCHAR(2048) NOT NULL,   -- || product description

    -- Price (keep both exact string and parsed numeric)
    taler_amount_str     VARCHAR(64) NULL,         -- e.g. "EUR:12.34"
    taler_currency       VARCHAR(16) NULL,
    taler_value          BIGINT NULL,              -- integer units
    taler_fraction       INT NULL,                 -- 0..99,999,999 (1e-8)
    price_is_ttc         TINYINT(1) NOT NULL DEFAULT 1,

    -- Units & stocks
    fk_unit              INT NULL,                 -- map to llx_c_units.rowid
    taler_total_stock    BIGINT NULL DEFAULT -1,   -- -1 means infinite
    taler_total_sold     BIGINT NULL,
    taler_total_lost     BIGINT NULL,

    -- Categories / Taxes / Misc (JSON as TEXT for Builder-compat)
    taler_categories_json  TEXT NULL,
    taler_taxes_json       TEXT NULL,
    taler_address_json     TEXT NULL,
    taler_image_hash       VARCHAR(64) NULL,
    taler_next_restock     DATETIME NULL,
    taler_minimum_age      INT NULL,

    -- Sync control (bool, matches your config)
    sync_enabled         TINYINT(1) NOT NULL DEFAULT 1,
    syncdirection_override TINYINT(1) NULL,        -- NULL = use global config; 1 pull; 0 push

    -- Last sync result
    lastsync_is_push     TINYINT(1) NULL,          -- 1=pull (Taler→Doli), 0=push (Doli→Taler)
    last_sync_status     VARCHAR(16) NULL,         -- ok | error | conflict
    last_sync_at         DATETIME NULL,
    last_error_code      VARCHAR(64) NULL,
    last_error_message   TEXT NULL,

    -- Change detection (hex strings; Builder doesn’t like VARBINARY)
    checksum_d_hex       CHAR(64) NULL,            -- hex(sha256 of selected Doli fields)
    checksum_t_hex       CHAR(64) NULL,            -- hex(sha256 of normalized Taler payload)

    datec                DATETIME NULL,
    tms                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_instance_pid (entity, taler_instance, taler_product_id),
    UNIQUE KEY uk_product      (entity, fk_product),
    KEY idx_enabled_dir (sync_enabled, syncdirection_override),
    CONSTRAINT fk_link_product FOREIGN KEY (fk_product) REFERENCES llx_product(rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS llx_talerbarr_category_map (
  rowid              INT AUTO_INCREMENT PRIMARY KEY,
  entity             INT NOT NULL DEFAULT 1,
  taler_instance     VARCHAR(64) NOT NULL,
  taler_category_id  INT NOT NULL,
  taler_category_name VARCHAR(255) NULL,
  fk_categorie       INT NOT NULL,                 -- -> llx_categorie.rowid (type=product)
  note               VARCHAR(255) NULL,

  datec              DATETIME NULL,
  tms                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_cat (entity, taler_instance, taler_category_id),
  UNIQUE KEY uk_cat_dolli (entity, fk_categorie),
  CONSTRAINT fk_cat_categorie FOREIGN KEY (fk_categorie) REFERENCES llx_categorie(rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS llx_talerbarr_error_log (
    rowid            INT AUTO_INCREMENT PRIMARY KEY,
    entity           INT NOT NULL DEFAULT 1,

    -- What failed
    context          VARCHAR(32) NOT NULL,           -- product | category | order | tax | image | auth | other
    operation        VARCHAR(64) NULL,               -- create | update | delete | relink | sync | fetch ...
    direction_is_push TINYINT(1) NULL,               -- 1 push Doli→Taler, 0 pull Taler→Doli, NULL unknown

    -- Identities (nullable; fill what you have)
    fk_product_link  INT NULL,                       -- -> llx_talerbar_product_link.rowid
    fk_product       INT NULL,                       -- -> llx_product.rowid
    taler_instance   VARCHAR(64) NULL,
    taler_product_id VARCHAR(128) NULL,
    external_ref     VARCHAR(128) NULL,              -- e.g., order id if context='order'

    http_status      INT NULL,
    error_code       VARCHAR(64) NULL,
    error_message    TEXT NOT NULL,
    payload_json     TEXT NULL,                      -- request/response snippet (TEXT for Builder-compat)

    datec            DATETIME NULL,
    tms              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_ctx_date (context, datec),
    CONSTRAINT fk_err_link FOREIGN KEY (fk_product_link) REFERENCES llx_talerbarr_product_link(rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS llx_talerbarr_tax_map (
  rowid            INT AUTO_INCREMENT PRIMARY KEY,
  entity           INT NOT NULL DEFAULT 1,
  taler_instance   VARCHAR(64) NOT NULL,
  taler_tax_name   VARCHAR(128) NOT NULL,          -- e.g. "VAT 7%"
  taler_tax_amount_hint VARCHAR(64) NULL,          -- e.g. "EUR:0.70" (optional)
  vat_rate         DECIMAL(6,3) NULL,              -- convenience
  fk_c_tva         INT NULL,                        -- -> Dolibarr VAT dictionary id

  datec            DATETIME NULL,
  tms              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tax (entity, taler_instance, taler_tax_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
