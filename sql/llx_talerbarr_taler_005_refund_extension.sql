--
-- llx_talerbarr_taler_005_refund_extension.sql
-- Extend order link refund snapshot with fields from Merchant order status/refund details.
--

ALTER TABLE llx_talerbarr_order_link
    ADD COLUMN IF NOT EXISTS taler_refund_taken_total VARCHAR(64) NULL AFTER taler_refunded_total;

ALTER TABLE llx_talerbarr_order_link
    ADD COLUMN IF NOT EXISTS taler_refund_last_reason VARCHAR(255) NULL AFTER taler_refund_pending;

ALTER TABLE llx_talerbarr_order_link
    ADD COLUMN IF NOT EXISTS taler_refund_last_amount VARCHAR(64) NULL AFTER taler_refund_last_reason;

ALTER TABLE llx_talerbarr_order_link
    ADD COLUMN IF NOT EXISTS taler_refund_last_at DATETIME NULL AFTER taler_refund_last_amount;

ALTER TABLE llx_talerbarr_order_link
    ADD COLUMN IF NOT EXISTS taler_refund_details_json TEXT NULL AFTER taler_refund_last_at;
