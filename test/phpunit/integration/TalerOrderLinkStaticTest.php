<?php
declare(strict_types=1);

/* --------------------------------------------------------------------------
 * Dolibarr bootstrap & shared test helpers
 * ------------------------------------------------------------------------ */

global $conf, $user, $db, $langs;

require_once dirname(__FILE__, 7) . '/htdocs/master.inc.php';
require_once dirname(__FILE__, 7) . '/test/phpunit/CommonClassTest.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/talerbarr/class/talerorderlink.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/talerbarr/class/talerconfig.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/talerbarr/core/triggers/interface_99_modTalerBarr_TalerBarrTriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

/**
 * PHPUnit coverage for the static helper methods of TalerOrderLink.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class TalerOrderLinkStaticTest extends CommonClassTest
{
	private array $constBackup = [];

	/**
	 * Store current Dolibarr constants so the test can restore them afterwards.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->constBackup = [];
		$this->backupConst('TALERBARR_DEFAULT_SOCID');
		$this->backupConst('TALERBARR_PAYMENT_MODE_ID');
		$this->backupConst('TALERBARR_CLEARING_BANK_ACCOUNT');
		$this->backupConst('TALERBARR_CLEARING_ACCOUNT_ID');
		$this->backupConst('TALERBARR_FINAL_BANK_ACCOUNT');
	}

	/**
	 * Restore the Dolibarr constants to their previous state.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $db, $conf;
		foreach ($this->constBackup as $name => $value) {
			if ($value === null) {
				dolibarr_del_const($db, $name, $conf->entity);
				unset($conf->global->$name);
			} else {
				dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity);
				$conf->global->$name = $value;
			}
		}
		parent::tearDown();
	}

	/**
	 * Helper to capture current const value for later restoration.
	 *
	 * @param string $name Constant name
	 * @return void
	 */
	private function backupConst(string $name): void
	{
		global $conf;
		$this->constBackup[$name] = $conf->global->$name ?? null;
	}

	/**
	 * Reflective access to private static helpers.
	 *
	 * @param string $method Method name in TalerOrderLink
	 * @param mixed  ...$args Arguments to forward
	 * @return mixed
	 */
	private function callStatic(string $method, mixed ...$args): mixed
	{
		$ref = new ReflectionMethod(TalerOrderLink::class, $method);
		if ($ref->isPrivate() || $ref->isProtected()) {
			$ref->setAccessible(true);
		}
		return $ref->invoke(null, ...$args);
	}

	/**
	 * Fetch first integer value from SQL.
	 *
	 * @param string $sql SQL query returning one numeric column.
	 * @return int
	 */
	private function fetchFirstInt(string $sql): int
	{
		global $db;

		$resql = $db->query($sql);
		if (!$resql) {
			return 0;
		}
		$row = $db->fetch_row($resql);
		$db->free($resql);
		if (!is_array($row) || !isset($row[0])) {
			return 0;
		}
		return (int) $row[0];
	}

	/**
	 * Create and validate a minimal credit note for repayment sync tests.
	 *
	 * @param int $socId         Customer id.
	 * @param int $bankAccountId Bank account id.
	 * @return Facture
	 */
	private function createValidatedCreditNote(int $socId, int $bankAccountId): Facture
	{
		global $db, $user;

		$credit = new Facture($db);
		$credit->socid = $socId;
		$credit->type = Facture::TYPE_CREDIT_NOTE;
		$credit->date = dol_now();
		$credit->fk_account = $bankAccountId;

		$createResult = $credit->create($user);
		$this->assertGreaterThan(0, $createResult, 'Credit note creation should succeed');

		$lineResult = $credit->addline('Wallet payout sync test line', 10.0, 1, 0, 0, 0, 0);
		$this->assertGreaterThan(0, $lineResult, 'Credit note line insertion should succeed');

		$credit->fetch((int) $credit->id);
		$credit->update_price(1);

		$validateResult = $credit->validate($user);
		$this->assertGreaterThan(0, $validateResult, 'Credit note validation should succeed');
		$credit->fetch((int) $credit->id);

		return $credit;
	}

	/**
	 * Test normalizeToArray() converts nested stdClass into array.
	 * @return void
	 */
	public function testNormalizeToArrayWithStdClass(): void
	{
		$obj = (object) ['foo' => 'bar', 'nested' => (object) ['baz' => 1]];
		$result = $this->callStatic('normalizeToArray', $obj);
		$this->assertSame(['foo' => 'bar', 'nested' => ['baz' => 1]], $result);
	}

	/**
	 * Test normalizeToArray() returns same array input.
	 * @return void
	 */
	public function testNormalizeToArrayWithArray(): void
	{
		$source = ['alpha' => 1, 'beta' => 'two'];
		$this->assertSame($source, $this->callStatic('normalizeToArray', $source));
	}

	/**
	 * Test normalizeToArray() returns empty array for null input.
	 * @return void
	 */
	public function testNormalizeToArrayWithNull(): void
	{
		$this->assertSame([], $this->callStatic('normalizeToArray', null));
	}


	/**
	 * Test hydrateInvoiceSnapshotFromFacture() maps invoice fields to link snapshot.
	 * @return void
	 */
	public function testHydrateInvoiceSnapshotFromFacture(): void
	{
		global $db;
		$invoice = new Facture($db);
		$invoice->id = 77;
		$invoice->ref = 'FA77';
		$invoice->date = dol_now();
		$invoice->date_validation = dol_now() + 120;
		$invoice->cond_reglement_id = 8;

		$link = new TalerOrderLink($db);

		$this->callStatic('hydrateInvoiceSnapshotFromFacture', $db, $link, $invoice);

		$this->assertSame(77, $link->fk_facture);
		$this->assertSame('FA77', $link->facture_ref_snap);
		$this->assertNotEmpty($link->facture_datef);
		$this->assertNotEmpty($link->facture_validated_at);
		$this->assertSame(8, $link->fk_cond_reglement);
	}

	/**
	 * Test coalesceString() picks first non-empty string correctly.
	 * @return void
	 */
	public function testCoalesceString(): void
	{
		$this->assertSame('foo', $this->callStatic('coalesceString', '', 0, null, 'foo', 'bar'));
		$this->assertSame('42', $this->callStatic('coalesceString', '', 0, 42, 'bar'));
		$this->assertSame('', $this->callStatic('coalesceString', null, '', 0));
	}

	/**
	 * Test parseTimestamp() handles int, array and ISO string variants.
	 * @return void
	 */
	public function testParseTimestampVariants(): void
	{
		$iso = $this->callStatic('parseTimestamp', '2024-01-01T12:34:56Z');
		$this->assertIsInt($iso);
		$this->assertGreaterThan(0, $iso);

		$this->assertSame(123, $this->callStatic('parseTimestamp', 123));
		$this->assertSame(456, $this->callStatic('parseTimestamp', ['t_s' => 456]));
		$this->assertNull($this->callStatic('parseTimestamp', 'not-a-date'));
	}

	/**
	 * Test extractAmount() parses amount string correctly.
	 * @return void
	 */
	public function testExtractAmountFromString(): void
	{
		$out = $this->callStatic('extractAmount', 'EUR:10.50000000');
		$this->assertSame('EUR', $out['currency']);
		$this->assertSame(10, $out['value']);
		$this->assertSame(50000000, $out['fraction']);
		$this->assertSame('EUR:10.50000000', $out['amount_str']);
	}

	/**
	 * Test extractAmount() reads value and fraction from array input.
	 * @return void
	 */
	public function testExtractAmountFromArray(): void
	{
		$out = $this->callStatic('extractAmount', ['currency' => 'chf', 'value' => 7]);
		$this->assertSame('CHF', $out['currency']);
		$this->assertSame(7, $out['value']);
		$this->assertNull($out['fraction']);
		$this->assertSame('CHF:7', $out['amount_str']);

		$nested = $this->callStatic('extractAmount', ['amount' => 'USD:3.2']);
		$this->assertSame('USD', $nested['currency']);
	}

	/**
	 * Test amountToFloat() converts structured amount to float.
	 * @return void
	 */
	public function testAmountToFloat(): void
	{
		$parsed = ['currency' => 'EUR', 'value' => 2, 'fraction' => 50000000];
		$this->assertEqualsWithDelta(2.5, $this->callStatic('amountToFloat', $parsed), 0.0000001);
	}

	/**
	 * Test formatAmountString() formats currency and amount string.
	 * @return void
	 */
	public function testFormatAmountString(): void
	{
		$this->assertSame('EUR:12.34000000', $this->callStatic('formatAmountString', 'eur', 12.34));
		$this->assertSame('-USD:1', $this->callStatic('formatAmountString', 'usd', -1.0));
	}

	/**
	 * Test getRefundPolicy() allows refund for paid orders before deadline.
	 * @return void
	 */
	public function testGetRefundPolicyEligible(): void
	{
		global $db;
		$link = new TalerOrderLink($db);
		$link->taler_instance = 'sandbox';
		$link->taler_order_id = 'ORDER-1';
		$link->merchant_status_raw = 'paid';
		$link->taler_state = 30;
		$link->order_amount_str = 'EUR:10';
		$link->taler_refunded_total = 'EUR:2';
		$link->taler_refund_deadline = gmdate('Y-m-d H:i:s', dol_now() + 3600);

		$policy = $link->getRefundPolicy(dol_now());
		$this->assertTrue((bool) $policy['eligible']);
		$this->assertSame('eligible', $policy['code']);
		$this->assertSame('EUR:8', $policy['remaining_amount_str']);
	}

	/**
	 * Test getRefundPolicy() blocks when refund deadline is passed.
	 * @return void
	 */
	public function testGetRefundPolicyDeadlinePassed(): void
	{
		global $db;
		$link = new TalerOrderLink($db);
		$link->taler_instance = 'sandbox';
		$link->taler_order_id = 'ORDER-2';
		$link->merchant_status_raw = 'paid';
		$link->taler_state = 30;
		$link->order_amount_str = 'EUR:5';
		$link->taler_refund_deadline = gmdate('Y-m-d H:i:s', dol_now() - 60);

		$policy = $link->getRefundPolicy(dol_now());
		$this->assertFalse((bool) $policy['eligible']);
		$this->assertSame('blocked_deadline', $policy['code']);
	}

	/**
	 * Test getRefundPolicy() blocks when there is no remaining refundable amount.
	 * @return void
	 */
	public function testGetRefundPolicyNoRemaining(): void
	{
		global $db;
		$link = new TalerOrderLink($db);
		$link->taler_instance = 'sandbox';
		$link->taler_order_id = 'ORDER-3';
		$link->merchant_status_raw = 'refunded';
		$link->taler_state = 70;
		$link->order_amount_str = 'EUR:3';
		$link->taler_refunded_total = 'EUR:3';

		$policy = $link->getRefundPolicy(dol_now());
		$this->assertFalse((bool) $policy['eligible']);
		$this->assertSame('blocked_no_remaining', $policy['code']);
	}

	/**
	 * Test sanitizeOrderIdCandidate() filters invalid characters.
	 * @return void
	 */
	public function testSanitizeOrderIdCandidate(): void
	{
		$this->assertSame('Abc-123', $this->callStatic('sanitizeOrderIdCandidate', '  Abc*123  '));
		$this->assertSame('', $this->callStatic('sanitizeOrderIdCandidate', '!!!'));
	}

	/**
	 * Test resolveCustomerId() returns fk_soc when set.
	 * @return void
	 */
	public function testResolveCustomerIdPrefersLink(): void
	{
		global $db;
		$link = new TalerOrderLink($db);
		$link->fk_soc = 19;
		$this->assertSame(19, $this->callStatic('resolveCustomerId', $link));
	}

	/**
	 * Test resolveCustomerId() falls back to constant when link fk_soc is null.
	 * @return void
	 */
	public function testResolveCustomerIdFallsBackToConst(): void
	{
		global $db, $conf;
		dolibarr_set_const($db, 'TALERBARR_DEFAULT_SOCID', 23, 'chaine', 0, '', $conf->entity);
		$link = new TalerOrderLink($db);
		$link->fk_soc = null;
		$this->assertSame(23, $this->callStatic('resolveCustomerId', $link));
	}

	/**
	 * Test resolvePaymentModeId() reads payment mode from constant.
	 * @return void
	 */
	public function testResolvePaymentModeIdUsesConst(): void
	{
		global $db, $conf;
		dolibarr_set_const($db, 'TALERBARR_PAYMENT_MODE_ID', 31, 'chaine', 0, '', $conf->entity);
		$this->assertSame(31, $this->callStatic('resolvePaymentModeId'));
	}

	/**
	 * Test resolveClearingAccountId() picks bank or account constant.
	 * @return void
	 */
	public function testResolveClearingAccountId(): void
	{
		global $db, $conf;
		dolibarr_set_const($db, 'TALERBARR_CLEARING_BANK_ACCOUNT', 41, 'chaine', 0, '', $conf->entity);
		$this->assertSame(41, $this->callStatic('resolveClearingAccountId'));

		dolibarr_set_const($db, 'TALERBARR_CLEARING_BANK_ACCOUNT', '', 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'TALERBARR_CLEARING_ACCOUNT_ID', 42, 'chaine', 0, '', $conf->entity);
		$this->assertSame(42, $this->callStatic('resolveClearingAccountId'));
	}

	/**
	 * Test resolveFinalAccountId() reads from constant or config.
	 * @return void
	 */
	public function testResolveFinalAccountId(): void
	{
		global $db, $conf;
		dolibarr_set_const($db, 'TALERBARR_FINAL_BANK_ACCOUNT', 51, 'chaine', 0, '', $conf->entity);
		$this->assertSame(51, $this->callStatic('resolveFinalAccountId', null));

		dolibarr_set_const($db, 'TALERBARR_FINAL_BANK_ACCOUNT', '', 'chaine', 0, '', $conf->entity);
		$cfg = new TalerConfig($db);
		$cfg->fk_bank_account = 52;
		$this->assertSame(52, $this->callStatic('resolveFinalAccountId', $cfg));
	}

	/**
	 * Test buildOrderSummary() strips HTML and trims notes.
	 * @return void
	 */
	public function testBuildOrderSummaryFromNotes(): void
	{
		global $db;
		$cmd = new Commande($db);
		$cmd->id = 77;
		$cmd->note_public = "  Hello <strong>world</strong>\n";
		$this->assertSame('Hello world', $this->callStatic('buildOrderSummary', $cmd));
	}

	/**
	 * Test buildOrderSummary() falls back to order id summary.
	 * @return void
	 */
	public function testBuildOrderSummaryFallback(): void
	{
		global $db;
		$cmd = new Commande($db);
		$cmd->id = 88;
		$this->assertSame('Dolibarr order #88', $this->callStatic('buildOrderSummary', $cmd));
	}

	/**
	 * Ensure wallet payout sync creates incremental Dolibarr repayments for credit notes.
	 *
	 * @return void
	 */
	public function testScenarioSyncCreditNotePayoutCreatesIncrementalRepayments(): void
	{
		global $db, $conf, $user;

		$socId = $this->fetchFirstInt('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE client IN (1,3) ORDER BY rowid ASC LIMIT 1');
		if ($socId <= 0) {
			$this->markTestSkipped('No customer available to create a credit note.');
		}

		$bankAccountId = $this->fetchFirstInt('SELECT rowid FROM '.MAIN_DB_PREFIX.'bank_account WHERE clos = 0 ORDER BY rowid ASC LIMIT 1');
		if ($bankAccountId <= 0) {
			$this->markTestSkipped('No open bank account available for payment tests.');
		}
		$creditNoteBankAccountId = $this->fetchFirstInt('SELECT rowid FROM '.MAIN_DB_PREFIX.'bank_account WHERE clos = 0 AND rowid <> '.((int) $bankAccountId).' ORDER BY rowid ASC LIMIT 1');
		if ($creditNoteBankAccountId <= 0) {
			$creditNoteBankAccountId = $bankAccountId;
		}

		$paymentModeId = $this->fetchFirstInt('SELECT id FROM '.MAIN_DB_PREFIX.'c_paiement WHERE active = 1 ORDER BY id ASC LIMIT 1');
		if ($paymentModeId <= 0) {
			$this->markTestSkipped('No active payment mode available for payment tests.');
		}

		dolibarr_set_const($db, 'TALERBARR_PAYMENT_MODE_ID', $paymentModeId, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'TALERBARR_CLEARING_BANK_ACCOUNT', $bankAccountId, 'chaine', 0, '', $conf->entity);
		$conf->global->TALERBARR_PAYMENT_MODE_ID = (string) $paymentModeId;
		$conf->global->TALERBARR_CLEARING_BANK_ACCOUNT = (string) $bankAccountId;

		$credit = $this->createValidatedCreditNote($socId, $creditNoteBankAccountId);

		$syncResult1 = TalerOrderLink::syncCreditNotePayoutToDolibarr($db, $credit, $user, 4.0);
		$this->assertSame(1, $syncResult1);
		$this->assertEqualsWithDelta(-4.0, (float) $credit->getSommePaiement(), 0.000001);
		$sqlPayment = sprintf(
			'SELECT p.rowid as payment_id, p.fk_bank as bank_line_id FROM %spaiement_facture pf INNER JOIN %spaiement p ON p.rowid = pf.fk_paiement WHERE pf.fk_facture = %d ORDER BY pf.rowid DESC LIMIT 1',
			MAIN_DB_PREFIX,
			MAIN_DB_PREFIX,
			(int) $credit->id
		);
		$resPayment = $db->query($sqlPayment);
		$this->assertTrue((bool) $resPayment);
		$objPayment = $db->fetch_object($resPayment);
		$db->free($resPayment);
		$this->assertGreaterThan(0, (int) ($objPayment->payment_id ?? 0));
		$this->assertGreaterThan(0, (int) ($objPayment->bank_line_id ?? 0));
		$sqlBank = 'SELECT fk_account FROM '.MAIN_DB_PREFIX.'bank WHERE rowid = '.((int) $objPayment->bank_line_id);
		$resBank = $db->query($sqlBank);
		$this->assertTrue((bool) $resBank);
		$objBank = $db->fetch_object($resBank);
		$db->free($resBank);
		$this->assertSame($bankAccountId, (int) ($objBank->fk_account ?? 0));

		$syncResult2 = TalerOrderLink::syncCreditNotePayoutToDolibarr($db, $credit, $user, 4.0);
		$this->assertSame(0, $syncResult2);
		$this->assertEqualsWithDelta(-4.0, (float) $credit->getSommePaiement(), 0.000001);

		$syncResult3 = TalerOrderLink::syncCreditNotePayoutToDolibarr($db, $credit, $user, 7.5);
		$this->assertSame(1, $syncResult3);
		$this->assertEqualsWithDelta(-7.5, (float) $credit->getSommePaiement(), 0.000001);

		$sql = 'SELECT COUNT(rowid) as nb FROM '.MAIN_DB_PREFIX.'paiement_facture WHERE fk_facture = '.((int) $credit->id);
		$resql = $db->query($sql);
		$this->assertTrue((bool) $resql);
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		$this->assertSame(2, (int) ($obj->nb ?? 0));
	}

	/**
	 * Test logThrowable() handles exceptions without rethrowing.
	 * @return void
	 */
	public function testLogThrowableDoesNotThrow(): void
	{
		$this->callStatic('logThrowable', 'testContext', new Exception('boom'));
		$this->assertTrue(true); // just ensure no exception bubbles up
	}

	/**
	 * Ensure extractAmount() uses centralized currency alias mapping for KUDOS.
	 * Regression guard against hardcoded KUDOS=>EUR conversion.
	 *
	 * @return void
	 */
	public function testScenarioExtractAmountUsesConfiguredCurrencyAliasForKudos(): void
	{
		$expected = TalerConfig::mapCurrencyFromTaler('KUDOS');
		$out = $this->callStatic('extractAmount', 'KUDOS:10');

		$this->assertSame($expected, $out['currency']);
		$this->assertSame(10, $out['value']);
		$this->assertSame(0, $out['fraction']);
		$this->assertSame('KUDOS:10', $out['amount_str']);
	}

	/**
	 * Ensure trigger helper flags backend currency/state conflicts from hint text.
	 *
	 * @return void
	 */
	public function testScenarioCurrencyConflictHintIsDetected(): void
	{
		$ref = new ReflectionMethod(modTalerBarr_TalerBarrTriggers::class, 'isCurrencyConflictHint');
		$ref->setAccessible(true);

		$this->assertTrue((bool) $ref->invoke(null, 'The currency specified in the operation does not work with the current state of the given resource.'));
		$this->assertFalse((bool) $ref->invoke(null, 'Refund amount exceeds remaining refundable total.'));
	}
}

// phpcs:enable Squiz.Commenting.FunctionComment.Missing,PSR1.Methods.CamelCapsMethodName.NotCamelCaps
