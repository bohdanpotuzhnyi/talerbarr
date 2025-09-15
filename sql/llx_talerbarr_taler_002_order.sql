--
-- llx_talerbarr_orders_1.sql
-- #1 Batch for order/invoice/payment/wire linkage
--

CREATE TABLE IF NOT EXISTS llx_talerbarr_order_link (
    rowid                  INT AUTO_INCREMENT PRIMARY KEY,
    entity                 INT NOT NULL DEFAULT 1,

    -- === Taler identity / API ===
    taler_instance         VARCHAR(64)  NOT NULL,        -- which merchant instance
    taler_order_id         VARCHAR(128) NOT NULL,        -- unique per instance
    taler_session_id       VARCHAR(128) NULL,
    taler_pay_uri          VARCHAR(255) NULL,            -- taler://pay/… (from GET /orders/$id)
    taler_status_url       VARCHAR(255) NULL,            -- public status page (QR / trigger)
    taler_refund_deadline  DATETIME NULL,
    taler_pay_deadline     DATETIME NULL,

    -- Contract / amount snapshot (string kept for exact fidelity + parsed helpers)
    order_summary          VARCHAR(255) NULL,
    order_amount_str       VARCHAR(64)  NULL,            -- e.g. "EUR:12.34" (requested total)
    order_currency         VARCHAR(16)  NULL,
    order_value            BIGINT       NULL,            -- integer units
    order_fraction         INT          NULL,            -- 0..99_999_999 (1e-8)
    deposit_total_str      VARCHAR(64)  NULL,            -- what exchange deposited (excl. fees)

    -- === Dolibarr party / template ===
    fk_soc                 INT NULL,                     -- -> llx_societe.rowid (customer)
    order_ref_planned      VARCHAR(64) NULL,             -- optional precomputed ref before create

    -- === Step 1–3: Customer ORDER (Commande) ===
    fk_commande            INT NULL,                     -- -> llx_commande.rowid
    commande_ref_snap      VARCHAR(64)  NULL,
    commande_datec         DATETIME     NULL,            -- created date
    commande_validated_at  DATETIME     NULL,            -- step 3: validated
    intended_payment_code  VARCHAR(32)  NULL,            -- e.g. 'TALER' (c_paiement.code) for UX

    -- === Step 4–5: Customer INVOICE (Facture) ===
    fk_facture             INT NULL,                     -- -> llx_facture.rowid
    facture_ref_snap       VARCHAR(64)  NULL,
    facture_datef          DATETIME     NULL,            -- invoice date
    fk_cond_reglement      INT NULL,                     -- -> llx_c_payment_term.rowid
    facture_validated_at   DATETIME     NULL,

    -- === Step 6–7: PAYMENT & BANK ===
    fk_paiement            INT NULL,                     -- -> llx_paiement.rowid (customer payment)
    paiement_datep         DATETIME     NULL,            -- payment date
    fk_c_paiement          INT NULL,                     -- -> llx_c_paiement.rowid (payment mode)
    fk_bank                INT NULL,                     -- -> llx_bank.rowid (bank entry/line)
    fk_bank_account        INT NULL,                     -- -> llx_bank_account.rowid (to credit)

    -- === Wire settlement (exchange → merchant) ===
    taler_wired            TINYINT(1) NOT NULL DEFAULT 0,
    taler_wtid             VARCHAR(64)  NULL,            -- 32-byte WTID (base32 or hex as delivered)
    taler_exchange_url     VARCHAR(255) NULL,
    wire_execution_time    DATETIME     NULL,
    wire_details_json      TEXT NULL,                    -- full array from API for audits

    -- === State, timestamps & safety ===
    taler_state            SMALLINT NULL,                -- 0=unpaid,1=claimed,2=paid,3=wired,4=refunded,-1=canceled
    taler_claimed_at       DATETIME NULL,
    taler_paid_at          DATETIME NULL,
    taler_refunded_total   VARCHAR(64) NULL,             -- "EUR:1.23" (accumulated)
    taler_refund_pending   TINYINT(1) NOT NULL DEFAULT 0,

    last_status_check_at   DATETIME NULL,
    datec                  DATETIME NULL,
    tms                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- === Integrity & idempotency ===
    idempotency_key        VARCHAR(128) NULL,            -- e.g. "$instance|$order_id"
    UNIQUE KEY uk_instance_order (entity, taler_instance, taler_order_id),

    -- Pragmatic lookup indexes for backoffice
    KEY idx_commande     (fk_commande),
    KEY idx_facture      (fk_facture),
    KEY idx_paiement     (fk_paiement),
    KEY idx_bank         (fk_bank),
    KEY idx_bank_account (fk_bank_account),
    KEY idx_societe      (fk_soc),
    KEY idx_wtid         (taler_wtid),

    CONSTRAINT fk_tbo_soc      FOREIGN KEY (fk_soc)        REFERENCES llx_societe(rowid),
    CONSTRAINT fk_tbo_cmd      FOREIGN KEY (fk_commande)   REFERENCES llx_commande(rowid),
    CONSTRAINT fk_tbo_fac      FOREIGN KEY (fk_facture)    REFERENCES llx_facture(rowid),
    CONSTRAINT fk_tbo_pai      FOREIGN KEY (fk_paiement)   REFERENCES llx_paiement(rowid),
    CONSTRAINT fk_tbo_bank     FOREIGN KEY (fk_bank)       REFERENCES llx_bank(rowid),
    CONSTRAINT fk_tbo_bankacc  FOREIGN KEY (fk_bank_account) REFERENCES llx_bank_account(rowid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
