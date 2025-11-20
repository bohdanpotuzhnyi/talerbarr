--
-- llx_talerbarr_taler_004_sync_timing.sql
-- Add configuration flag to delay Taler->Dolibarr order sync until payment.
--

ALTER TABLE llx_talerbarr_talerconfig
    ADD COLUMN IF NOT EXISTS sync_on_paid INTEGER NOT NULL DEFAULT 0 AFTER syncdirection;
