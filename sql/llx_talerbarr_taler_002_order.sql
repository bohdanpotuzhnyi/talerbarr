--
-- llx_talerbarr_orders_1.sql
-- #1 Batch for order/invoice/payment/wire linkage
--

CREATE TABLE IF NOT EXISTS llx_talerbarr_order_link (
    rowid                  INT AUTO_INCREMENT PRIMARY KEY,
    entity                 INT NOT NULL DEFAULT 1,

    -- === Taler identity / API ===
    taler_instance         VARCHAR(64)  NOT NULL,
    taler_order_id         VARCHAR(128) NOT NULL,
    taler_session_id       VARCHAR(128) NULL,
    taler_pay_uri          VARCHAR(255) NULL,
    taler_status_url       VARCHAR(255) NULL,
    taler_refund_deadline  DATETIME NULL,
    taler_pay_deadline     DATETIME NULL,

    -- Contract / amount snapshot
    order_summary          VARCHAR(255) NULL,
    order_amount_str       VARCHAR(64)  NULL,
    order_currency         VARCHAR(16)  NULL,
    order_value            BIGINT       NULL,
    order_fraction         INT          NULL,
    deposit_total_str      VARCHAR(64)  NULL,

    -- === Dolibarr party / template ===
    fk_soc                 INT NULL,
    order_ref_planned      VARCHAR(64) NULL,

    -- === Step 1-3: ORDER (Commande) ===
    fk_commande            INT NULL,
    commande_ref_snap      VARCHAR(64)  NULL,
    commande_datec         DATETIME     NULL,
    commande_validated_at  DATETIME     NULL,
    intended_payment_code  VARCHAR(32)  NULL,

    -- === Step 4-5: INVOICE (Facture) ===
    fk_facture             INT NULL,
    facture_ref_snap       VARCHAR(64)  NULL,
    facture_datef          DATETIME     NULL,
    fk_cond_reglement      INT NULL,
    facture_validated_at   DATETIME     NULL,

    -- === Step 6-7: PAYMENT & BANK ===
    fk_paiement            INT NULL,
    paiement_datep         DATETIME     NULL,
    fk_c_paiement          INT NULL,
    fk_bank                INT NULL,
    fk_bank_account        INT NULL,                 -- clearing account
    fk_bank_account_dest   INT NULL,                 -- real settlement account

    -- === Wire settlement (exchange → merchant) ===
    taler_wired            TINYINT(1) NOT NULL DEFAULT 0,
    taler_wtid             VARCHAR(64)  NULL,
    taler_exchange_url     VARCHAR(255) NULL,
    wire_execution_time    DATETIME     NULL,
    wire_details_json      TEXT NULL,

    -- === State, timestamps & safety ===
    taler_state            SMALLINT NULL,            -- macro FSM
    merchant_status_raw    VARCHAR(64) NULL,         -- last status string verbatim
    taler_claimed_at       DATETIME NULL,
    taler_paid_at          DATETIME NULL,
    taler_refunded_total   VARCHAR(64) NULL,
    taler_refund_pending   TINYINT(1) NOT NULL DEFAULT 0,
    last_status_check_at   DATETIME NULL,

    idempotency_key        VARCHAR(128) NULL,
    datec                  DATETIME NULL,
    tms                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_instance_order (entity, taler_instance, taler_order_id),

    KEY idx_commande      (fk_commande),
    KEY idx_facture       (fk_facture),
    KEY idx_paiement      (fk_paiement),
    KEY idx_bank          (fk_bank),
    KEY idx_bank_account  (fk_bank_account),
    KEY idx_bank_account_dest (fk_bank_account_dest),
    KEY idx_societe       (fk_soc),
    KEY idx_wtid          (taler_wtid),

    CONSTRAINT fk_tbo_soc        FOREIGN KEY (fk_soc)               REFERENCES llx_societe(rowid),
    CONSTRAINT fk_tbo_cmd        FOREIGN KEY (fk_commande)          REFERENCES llx_commande(rowid),
    CONSTRAINT fk_tbo_fac        FOREIGN KEY (fk_facture)           REFERENCES llx_facture(rowid),
    CONSTRAINT fk_tbo_pai        FOREIGN KEY (fk_paiement)          REFERENCES llx_paiement(rowid),
    CONSTRAINT fk_tbo_bank       FOREIGN KEY (fk_bank)              REFERENCES llx_bank(rowid),
    CONSTRAINT fk_tbo_bankacc    FOREIGN KEY (fk_bank_account)      REFERENCES llx_bank_account(rowid),
    CONSTRAINT fk_tbo_bankaccdst FOREIGN KEY (fk_bank_account_dest) REFERENCES llx_bank_account(rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

