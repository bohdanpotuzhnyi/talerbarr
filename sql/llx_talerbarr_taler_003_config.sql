--
-- llx_talerbarr_taler_003_config.sql
-- Upgrade Taler configuration table with multi-entity support,
-- settlement account linkage and default customer mapping.
--

ALTER TABLE llx_talerbarr_talerconfig
    ADD COLUMN entity INT NOT NULL DEFAULT 1 AFTER rowid;

ALTER TABLE llx_talerbarr_talerconfig
    ADD COLUMN fk_bank_account INT NULL AFTER syncdirection;

ALTER TABLE llx_talerbarr_talerconfig
    ADD COLUMN fk_default_customer INT NULL AFTER fk_bank_account;

ALTER TABLE llx_talerbarr_talerconfig
    ADD KEY idx_talerbarr_talerconfig_entity (entity);

ALTER TABLE llx_talerbarr_talerconfig
    ADD KEY idx_bank_account (fk_bank_account);

ALTER TABLE llx_talerbarr_talerconfig
    ADD KEY idx_talerbarr_talerconfig_default_customer (fk_default_customer);

UPDATE llx_talerbarr_talerconfig
    SET entity = 1
    WHERE entity IS NULL OR entity = 0;

ALTER TABLE llx_talerbarr_talerconfig
    ADD CONSTRAINT fk_tbc_bankacc
        FOREIGN KEY (fk_bank_account) REFERENCES llx_bank_account(rowid);

ALTER TABLE llx_talerbarr_talerconfig
    ADD CONSTRAINT fk_talerconfig_default_customer
        FOREIGN KEY (fk_default_customer) REFERENCES llx_societe(rowid);

ALTER TABLE llx_talerbarr_talerconfig
    ADD COLUMN taler_currency_alias VARCHAR(16) NULL AFTER talertoken;

UPDATE llx_talerbarr_talerconfig
SET taler_currency_alias = UPPER(taler_currency_alias)
WHERE taler_currency_alias IS NOT NULL
  AND taler_currency_alias <> UPPER(taler_currency_alias);
