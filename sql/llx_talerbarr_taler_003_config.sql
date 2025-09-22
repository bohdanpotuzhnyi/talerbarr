--
-- llx_talerbarr_taler_003_config.sql
-- Update for the config table, to support wire transfers
--

ALTER TABLE llx_talerbarr_talerconfig
    ADD COLUMN fk_bank_account INT NULL AFTER syncdirection,
    ADD KEY    idx_bank_account (fk_bank_account),
    ADD CONSTRAINT fk_tbc_bankacc
        FOREIGN KEY (fk_bank_account) REFERENCES llx_bank_account(rowid);
