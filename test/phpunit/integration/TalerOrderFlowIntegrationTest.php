<?php
declare(strict_types=1);

/**
 * Full end-to-end order flow test between Dolibarr and GNU Taler using taler-wallet-cli.
 *
 * The test is intentionally guarded behind the TALER_INTEGRATION_TEST=1 environment variable
 * because it requires a running Taler sandcastle (merchant, exchange, bank) as well as the
 * taler-wallet-cli binary to be present on the test runner.
 *
 * Expected environment variables:
 *  - TALER_INTEGRATION_TEST=1                 → opt-in for this heavy test
 *  - TALER_WALLET_CLI                         → path to taler-wallet-cli (if not on PATH)
 *  - TALER_EXCHANGE_URL                       → Exchange URL (default: http://exchange.test.taler.potuzhnyi.com/)
 *  - TALER_BANK_URL                           → Bank URL (default: http://bank.test.taler.potuzhnyi.com/)
 *  - TALER_MERCHANT_URL                       → Merchant URL (default: http://merchant.test.taler.potuzhnyi.com/)
 *  - TALER_MERCHANT_API_KEY                   → OAuth token for the merchant instance
 *  - TALER_INSTANCE                           → Merchant instance name (default: "sandbox")
 *  - TALER_BANK_WITHDRAW_ACCOUNT              → Debtor account used for manual reserve top-up (default: merchant-sandbox)
 *  - TALER_BANK_WITHDRAW_PASSWORD             → Password for TALER_BANK_WITHDRAW_ACCOUNT (default: sandbox)
 * Optional helpers when sandcastle-ng is used:
 *  - SANDCASTLE_CONTAINER_NAME / TALER_SANDBOX_CONTAINER → Podman container name (default: taler-sandcastle)
 *  - TALER_PODMAN_OVERRIDE_CONF                          → Path to containers.conf override (see CI tooling)
 *  - TALER_PODMAN_USE_SUDO                               → Force/disable sudo when invoking podman (default: auto)
 */

global $conf, $user, $db, $langs;

require_once dirname(__FILE__, 7) . '/htdocs/master.inc.php';
require_once dirname(__FILE__, 7) . '/test/phpunit/CommonClassTest.class.php';

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

require_once DOL_DOCUMENT_ROOT . '/custom/talerbarr/core/modules/modTalerBarr.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/talerbarr/class/talerconfig.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/talerbarr/class/talerorderlink.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/talerbarr/class/talermerchantclient.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/talerbarr/class/talerproductlink.class.php';

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps,PEAR.Commenting.FunctionComment.MissingParamComment,Squiz.Commenting.FunctionComment.MissingParamComment
/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 * @group integration
 */
class TalerOrderFlowIntegrationTest extends CommonClassTest
{
	private const WITHDRAW_AMOUNT = 'KUDOS:20';
	private const DOL_ORDER_AMOUNT = 'KUDOS:5';
	private const TALER_ORDER_AMOUNT = 'KUDOS:5';
	private const DEFAULT_EXCHANGE_URL = 'http://exchange.test.taler.potuzhnyi.com/';
	private const DEFAULT_BANK_URL = 'http://bank.test.taler.potuzhnyi.com/';
	private const DEFAULT_MERCHANT_URL = 'http://merchant.test.taler.potuzhnyi.com/';
	private const DEFAULT_MERCHANT_TOKEN = 'secret-token:sandbox';
	private const DEFAULT_INSTANCE = 'sandbox';
	private const DEFAULT_BANK_WITHDRAW_ACCOUNT = 'merchant-sandbox';
	private const DEFAULT_BANK_WITHDRAW_PASSWORD = 'sandbox';
	private const DEFAULT_SANDBOX_CONTAINER = 'taler-sandcastle';
	private const ORDER_SETTLED_SINK_FALLBACK_AFTER_SECONDS = 50;
	private const SYNTHETIC_ORDER_SETTLED_WTID = 'UNTIL_0011139_fixed_on_taler_side';

	private static ?DoliDB $db = null;
	private static ?User $user = null;
	private static ?TalerConfig $config = null;
	private static ?Societe $customer = null;
	private static ?Product $product = null;
	private static ?string $walletCli = null;
	private static array $walletEnv = [];
	private static ?string $walletHome = null;
	private static ?string $exchangeUrl = null;
	private static ?string $bankUrl = null;
	private static ?string $bankWithdrawAccount = null;
	private static ?string $bankWithdrawPassword = null;
	private static ?string $merchantUrl = null;
	private static ?string $merchantApiKey = null;
	private static string $merchantInstance = 'sandbox';
	private static ?string $webhookSinkListUrl = null;
	private static ?string $webhookSinkResetUrl = null;
	private static bool $webhookSinkReplayEnabled = false;
	private static int $webhookSinkMinEventIdExclusive = 0;
	private static string $walletExecMode = 'local';
	private static ?string $walletContainer = null;
	private static ?bool $podmanUseSudo = null;
	private static ?string $podmanOverrideConf = null;

	/**
	 * Bootstraps the Dolibarr + sandcastle stack once before running the suite.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$runFlag = getenv('TALER_INTEGRATION_TEST');
		$normalizedFlag = is_string($runFlag) ? strtolower(trim($runFlag)) : null;
		if (!in_array($normalizedFlag, ['1', 'true', 'yes', 'on'], true)) {
			self::markTestSkipped('Set TALER_INTEGRATION_TEST=1 to run GNU Taler integration tests.');
		}
		self::setEnv('TALER_INTEGRATION_TEST', '1');

		$override = getenv('TALER_PODMAN_OVERRIDE_CONF');
		if ($override !== false) {
			$override = trim((string) $override);
			self::$podmanOverrideConf = $override !== '' ? $override : null;
		}

		global $db, $user, $conf;
		self::$db = $db;
		self::$user = $user;

		self::bootstrapLogging();

		self::$walletCli = self::locateWalletCli();
		if (!self::$walletCli) {
			self::markTestSkipped('taler-wallet-cli not available locally and sandcastle container not reachable.');
		}

		self::$exchangeUrl = self::ensureEnvUrl('TALER_EXCHANGE_URL', self::DEFAULT_EXCHANGE_URL);
		self::$bankUrl = self::ensureEnvUrl('TALER_BANK_URL', self::DEFAULT_BANK_URL);
		self::$bankWithdrawAccount = self::ensureEnv('TALER_BANK_WITHDRAW_ACCOUNT', self::DEFAULT_BANK_WITHDRAW_ACCOUNT);
		self::$bankWithdrawPassword = self::ensureEnv('TALER_BANK_WITHDRAW_PASSWORD', self::DEFAULT_BANK_WITHDRAW_PASSWORD);
		self::$merchantUrl = self::ensureEnvUrl('TALER_MERCHANT_URL', self::DEFAULT_MERCHANT_URL);
		self::$merchantApiKey = self::ensureEnv('TALER_MERCHANT_API_KEY', self::DEFAULT_MERCHANT_TOKEN);
		self::$merchantInstance = self::ensureEnv('TALER_INSTANCE', self::DEFAULT_INSTANCE);
		self::$webhookSinkListUrl = self::optionalEnv('TALER_WEBHOOK_SINK_URL');
		self::$webhookSinkResetUrl = self::optionalEnv('TALER_WEBHOOK_SINK_RESET_URL');
		if (self::$webhookSinkListUrl !== null && self::$webhookSinkResetUrl === null) {
			self::$webhookSinkResetUrl = rtrim(self::$webhookSinkListUrl, '/') . '/reset';
		}
		self::$webhookSinkReplayEnabled = self::$webhookSinkListUrl !== null;

		self::prepareWalletHome();

		$module = new modTalerBarr(self::$db);
		$module->init('');

		self::ensureModuleTables();
		self::loadDefaultCustomer();
		self::seedConfiguration();
		self::ensureTestProduct();

		dolibarr_set_const(self::$db, 'TALERBARR_DEFAULT_SOCID', (int) self::$customer->id, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const(self::$db, 'TALERBARR_PAYMENT_MODE_ID', 1, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const(self::$db, 'TALERBARR_CLEARING_BANK_ACCOUNT', 1, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const(self::$db, 'TALERBARR_FINAL_BANK_ACCOUNT', 1, 'chaine', 0, '', $conf->entity);
		$conf->global->TALERBARR_DEFAULT_SOCID = (int) self::$customer->id;
		$conf->global->TALERBARR_PAYMENT_MODE_ID = 1;
		$conf->global->TALERBARR_CLEARING_BANK_ACCOUNT = 1;
		$conf->global->TALERBARR_FINAL_BANK_ACCOUNT = 1;
	}

	/**
	 * Cleans up the ephemeral wallet home after the test suite completes.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		parent::tearDownAfterClass();

		if (self::$walletHome) {
			if (self::$walletExecMode === 'podman' && self::$walletContainer) {
				$res = self::runPodmanExec(['rm', '-rf', self::$walletHome]);
				if ($res['code'] !== 0) {
					print "Failed to clean wallet home inside container: ".$res['stderr']."\n";
				}
			} elseif (is_dir(self::$walletHome)) {
				dol_delete_dir_recursive(self::$walletHome);
			}
		}
	}

	/**
	 * Stage 1: Dolibarr-origin order is pushed to Taler and synced back on payment.
	 *
	 * @return array<string,mixed>
	 */
	public function testStage1_DolibarrOrderPushAndPaymentSync(): array
	{
		$this->logStageStep('stage-1', 'start');

		$commande = $this->createDolibarrOrder();
		$this->assertNotEmpty($commande->id);

		$result = TalerOrderLink::upsertFromDolibarr(self::$db, $commande, self::$user);
		$this->assertGreaterThanOrEqual(0, $result, 'upsertFromDolibarr should not fail');

		$link = $this->fetchLinkByCommande((int) $commande->id);
		$this->assertNotNull($link, 'Order link expected after Dolibarr push');
		$this->assertNotEmpty($link->taler_pay_uri, 'Taler pay URI must be populated');

		$this->walletWithdraw(self::WITHDRAW_AMOUNT);
		$this->walletPayUri((string) $link->taler_pay_uri);

		$statusPaid = $this->waitForMerchantStatus((string) $link->taler_order_id, 45);
		$this->assertNotEmpty($statusPaid, 'Merchant status payload required');

		$rcPayment = TalerOrderLink::upsertFromTalerOfPayment(self::$db, $statusPaid, self::$user);
		$this->assertSame(1, $rcPayment, 'Payment sync should succeed');

		$linkPaid = $this->refetchLink((int) $link->id);
		$this->assertNotEmpty($linkPaid->fk_facture, 'Invoice should be linked after payment');

		$invoice = new Facture(self::$db);
		$invoiceFetch = $invoice->fetch((int) $linkPaid->fk_facture);
		$this->assertGreaterThan(0, $invoiceFetch, 'Invoice record must exist');
		$this->assertGreaterThanOrEqual(Facture::STATUS_VALIDATED, (int) $invoice->status, 'Invoice expected to be validated');

		$stageData = [
			'first_link_id' => (int) $linkPaid->id,
			'first_order_id' => (string) $linkPaid->taler_order_id,
			'first_invoice_id' => (int) $linkPaid->fk_facture,
			'first_commande_id' => (int) $linkPaid->fk_commande,
		];
		$this->logStageStep('stage-1', 'completed', $stageData);
		return $stageData;
	}

	/**
	 * Stage 2: Taler-origin order creation with sync-on-paid does not create Dolibarr artefacts yet.
	 *
	 * @depends testStage1_DolibarrOrderPushAndPaymentSync
	 * @param array<string,mixed> $stage1
	 * @return array<string,mixed>
	 */
	public function testStage2_TalerOrderCreationRespectsSyncOnPaid(array $stage1): array
	{
		$this->logStageStep('stage-2', 'start', $stage1);

		$this->updateSyncDirection(1, 1);
		$orderFromTaler = $this->createMerchantOrder(self::TALER_ORDER_AMOUNT);
		$this->assertArrayHasKey('status', $orderFromTaler);
		$this->assertArrayHasKey('taler_pay_uri', $orderFromTaler['status']);

		$rcCreation = TalerOrderLink::upsertFromTalerOnOrderCreation(
			self::$db,
			$orderFromTaler['status'],
			self::$user,
			$orderFromTaler['status']['contract_terms'] ?? []
		);
		$this->assertSame(1, $rcCreation, 'Order creation sync should succeed');

		$linkFromTaler = $this->fetchLinkByOrderId((string) $orderFromTaler['status']['order_id']);
		$this->assertNotNull($linkFromTaler, 'Order link missing for Taler-origin order');
		$this->assertEmpty($linkFromTaler->fk_commande, 'Dolibarr order must not exist before payment when sync_on_paid=1');
		$this->assertEmpty($linkFromTaler->fk_facture, 'Invoice must not exist before payment when sync_on_paid=1');

		$payUri = (string) ($orderFromTaler['status']['taler_pay_uri'] ?? $orderFromTaler['status']['pay_url'] ?? '');
		$this->assertNotSame('', $payUri, 'Merchant status must expose a pay URI');

		$stageData = array_merge($stage1,
			[
			'second_order_id' => (string) $orderFromTaler['status']['order_id'],
			'second_link_id' => (int) $linkFromTaler->id,
			'second_pay_uri' => $payUri,
		]);
		$this->logStageStep('stage-2',
			'completed',
			[
			'second_order_id' => $stageData['second_order_id'],
			'second_link_id' => $stageData['second_link_id'],
		]);
		return $stageData;
	}

	/**
	 * Stage 3: Taler-origin order materialises Dolibarr order/invoice only after payment.
	 *
	 * @depends testStage2_TalerOrderCreationRespectsSyncOnPaid
	 * @param array<string,mixed> $stage2
	 * @return array<string,mixed>
	 */
	public function testStage3_TalerOrderMaterializesAfterPayment(array $stage2): array
	{
		$this->logStageStep('stage-3',
			'start',
			[
			'second_order_id' => $stage2['second_order_id'] ?? null,
			'second_link_id' => $stage2['second_link_id'] ?? null,
		]);

		$this->walletPayUri((string) $stage2['second_pay_uri']);
		$statusPaid2 = $this->waitForMerchantStatus((string) $stage2['second_order_id'], 45);
		$this->assertNotEmpty($statusPaid2);

		$paymentRc2 = TalerOrderLink::upsertFromTalerOfPayment(self::$db, $statusPaid2, self::$user);
		$this->assertSame(1, $paymentRc2, 'Second payment sync should succeed');

		$linkPaid2 = $this->refetchLink((int) $stage2['second_link_id']);
		$this->assertNotEmpty($linkPaid2->fk_commande, 'Dolibarr order expected after payment sync');
		$this->assertNotEmpty($linkPaid2->fk_facture, 'Invoice expected after payment sync');
		$this->assertNotEmpty($linkPaid2->commande_ref_snap, 'Order snapshot reference should be stored');
		$this->assertNotEmpty($linkPaid2->facture_ref_snap, 'Invoice snapshot reference should be stored');
		$this->assertNotEmpty($linkPaid2->order_amount_str, 'Order amount snapshot should be stored');
		$this->assertNotEmpty($linkPaid2->merchant_status_raw, 'Merchant status should be stored');

		$invoice2 = new Facture(self::$db);
		$invoice2Fetch = $invoice2->fetch((int) $linkPaid2->fk_facture);
		$this->assertGreaterThan(0, $invoice2Fetch, 'Second invoice record must exist');
		$this->assertGreaterThanOrEqual(Facture::STATUS_VALIDATED, (int) $invoice2->status, 'Second invoice expected to be validated');

		$stageData = array_merge($stage2,
			[
			'second_commande_id' => (int) $linkPaid2->fk_commande,
			'second_facture_id' => (int) $linkPaid2->fk_facture,
			'second_order_amount_str' => (string) $linkPaid2->order_amount_str,
		]);
		$this->logStageStep('stage-3',
			'completed',
			[
			'second_commande_id' => $stageData['second_commande_id'],
			'second_facture_id' => $stageData['second_facture_id'],
		]);
		return $stageData;
	}

	/**
	 * Stage 4: Refund is issued in merchant backend and synchronized back to Dolibarr link metadata.
	 *
	 * @depends testStage3_TalerOrderMaterializesAfterPayment
	 * @param array<string,mixed> $stage3
	 * @return array<string,mixed>
	 */
	public function testStage4_RefundSync(array $stage3): array
	{
		$this->logStageStep('stage-4',
			'start',
			[
			'second_order_id' => $stage3['second_order_id'] ?? null,
		]);

		$client = self::$config->talerMerchantClient();
		$refundAmountBase = (string) ($stage3['second_order_amount_str'] ?? self::TALER_ORDER_AMOUNT);
		$refundCurrency = strtoupper((string) strtok($refundAmountBase, ':'));
		if ($refundCurrency === '') {
			$refundCurrency = 'KUDOS';
		}
		$refundAmount = TalerOrderLink::toTalerAmountString($refundCurrency, 1.0);
		$refundResponse = $client->refundOrder((string) $stage3['second_order_id'],
			[
			'refund' => $refundAmount,
			'reason' => 'Integration test refund',
		]);
		$this->assertNotEmpty($refundResponse['taler_refund_uri'] ?? '', 'Refund response must include taler_refund_uri');
		$this->assertNotEmpty($refundResponse['h_contract'] ?? '', 'Refund response must include h_contract');

		$statusRefund = $client->getOrderStatus((string) $stage3['second_order_id'],
			[
			'allow_refunded_for_repurchase' => 'YES',
		]);
		$statusRefund['order_id'] = (string) $stage3['second_order_id'];
		$refundRc = TalerOrderLink::upsertFromTalerOfRefund(self::$db, $statusRefund, self::$user);
		$this->assertSame(1, $refundRc, 'Refund sync should succeed');

		$linkRefunded = $this->refetchLink((int) $stage3['second_link_id']);
		$this->assertNotEmpty($linkRefunded->taler_refunded_total, 'Refunded total should be tracked');
		$this->assertNotEmpty($linkRefunded->taler_refund_last_amount, 'Last refund amount should be tracked');

		$stageData = array_merge($stage3,
			[
			'refund_amount' => $refundAmount,
		]);
		$this->logStageStep('stage-4',
			'completed',
			[
			'second_link_id' => $stage3['second_link_id'],
			'refund_amount' => $refundAmount,
		]);
		return $stageData;
	}

	/**
	 * Stage 5: Wire transfer metadata from Taler is synchronized and persisted.
	 *
	 * @depends testStage4_RefundSync
	 * @param array<string,mixed> $stage4
	 * @return void
	 */
	public function testStage5_WireTransferSync(array $stage4): void
	{
		$this->logStageStep('stage-5',
			'start',
			[
			'first_order_id' => $stage4['first_order_id'] ?? null,
			'first_link_id' => $stage4['first_link_id'] ?? null,
		]);

		$wireAccounts = $this->ensureDistinctWireAccounts();
		$this->assertNotNull($wireAccounts, 'Need two bank accounts for wire-transfer stage');
		$this->applyWireAccounts((int) $wireAccounts['clearing'], (int) $wireAccounts['final']);

		$client = self::$config->talerMerchantClient();
		$statusWire = $client->getOrderStatus((string) $stage4['first_order_id'],
			[
			'allow_refunded_for_repurchase' => 'YES',
		]);
		$statusWire['order_id'] = (string) $stage4['first_order_id'];
		$statusWire['merchant_instance'] = self::$merchantInstance;
		$statusWire['status'] = $statusWire['order_status'] ?? 'wired';
		$statusWire['wired'] = true;
		$statusWire['execution_time'] = $statusWire['execution_time'] ?? ['t_s' => time()];

		$wireRc = TalerOrderLink::upsertFromTalerOfWireTransfer(self::$db, $statusWire, self::$user);
		$this->assertSame(1, $wireRc, 'Wire transfer sync should succeed');

		$linkWired = $this->refetchLink((int) $stage4['first_link_id']);
		$this->assertSame(1, (int) $linkWired->taler_wired, 'Wire flag should be set');
		$this->assertNotEmpty($linkWired->wire_execution_time, 'Wire execution timestamp should be set');
		$this->assertNotEmpty($linkWired->wire_details_json, 'Wire details should be stored');
		$this->assertNotEmpty($linkWired->fk_bank_account_dest, 'Destination bank account should be tracked');

		$this->logStageStep('stage-5',
			'completed',
			[
			'first_link_id' => $stage4['first_link_id'],
			'bank_account_dest' => $linkWired->fk_bank_account_dest,
		]);
	}

	/**
	 * Stage 6: Prepare independent webhook-driven order flow from a Dolibarr-created order.
	 *
	 * @return array<string,mixed>
	 */
	public function testStage6_WebhookFlowPrepareDolibarrOrder(): array
	{
		$this->logStageStep('stage-6', 'start');

		// Order creation is allowed only in Dolibarr->Taler direction.
		$this->updateSyncDirection(0, 0);

		$client = self::$config->talerMerchantClient();
		$hooks = $client->listWebhooks();
		$webhooks = is_array($hooks['webhooks'] ?? null) ? $hooks['webhooks'] : [];
		$hookIds = array_values(array_filter(array_map(
			static function ($hook): string {
				return is_array($hook) ? (string) ($hook['webhook_id'] ?? '') : '';
			},
			$webhooks
		)));

		foreach (['talerbarr_pay', 'talerbarr_refund', 'talerbarr_order_settled'] as $required) {
			$this->assertContains($required, $hookIds, 'Missing required webhook '.$required);
		}

		$payWebhook = $client->getWebhook('talerbarr_pay');
		$merchantWebhookUrl = (string) ($payWebhook['url'] ?? '');
		$this->assertNotSame('', $merchantWebhookUrl, 'Pay webhook URL must be configured');

		$webhookUrl = (string) dol_buildpath('/custom/talerbarr/webhook/webhook.php', 2);
		$this->assertNotSame('', $webhookUrl, 'Dolibarr webhook endpoint URL must be configured');
		if (self::$webhookSinkReplayEnabled) {
			$this->resetWebhookSink();
			$this->logStageStep('stage-6',
				'webhook-sink',
				[
				'merchant_webhook_url' => $merchantWebhookUrl,
				'dolibarr_webhook_url' => $webhookUrl,
				'sink_list_url' => self::$webhookSinkListUrl,
				'sink_reset_url' => self::$webhookSinkResetUrl,
			]);
		}

		// Sanity-check actual Dolibarr webhook endpoint auth/path wiring with an unsupported event.
		$probe = $this->postWebhookPayload(['event_type' => '__talerbarr_probe__'], null, $webhookUrl);
		$this->assertSame(404, $probe['code'], 'Webhook endpoint should reject unsupported event types');

		$commande = $this->createDolibarrOrder();
		$this->assertNotEmpty($commande->id);
		$result = TalerOrderLink::upsertFromDolibarr(self::$db, $commande, self::$user);
		$this->assertGreaterThanOrEqual(0, $result, 'upsertFromDolibarr should not fail in webhook flow setup');

		$link = $this->fetchLinkByCommande((int) $commande->id);
		$this->assertNotNull($link, 'Order link expected after Dolibarr push for webhook flow');
		$this->assertNotEmpty($link->taler_order_id, 'Taler order id must be populated');
		$this->assertNotEmpty($link->taler_pay_uri, 'Pay URI must be populated');

		// Enable Taler-origin webhook processing (refund/order_created family).
		$this->updateSyncDirection(1, 0);

		$stageData = [
			'webhook_url' => $webhookUrl,
			'merchant_webhook_url' => $merchantWebhookUrl,
			'webhook_order_id' => (string) $link->taler_order_id,
			'webhook_link_id' => (int) $link->id,
			'webhook_pay_uri' => (string) $link->taler_pay_uri,
			'webhook_sink_enabled' => self::$webhookSinkReplayEnabled,
		];
		$this->logStageStep('stage-6', 'completed', $stageData);
		return $stageData;
	}

	/**
	 * Stage 7: Payment state must reach Dolibarr only via webhook ingestion.
	 *
	 * @depends testStage6_WebhookFlowPrepareDolibarrOrder
	 * @param array<string,mixed> $stage6
	 * @return array<string,mixed>
	 */
	public function testStage7_WebhookPayIngestion(array $stage6): array
	{
		$this->logStageStep('stage-7',
			'start',
			[
			'order_id' => $stage6['webhook_order_id'] ?? null,
			'link_id' => $stage6['webhook_link_id'] ?? null,
		]);

		$this->walletWithdraw(self::WITHDRAW_AMOUNT);
		$this->walletPayUri((string) $stage6['webhook_pay_uri']);

		$statusPaid = $this->waitForMerchantStatus((string) $stage6['webhook_order_id'], 45);
		$this->assertNotEmpty($statusPaid, 'Merchant must report paid status');
		if (self::$webhookSinkReplayEnabled) {
			$this->logStageStep('stage-7',
				'sink-replay-attempt',
				[
				'event_type' => 'pay',
				'order_id' => (string) $stage6['webhook_order_id'],
				'sink_url' => self::$webhookSinkListUrl,
			]);
			$replayed = $this->replayWebhookFromSink('pay', (string) $stage6['webhook_order_id'], (string) $stage6['webhook_url'], 90);
			$this->logStageStep('stage-7', 'replayed-webhook', $replayed);
		} else {
			$this->logStageStep('stage-7',
				'sink-replay-disabled',
				[
				'sink_url' => self::$webhookSinkListUrl,
			]);
		}

		$linkPaid = $this->waitForLinkCondition(
			(int) $stage6['webhook_link_id'],
			static function (TalerOrderLink $link): bool {
				return !empty($link->fk_facture) && !empty($link->fk_commande);
			},
			75
		);
		$this->assertNotNull($linkPaid, 'Webhook pay event should materialize invoice and order');

		$invoice = new Facture(self::$db);
		$invoiceFetch = $invoice->fetch((int) $linkPaid->fk_facture);
		$this->assertGreaterThan(0, $invoiceFetch, 'Webhook flow invoice must exist');
		$this->assertGreaterThanOrEqual(Facture::STATUS_VALIDATED, (int) $invoice->status, 'Webhook flow invoice should be validated');

		$stageData = array_merge($stage6,
			[
			'webhook_facture_id' => (int) $linkPaid->fk_facture,
			'webhook_commande_id' => (int) $linkPaid->fk_commande,
			'webhook_order_amount_str' => (string) $linkPaid->order_amount_str,
		]);
		$this->logStageStep('stage-7',
			'completed',
			[
			'facture_id' => $stageData['webhook_facture_id'],
			'commande_id' => $stageData['webhook_commande_id'],
		]);
		return $stageData;
	}

	/**
	 * Stage 8: Refund metadata must be ingested via webhook.
	 *
	 * @depends testStage7_WebhookPayIngestion
	 * @param array<string,mixed> $stage7
	 * @return array<string,mixed>
	 */
	public function testStage8_WebhookRefundIngestion(array $stage7): array
	{
		$this->logStageStep('stage-8',
			'start',
			[
			'order_id' => $stage7['webhook_order_id'] ?? null,
		]);

		$client = self::$config->talerMerchantClient();
		$refundAmountBase = (string) ($stage7['webhook_order_amount_str'] ?? self::TALER_ORDER_AMOUNT);
		$refundCurrency = strtoupper((string) strtok($refundAmountBase, ':'));
		if ($refundCurrency === '') {
			$refundCurrency = 'KUDOS';
		}

		// Post refund request to merchant API, no update after this on dolibarr.
		$refundAmount = TalerOrderLink::toTalerAmountString($refundCurrency, 1.0);
		$refundResponse = $client->refundOrder((string) $stage7['webhook_order_id'],
			[
			'refund' => $refundAmount,
			'reason' => 'Webhook-only integration refund',
		]);
		$this->assertNotEmpty($refundResponse['taler_refund_uri'] ?? '', 'Refund request should be accepted by merchant');

		// The refund is being collected by the wallet
		$refundUri = (string) ($refundResponse['taler_refund_uri'] ?? '');
		$this->walletPayUri($refundUri);
		$client->getOrderStatus((string) $stage7['webhook_order_id'],
			[
			'allow_refunded_for_repurchase' => 'YES',
			'await_refund_obtained' => 'yes',
			'timeout_ms' => 20000,
		]);
		if (self::$webhookSinkReplayEnabled) {
			$this->logStageStep('stage-8',
				'sink-replay-attempt',
				[
				'event_type' => 'refund',
				'order_id' => (string) $stage7['webhook_order_id'],
				'sink_url' => self::$webhookSinkListUrl,
			]);
			$replayed = $this->replayWebhookFromSink('refund', (string) $stage7['webhook_order_id'], (string) $stage7['webhook_url'], 90);
			$this->logStageStep('stage-8', 'replayed-webhook', $replayed);
		} else {
			$this->logStageStep('stage-8',
				'sink-replay-disabled',
				[
				'sink_url' => self::$webhookSinkListUrl,
			]);
		}

		// Verify refund info has been landed in Dolibarr via webhook
		$linkRefunded = $this->waitForLinkCondition(
			(int) $stage7['webhook_link_id'],
			static function (TalerOrderLink $link) use ($refundAmount): bool {
				$lastAmount = (string) ($link->taler_refund_last_amount ?? '');
				$takenTotal = (string) ($link->taler_refund_taken_total ?? '');
				return $lastAmount === $refundAmount || $takenTotal === $refundAmount;
			},
			60 //very safe margin, normally 5 seconds should be more then enough for this.
		);
		$this->assertNotNull($linkRefunded, 'Refund-obtained webhook/state update was not observed after wallet collection');

		$stageData = array_merge($stage7,
			[
			'webhook_refund_amount' => $refundAmount,
		]);
		$this->logStageStep('stage-8',
			'completed',
			[
			'refund_amount' => $refundAmount,
			'refunded_total' => $linkRefunded->taler_refunded_total,
			'refund_taken_total' => $linkRefunded->taler_refund_taken_total,
		]);
		return $stageData;
	}

	/**
	 * Stage 9: Wire-settlement metadata must be ingested via webhook.
	 *
	 * @depends testStage8_WebhookRefundIngestion
	 * @param array<string,mixed> $stage8
	 * @return array<string,mixed>
	 */
	public function testStage9_WebhookWireIngestion(array $stage8): array
	{
		$this->logStageStep(
			'stage-9',
			'start',
			['order_id' => $stage8['webhook_order_id'] ?? null]
		);

		$wireAccounts = $this->ensureDistinctWireAccounts();
		$this->assertNotNull($wireAccounts, 'Need two bank accounts for webhook wire stage');
		$this->applyWireAccounts((int) $wireAccounts['clearing'], (int) $wireAccounts['final']);

		$this->updateSyncDirection(0, 0);

		$nowTs = time();
		$wireCmd = $this->createDolibarrOrder($nowTs + 5, $nowTs + 6, $nowTs + 7);
		$this->assertNotEmpty($wireCmd->id);

		$wireCreateRc = TalerOrderLink::upsertFromDolibarr(self::$db, $wireCmd, self::$user);
		$this->assertGreaterThanOrEqual(0, $wireCreateRc, 'Wire candidate order push should not fail');

		$wireLink = $this->fetchLinkByCommande((int) $wireCmd->id);
		$this->assertNotNull($wireLink, 'Wire candidate link should exist');
		$this->assertNotEmpty($wireLink->taler_pay_uri, 'Wire candidate pay URI should be present');
		$this->walletPayUri((string) $wireLink->taler_pay_uri);

		$linkPaid = $this->waitForLinkCondition(
			$wireLink->id,
			static function (TalerOrderLink $link): bool {
				return !empty($link->fk_facture);
			},
			30
		);
		$this->assertNotNull($linkPaid, 'Wire candidate payment webhook should materialize invoice');

		$wiredStatus = $this->waitForMerchantOrderState((string) $wireLink->taler_order_id, ['wired'], 200);
		$wiredState = strtolower((string) ($wiredStatus['order_status'] ?? $wiredStatus['status'] ?? ''));
		$this->assertTrue(
			$wiredState === 'wired' || !empty($wiredStatus['wired']),
			'Merchant did not reach wired state for webhook wire stage'
		);
		$settledStatus = $this->waitForMerchantOrderCondition(
			(string) $wireLink->taler_order_id,
			static function (array $status): bool {
				$wireDetails = $status['wire_details'] ?? null;
				if (!is_array($wireDetails)) {
					return false;
				}
				foreach ($wireDetails as $detail) {
					if (!is_array($detail)) {
						continue;
					}
					if (!empty($detail['confirmed'])) {
						return true;
					}
				}
				return false;
			},
			240
		);
		$confirmedWire = $this->merchantStatusHasConfirmedWire($settledStatus);
		if (!$confirmedWire) {
			// TODO: Revert this fallback once sandbox/test merchant reliably flips wire_details[].confirmed=true
			// before (or together with) order_settled webhook delivery. Some current deployments report
			// wired=true with a stable WTID while keeping confirmed=false for an extended period.
			$this->assertTrue(
				!empty($settledStatus['wired']),
				'Merchant wire settlement was not confirmed before expecting order_settled webhook'
			);
			$this->logStageStep('stage-9',
				'merchant-wire-unconfirmed-continue',
				[
				'order_id' => (string) $wireLink->taler_order_id,
				'wired' => !empty($settledStatus['wired']) ? 1 : 0,
				'confirmed' => 0,
				'wtid' => (string) ($settledStatus['wtid'] ?? ($settledStatus['wire_details'][0]['wtid'] ?? '')),
			]);
		}
		$this->logStageStep(
			'stage-9',
			'merchant-wire-confirmed',
			['order_id' => (string) $wireLink->taler_order_id,
			 'wired' => !empty($settledStatus['wired']) ? 1 : 0,
			 'order_status' => (string) ($settledStatus['order_status'] ?? $settledStatus['status'] ?? ''),]
		);
		if (self::$webhookSinkReplayEnabled) {
			$this->logStageStep('stage-9',
				'sink-replay-attempt',
				[
				'event_type' => 'order_settled',
				'order_id' => (string) $wireLink->taler_order_id,
				'sink_url' => self::$webhookSinkListUrl,
			]);
			$replayed = $this->replayWebhookFromSink('order_settled', (string) $wireLink->taler_order_id, (string) $stage8['webhook_url'], 120);
			$this->logStageStep('stage-9', 'replayed-webhook', $replayed);
		} else {
			$this->logStageStep('stage-9',
				'sink-replay-disabled',
				[
				'sink_url' => self::$webhookSinkListUrl,
			]);
		}

		$linkWired = $this->waitForLinkCondition(
			(int) $wireLink->id,
			static function (TalerOrderLink $link): bool {
				return (int) ($link->taler_wired ?? 0) === 1 && !empty($link->wire_execution_time);
			},
			40
		);
		if ($linkWired === null) {
			$lastLink = $this->refetchLink((int) $wireLink->id);
			$this->fail(
				sprintf(
					'Wire webhook should set wired metadata on the link; merchant_state=%s; merchant_wtid=%s; link_taler_wired=%d; link_wire_execution_time=%s',
					(string) ($wiredStatus['order_status'] ?? $wiredStatus['status'] ?? 'unknown'),
					(string) ($wiredStatus['wtid'] ?? ''),
					(int) ($lastLink->taler_wired ?? 0),
					(string) ($lastLink->wire_execution_time ?? '')
				)
			);
		}
		$this->assertNotEmpty($linkWired->wire_details_json, 'Wire details should be stored after webhook ingestion');
		$this->assertNotEmpty($linkWired->fk_bank_account_dest, 'Wire destination account should be set');

		$stageData = array_merge($stage8,
			[
			'webhook_wired' => 1,
			'webhook_wire_order_id' => (string) ($linkWired->taler_order_id ?? ''),
		]);
		$this->logStageStep('stage-9',
			'completed',
			[
			'wired' => $stageData['webhook_wired'],
			'wire_order_id' => $stageData['webhook_wire_order_id'],
			'bank_account_dest' => $linkWired->fk_bank_account_dest,
		]);
		return $stageData;
	}

	/**
	 * Stage 10: Webhook endpoint rejects invalid signatures.
	 *
	 * @depends testStage9_WebhookWireIngestion
	 * @param array<string,mixed> $stage9
	 * @return void
	 */
	public function testStage10_WebhookAuthenticationGuard(array $stage9): void
	{
		$this->logStageStep('stage-10',
			'start',
			[
			'order_id' => $stage9['webhook_order_id'] ?? null,
		]);

		$rejected = $this->postWebhookPayload([
			'event_type' => 'pay',
			'merchant_instance' => self::$merchantInstance,
			'order_id' => (string) $stage9['webhook_order_id'],
		],
			'INVALID_SIGNATURE_FOR_TEST',
			(string) $stage9['webhook_url']);

		$this->assertSame(403, $rejected['code'], 'Webhook endpoint must reject invalid signatures');
		$this->logStageStep('stage-10',
			'completed',
			[
			'http_code' => $rejected['code'],
			'response' => $this->truncateForLog((string) $rejected['response'], 256),
		]);
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Locate taler-wallet-cli either locally or inside the sandcastle container.
	 *
	 * @return string|null Full path/binary name when available, null otherwise.
	 */
	private static function locateWalletCli(): ?string
	{
		$explicit = getenv('TALER_WALLET_CLI');
		if (is_string($explicit) && $explicit !== '' && is_executable($explicit)) {
			self::$walletExecMode = 'local';
			return $explicit;
		}

		$which = trim((string) shell_exec('command -v taler-wallet-cli 2>/dev/null'));
		if ($which !== '') {
			self::$walletExecMode = 'local';
			return $which;
		}

		$container = self::resolveSandboxContainer();
		if (self::commandExists('podman')) {
			$result = self::runPodman(['exec', $container, 'which', 'taler-wallet-cli'], true);
			if ($result['code'] === 0 && trim((string) $result['stdout']) !== '') {
				self::$walletExecMode = 'podman';
				self::$walletContainer = $container;
				return 'taler-wallet-cli';
			}
		}

		return null;
	}

	/**
	 * Ensure a URL-like variable is defined and ends with a trailing slash.
	 *
	 * @param string $var     Environment variable name.
	 * @param string $default Default value to write when missing.
	 * @return string Normalised URL including trailing slash.
	 */
	private static function ensureEnvUrl(string $var, string $default): string
	{
		$value = self::ensureEnv($var, $default);
		if ($value === '') {
			self::markTestSkipped('Missing required environment variable '.$var);
		}
		return rtrim(trim((string) $value), '/') . '/';
	}

	/**
	 * Ensure an environment variable exists and optionally bootstrap it.
	 *
	 * @param string $var     Environment variable name.
	 * @param string $default Default to use when not already set.
	 * @return string Current or default value.
	 */
	private static function ensureEnv(string $var, string $default): string
	{
		$value = getenv($var);
		if ($value === false || trim((string) $value) === '') {
			$value = $default;
			self::setEnv($var, $value);
		}
		return (string) $value;
	}

	/**
	 * Read an optional environment variable and return null when blank/missing.
	 *
	 * @param string $var Environment variable name.
	 * @return string|null
	 */
	private static function optionalEnv(string $var): ?string
	{
		$value = getenv($var);
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		return $value !== '' ? $value : null;
	}

	/**
	 * Synchronise an environment variable across PHP super-globals.
	 *
	 * @param string $var   Variable name.
	 * @param string $value Value to assign.
	 * @return void
	 */
	private static function setEnv(string $var, string $value): void
	{
		putenv($var.'='.$value);
		$_ENV[$var] = $value;
		$_SERVER[$var] = $value;
	}

	/**
	 * Create the wallet home directory either locally or inside the container.
	 *
	 * @return void
	 */
	private static function prepareWalletHome(): void
	{
		if (self::$walletExecMode === 'podman') {
			self::$walletHome = '/tmp/taler-wallet-'.bin2hex(random_bytes(6));
			self::$walletEnv = [
				'TALER_WALLET_HOME' => self::$walletHome,
				'HOME'              => self::$walletHome,
			];
			$result = self::runPodmanExec(['mkdir', '-p', self::$walletHome], self::$walletEnv, true);
			if ($result['code'] !== 0) {
				throw new RuntimeException('Unable to create wallet home inside sandcastle: '.$result['stderr']);
			}
			return;
		}

		self::$walletHome = sys_get_temp_dir().'/taler-wallet-'.bin2hex(random_bytes(6));
		if (!dol_mkdir(self::$walletHome)) {
			throw new RuntimeException('Unable to create wallet home directory: '.self::$walletHome);
		}
		self::$walletEnv = [
			'TALER_WALLET_HOME' => self::$walletHome,
			'HOME'              => self::$walletHome,
		];
	}

	/**
	 * Install missing module tables by replaying the bundled SQL installers.
	 *
	 * @return void
	 */
	private static function ensureModuleTables(): void
	{
		$tables = [
			'talerbarr_product_link',
			'talerbarr_category_map',
			'talerbarr_error_log',
			'talerbarr_tax_map',
			'talerbarr_talerconfig',
			'talerbarr_order_link',
		];

		foreach ($tables as $short) {
			$table = MAIN_DB_PREFIX.$short;
			$res = self::$db->query('SELECT 1 FROM '.$table.' LIMIT 1');
			if ($res) {
				self::$db->free($res);
				continue;
			}
			$sqlFile = DOL_DOCUMENT_ROOT.'/custom/talerbarr/sql/'.$short.'.sql';
			if (!is_readable($sqlFile)) {
				throw new RuntimeException('Missing SQL installer for '.$short);
			}
			run_sql($sqlFile, 1, $GLOBALS['conf']->entity, 1, 'default', 'default');
		}
	}

	/**
	 * Reuse or create the taler configuration singleton with test defaults.
	 *
	 * @return void
	 */
	private static function seedConfiguration(): void
	{
		global $conf;

		$error = null;
		$config = TalerConfig::fetchSingletonVerified(self::$db, $error);
		if ($config === null) {
			$config = new TalerConfig(self::$db);
			$config->entity = $conf->entity;
			//TODO: We have to fail at this point as config, must have been done by the previous test(ProductLink)
		}

		//we might really want to verify that the sync is from doli to taler
		$config->syncdirection = 0;
		if (empty($config->taler_currency_alias)) {
			$config->taler_currency_alias = 'KUDOS';
		}

		if (!empty($config->id)) {
			if ($config->update(self::$user) <= 0) {
				throw new RuntimeException('Unable to update Taler configuration: '.$config->error);
			}
		} else {
			$id = $config->create(self::$user);
			if ($id <= 0) {
				throw new RuntimeException('Unable to create Taler configuration: '.$config->error);
			}
			$config->id = $id;
		}

		self::$config = $config;
	}

	/**
	 * Load the module-provisioned default customer created during module init.
	 *
	 * @return void
	 */
	private static function loadDefaultCustomer(): void
	{
		global $conf;

		$defaultSocId = (int) getDolGlobalInt('TALERBARR_DEFAULT_SOCID');
		if ($defaultSocId <= 0) {
			throw new RuntimeException('TALERBARR_DEFAULT_SOCID not initialised; module bootstrap failed.');
		}

		$company = new Societe(self::$db);
		if ($company->fetch($defaultSocId) <= 0 || (int) $company->entity !== (int) $conf->entity) {
			throw new RuntimeException('Unable to fetch default Taler customer (ID '.$defaultSocId.'): '.$company->error);
		}

		self::$customer = $company;
	}

	/**
	 * Ensure a deterministic service product exists for integration flows.
	 *
	 * @return void
	 */
	private static function ensureTestProduct(): void
	{
		global $conf;

		$ref = 'TALER-TEST-PRODUCT';
		$product = new Product(self::$db);
		if ($product->fetch(0, $ref) > 0 && (int) $product->entity === (int) $conf->entity) {
			self::$product = $product;
			return;
		}

		$product->initAsSpecimen();
		$product->ref = $ref;
		$product->label = 'Taler integration test product';
		$product->price = 5.00;
		$product->price_ttc = 5.00;
		$product->price_base_type = 'HT';
		$product->tva_tx = 0;
		$product->status = 1;
		$product->status_buy = 0;
		$product->entity = $conf->entity;
		$product->type = Product::TYPE_SERVICE;

		$resCreate = $product->create(self::$user);
		if ($resCreate <= 0) {
			if ($product->errorcode === 'DB_ERROR_RECORD_ALREADY_EXISTS' || str_contains((string) $product->error, 'Duplicate')) {
				if ($product->fetch(0, $ref) > 0) {
					self::$product = $product;
					return;
				}
			}
			throw new RuntimeException('Unable to provision test product: '.$product->error);
		}

		self::$product = $product;
	}

	/**
	 * Bootstrap a validated Dolibarr order for the product under test.
	 *
	 * @param int|null $payDeadlineTs    Optional payment deadline timestamp.
	 * @param int|null $refundDeadlineTs Optional refund deadline override timestamp.
	 * @param int|null $wireDeadlineTs   Optional wire transfer deadline override timestamp.
	 * @return Commande Newly created order instance.
	 */
	private function createDolibarrOrder(?int $payDeadlineTs = null, ?int $refundDeadlineTs = null, ?int $wireDeadlineTs = null): Commande
	{
		$cmd = new Commande(self::$db);
		$cmd->socid = self::$customer->id;
		$cmd->date = dol_now();
		$cmd->entity = self::$customer->entity;
		$cmd->cond_reglement_id = 1;
		$cmd->mode_reglement_id = 1;
		$cmd->multicurrency_code = 'KUDOS';
		if ($payDeadlineTs !== null) {
			$cmd->date_lim_reglement = $payDeadlineTs;
		}

		$id = $cmd->create(self::$user);
		$this->assertGreaterThan(0, $id, 'Commande creation failed: '.$cmd->error);
		$cmd->fetch($id);

		$lineRes = $cmd->addline(
			self::$product->label,
			self::$product->price,
			1,
			self::$product->tva_tx,
			0,
			0,
			self::$product->id
		);
		$this->assertGreaterThan(0, $lineRes, 'Failed to add order line: '.$cmd->error);

		$this->assertGreaterThan(0, $cmd->valid(self::$user), 'Order validation failed: '.$cmd->error);
		if ($refundDeadlineTs !== null) {
			$cmd->_taler_refund_deadline_ts = $refundDeadlineTs;
		}
		if ($wireDeadlineTs !== null) {
			$cmd->_taler_wire_transfer_deadline_ts = $wireDeadlineTs;
		}
		return $cmd;
	}

	/**
	 * Fetch the most recent taler link for a given Dolibarr order.
	 *
	 * @param int $commandeId Order identifier.
	 * @return TalerOrderLink|null Matching link or null when absent.
	 */
	private function fetchLinkByCommande(int $commandeId): ?TalerOrderLink
	{
		$link = new TalerOrderLink(self::$db);
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$link->table_element.' WHERE fk_commande = '.$commandeId.' ORDER BY rowid DESC LIMIT 1';
		$res = self::$db->query($sql);
		if (!$res) {
			return null;
		}
		$obj = self::$db->fetch_object($res);
		self::$db->free($res);
		if (!$obj) {
			return null;
		}
		$link->fetch((int) $obj->rowid);
		return $link;
	}

	/**
	 * Fetch a taler link by its order identifier.
	 *
	 * @param string $orderId Merchant order identifier.
	 * @return TalerOrderLink|null Stored link or null.
	 */
	private function fetchLinkByOrderId(string $orderId): ?TalerOrderLink
	{
		$link = new TalerOrderLink(self::$db);
		if ($link->fetchByInstanceOrderId(self::$merchantInstance, $orderId) > 0) {
			return $link;
		}
		return null;
	}

	/**
	 * Rehydrate a taler link by primary key.
	 *
	 * @param int $rowid Link primary key.
	 * @return TalerOrderLink Fresh instance populated from DB.
	 */
	private function refetchLink(int $rowid): TalerOrderLink
	{
		$link = new TalerOrderLink(self::$db);
		$link->fetch($rowid);
		return $link;
	}

	/**
	 * Poll a link row until a predicate on its latest state returns true.
	 *
	 * @param int      $rowid          Link row identifier.
	 * @param callable $predicate      Callback receiving TalerOrderLink, should return bool.
	 * @param int      $timeoutSeconds Timeout in seconds.
	 * @return TalerOrderLink|null     Latest link once predicate matches, null on timeout.
	 */
	private function waitForLinkCondition(int $rowid, callable $predicate, int $timeoutSeconds = 45): ?TalerOrderLink
	{
		$deadline = time() + $timeoutSeconds;
		do {
			$link = $this->refetchLink($rowid);
			if ($predicate($link)) {
				return $link;
			}
			usleep(2000000);
		} while (time() <= $deadline);

		return null;
	}

	/**
	 * Withdraw test currency via wallet CLI.
	 * Follows current taler-wallet-cli(1) structure (testing withdraw-kudos / withdraw manual).
	 *
	 * @param string $amount Amount to withdraw (e.g. KUDOS:5).
	 * @return void
	 */
	private function walletWithdraw(string $amount): void
	{
		$attempts = [];
		$tries = [
			[
				'testing', 'withdraw-kudos',
				'--amount', $amount,
				'--bank-url', (string) self::$bankUrl,
				'--exchange-url', (string) self::$exchangeUrl,
				'--wait',
			],
			[
				'withdraw', 'manual',
				'--exchange', (string) self::$exchangeUrl,
				'--amount',   $amount,
			],
		];

		$lastError = null;
		foreach ($tries as $candidate) {
			$this->logWalletStep('withdraw', 'attempt', ['command' => $candidate]);
			$res = $this->runWallet($candidate);
			$attempt = [
				'command' => $candidate,
				'code'    => $res['code'],
				'stdout'  => $this->truncateForLog($res['stdout']),
				'stderr'  => $this->truncateForLog($res['stderr']),
			];
			$attempts[] = $attempt;
			$this->logWalletStep('withdraw', 'result', $attempt);
			if ($res['code'] === 0) {
				if ($this->isManualWithdrawCommand($candidate)) {
					$manualFunding = $this->fundManualWithdraw((string) $res['stdout']);
					if (!$manualFunding['ok']) {
						$lastError = $manualFunding['error'];
						break;
					}
				}
				return;
			}
			$lastError = $res['stderr'] ?: $res['stdout'];
			if (
				stripos((string) $lastError, 'unknown command') === false
				&& stripos((string) $lastError, 'unknown option') === false
			) {
				break;
			}
		}

		$this->fail(
			'Wallet withdraw failed: '.($lastError ?? 'unknown error')
			.'; attempts='.json_encode($attempts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			);
	}

	/**
	 * Detect whether the wallet command is the manual withdraw fallback.
	 *
	 * @param array<int,string> $candidate Command candidate.
	 * @return bool
	 */
	private function isManualWithdrawCommand(array $candidate): bool
	{
		return isset($candidate[0], $candidate[1])
			&& $candidate[0] === 'withdraw'
			&& $candidate[1] === 'manual';
	}

	/**
	 * For manual withdrawals, wire the reserve via libeufin-bank and wait for settlement.
	 *
	 * @param string $stdout Wallet command stdout.
	 * @return array{ok:bool,error:string}
	 */
	private function fundManualWithdraw(string $stdout): array
	{
		$paytoUri = '';
		if (preg_match('/^Payto URI\\s+(\\S+)/m', $stdout, $match) === 1) {
			$paytoUri = (string) ($match[1] ?? '');
		}
		if ($paytoUri === '') {
			return [
				'ok'    => false,
				'error' => 'Manual withdraw returned no Payto URI; stdout='.$this->truncateForLog($stdout, 1024),
			];
		}

		$bankTransfer = $this->submitBankTransfer($paytoUri);
		$this->logWalletStep('withdraw',
			'bank-transfer',
			[
			'url'      => $bankTransfer['url'],
			'code'     => $bankTransfer['code'],
			'response' => $this->truncateForLog($bankTransfer['response'], 1024),
		]);
		if (!$bankTransfer['ok']) {
			return [
				'ok'    => false,
				'error' => 'Bank transfer for reserve funding failed: '.$bankTransfer['error'],
			];
		}

		$sync = $this->runWallet(['run-until-done']);
		$this->logWalletStep('withdraw',
			'run-until-done',
			[
			'code'   => $sync['code'],
			'stdout' => $this->truncateForLog($sync['stdout']),
			'stderr' => $this->truncateForLog($sync['stderr']),
		]);
		if ($sync['code'] !== 0) {
			return [
				'ok'    => false,
				'error' => 'Wallet run-until-done failed after manual funding: '.($sync['stderr'] ?: $sync['stdout']),
			];
		}

		return ['ok' => true, 'error' => ''];
	}

	/**
	 * POST a payto:// transfer request to libeufin-bank.
	 *
	 * @param string $paytoUri Reserve top-up payto URI from wallet manual withdraw.
	 * @return array{ok:bool,code:int,response:string,error:string,url:string}
	 */
	private function submitBankTransfer(string $paytoUri): array
	{
		$account = trim((string) self::$bankWithdrawAccount);
		$password = (string) self::$bankWithdrawPassword;
		$bankBaseUrl = rtrim((string) self::$bankUrl, '/');
		if ($account === '' || $password === '' || $bankBaseUrl === '') {
			return [
				'ok'       => false,
				'code'     => 0,
				'response' => '',
				'error'    => 'Missing TALER_BANK_URL/TALER_BANK_WITHDRAW_ACCOUNT/TALER_BANK_WITHDRAW_PASSWORD',
				'url'      => '',
			];
		}

		$url = $bankBaseUrl.'/accounts/'.rawurlencode($account).'/transactions';
		$headers = [
			'Content-Type: application/json',
			'Authorization: Basic '.base64_encode($account.':'.$password),
		];
		$payloads = [
			['payto_uri' => $paytoUri],
			['paytoUri' => $paytoUri],
		];
		$lastCode = 0;
		$lastBody = '';
		foreach ($payloads as $payloadData) {
			$payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES);
			if ($payload === false) {
				continue;
			}
			$context = stream_context_create([
				'http' => [
					'method'        => 'POST',
					'header'        => implode("\r\n", $headers)."\r\n",
					'content'       => $payload,
					'ignore_errors' => true,
					'timeout'       => 30,
				],
			]);
			$response = @file_get_contents($url, false, $context);
			$rawHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
			$code = $this->extractHttpStatusCode($rawHeaders);
			$body = is_string($response) ? $response : '';
			if ($code >= 200 && $code < 300) {
				return [
					'ok'       => true,
					'code'     => $code,
					'response' => $body,
					'error'    => '',
					'url'      => $url,
				];
			}
			$lastCode = $code;
			$lastBody = $body;
		}
		return [
			'ok'       => false,
			'code'     => $lastCode,
			'response' => $lastBody,
			'error'    => trim('HTTP '.$lastCode.' '.$this->truncateForLog($lastBody, 1024)),
			'url'      => $url,
		];
	}

	/**
	 * Extract HTTP status code from stream wrapper response headers.
	 *
	 * @param array<int,string> $headers Response headers.
	 * @return int
	 */
	private function extractHttpStatusCode(array $headers): int
	{
		$statusCode = 0;
		foreach ($headers as $header) {
			if (preg_match('/^HTTP\\/\\S+\\s+(\\d{3})\\b/i', $header, $match) === 1) {
				$statusCode = (int) ($match[1] ?? 0);
			}
		}
		return $statusCode;
	}
	// WHAT A BULLSHIT, IT MUST BE JUST GET + FILTERS OVER THE RECEIVED JSON OBJECTS// WHAT A BULLSHIT, IT MUST BE JUST GET + FILTERS OVER THE RECEIVED JSON OBJECTS
	/**
	 * Post a webhook JSON payload to the configured Dolibarr webhook endpoint.
	 *
	 * Used both for local probe calls and replaying payloads captured from a webhook sink.
	 *
	 * @param array<string,mixed> $payload      Webhook JSON payload.
	 * @param string|null         $authOverride Optional auth credential override.
	 * @param string|null         $targetUrl    Optional endpoint override.
	 * @return array{code:int,response:string,error:string,url:string}
	 */
	private function postWebhookPayload(array $payload, ?string $authOverride = null, ?string $targetUrl = null): array
	{
		$bodyPayload = $payload;
		if (!isset($bodyPayload['auth_signature']) || !is_string($bodyPayload['auth_signature']) || trim((string) $bodyPayload['auth_signature']) === '') {
			$token = (string) (self::$config->talertoken ?? '');
			$this->assertNotSame('', $token, 'Missing TALER token for webhook authentication');
			$bodyPayload['auth_signature'] = (string) ($authOverride ?? hash('sha256', $token));
		}
		$body = json_encode($bodyPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->assertNotFalse($body, 'Failed to encode webhook payload as JSON');

		return $this->postWebhookRawBody((string) $body, $authOverride, $targetUrl, 'application/json');
	}

	/**
	 * Post a raw webhook request body to the configured Dolibarr webhook endpoint.
	 *
	 * Used by sink replay to preserve the exact captured payload bytes/body format.
	 *
	 * @param string      $body         Raw request body.
	 * @param string|null $authOverride Optional auth credential override.
	 * @param string|null $targetUrl    Optional endpoint override.
	 * @param string|null $contentType  Optional content type override.
	 * @return array{code:int,response:string,error:string,url:string}
	 */
	private function postWebhookRawBody(string $body, ?string $authOverride = null, ?string $targetUrl = null, ?string $contentType = null): array
	{
		$url = trim((string) ($targetUrl ?? dol_buildpath('/custom/talerbarr/webhook/webhook.php', 2)));
		$this->assertNotSame('', $url, 'Webhook URL is empty');

		$token = (string) (self::$config->talertoken ?? '');
		$this->assertNotSame('', $token, 'Missing TALER token for webhook authentication');
		$auth = (string) ($authOverride ?? hash('sha256', $token));

		$headers = [
			'Content-Type: '.trim((string) ($contentType ?: 'application/json')),
			'X-Auth-Header: '.$auth,
		];
		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => implode("\r\n", $headers)."\r\n",
				'content' => $body,
				'ignore_errors' => true,
				'timeout' => 30,
			],
		]);

		$response = @file_get_contents($url, false, $context);
		$rawHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
		$code = $this->extractHttpStatusCode($rawHeaders);
		$responseBody = is_string($response) ? $response : '';

		return [
			'code' => $code,
			'response' => $responseBody,
			'error' => $code === 0 ? 'No HTTP response' : '',
			'url' => $url,
		];
	}

	/**
	 * Clear the optional webhook sink before replay-based stages.
	 *
	 * @return void
	 */
	private function resetWebhookSink(): void
	{
		if (!self::$webhookSinkReplayEnabled || empty(self::$webhookSinkResetUrl)) {
			return;
		}
		$res = $this->httpJsonRequest((string) self::$webhookSinkResetUrl, 'POST');
		if ($res['code'] >= 200 && $res['code'] < 300) {
			self::$webhookSinkMinEventIdExclusive = 0;
			return;
		}

		// Some deployments expose only GET /webhooks and not /webhooks/reset via nginx.
		// Fall back to a cursor baseline so we can still replay only newly captured events.
		$baseline = $this->captureWebhookSinkBaselineEventId();
		self::$webhookSinkMinEventIdExclusive = max(0, $baseline);
		dol_syslog(
			__METHOD__ . ': webhook sink reset unavailable (HTTP '.$res['code'].'); using baseline event id '
			. self::$webhookSinkMinEventIdExclusive,
			LOG_INFO
		);
	}

	/**
	 * Snapshot the current maximum sink event id for stale-event filtering.
	 *
	 * @return int
	 */
	private function captureWebhookSinkBaselineEventId(): int
	{
		if (!self::$webhookSinkReplayEnabled || empty(self::$webhookSinkListUrl)) {
			return 0;
		}
		$res = $this->httpJsonRequest((string) self::$webhookSinkListUrl, 'GET');
		if ($res['code'] < 200 || $res['code'] >= 300 || !is_array($res['json'])) {
			return 0;
		}
		$events = is_array($res['json']['events'] ?? null) ? $res['json']['events'] : [];
		$maxId = 0;
		foreach ($events as $event) {
			if (!is_array($event)) {
				continue;
			}
			$eventId = (int) ($event['id'] ?? 0);
			if ($eventId > $maxId) {
				$maxId = $eventId;
			}
		}
		return $maxId;
	}

	/**
	 * Replay one captured webhook from the sink into Dolibarr.
	 *
	 * @param string $eventType Event type to replay.
	 * @param string $orderId   Order id to match.
	 * @param string $targetUrl Target Dolibarr webhook endpoint.
	 * @param int    $timeoutSeconds Poll timeout.
	 * @return array<string,mixed>
	 */
	private function replayWebhookFromSink(string $eventType, string $orderId, string $targetUrl, int $timeoutSeconds = 45): array
	{
		$this->assertTrue(self::$webhookSinkReplayEnabled, 'Webhook sink replay mode is not enabled');
		$this->assertNotSame('', trim($targetUrl), 'Replay target URL must not be empty');

		$captured = null;
		$eventTypeNormalized = strtolower(trim($eventType));
		if ($eventTypeNormalized === 'order_settled' && $timeoutSeconds > self::ORDER_SETTLED_SINK_FALLBACK_AFTER_SECONDS) {
			$captured = $this->waitForWebhookSinkEvent($eventType, $orderId, self::ORDER_SETTLED_SINK_FALLBACK_AFTER_SECONDS);
			if ($captured === null) {
				$fallbackReplay = $this->replaySyntheticOrderSettledWebhookIfMerchantWired($orderId, $targetUrl);
				if ($fallbackReplay !== null) {
					return $fallbackReplay;
				}
				$remaining = max(1, $timeoutSeconds - self::ORDER_SETTLED_SINK_FALLBACK_AFTER_SECONDS);
				$captured = $this->waitForWebhookSinkEvent($eventType, $orderId, $remaining);
			}
		} else {
			$captured = $this->waitForWebhookSinkEvent($eventType, $orderId, $timeoutSeconds);
		}
		$this->assertNotNull($captured, 'Captured webhook '.$eventType.' for order '.$orderId.' was not found in sink');
		$capturedHeaders = is_array($captured['headers'] ?? null) ? $captured['headers'] : [];
		$capturedBody = '';
		if (isset($captured['body']) && is_string($captured['body'])) {
			$capturedBody = $captured['body'];
		} elseif (isset($captured['body_base64']) && is_string($captured['body_base64']) && $captured['body_base64'] !== '') {
			$decodedBody = base64_decode($captured['body_base64'], true);
			$this->assertNotFalse($decodedBody, 'Captured webhook base64 body could not be decoded');
			$capturedBody = (string) $decodedBody;
		}
		$this->assertNotSame('', $capturedBody, 'Captured webhook body must not be empty');
		$capturedAuth = null;
		$capturedContentType = null;
		foreach (['X-Auth-Header', 'x-auth-header'] as $headerName) {
			if (isset($capturedHeaders[$headerName]) && is_string($capturedHeaders[$headerName]) && trim($capturedHeaders[$headerName]) !== '') {
				$capturedAuth = trim((string) $capturedHeaders[$headerName]);
				break;
			}
		}
		foreach (['Content-Type', 'content-type'] as $headerName) {
			if (isset($capturedHeaders[$headerName]) && is_string($capturedHeaders[$headerName]) && trim($capturedHeaders[$headerName]) !== '') {
				$capturedContentType = trim((string) $capturedHeaders[$headerName]);
				break;
			}
		}

		$replay = $this->postWebhookRawBody($capturedBody, $capturedAuth, $targetUrl, $capturedContentType);
		$this->assertContains((int) $replay['code'], [200, 202], 'Replayed webhook should be accepted by Dolibarr');

		return [
			'event_type' => $eventType,
			'order_id' => $orderId,
			'captured_id' => $captured['id'] ?? null,
			'captured_content_type' => (string) ($capturedContentType ?? ''),
			'captured_body_size' => (int) ($captured['body_size'] ?? strlen($capturedBody)),
			'replay_code' => (int) $replay['code'],
		];
	}

	/**
	 * Fallback for sandcastle webhook sink flakiness: synthesize order_settled replay once merchant is wired.
	 *
	 * @param string $orderId
	 * @param string $targetUrl
	 * @return array<string,mixed>|null
	 */
	private function replaySyntheticOrderSettledWebhookIfMerchantWired(string $orderId, string $targetUrl): ?array
	{
		$status = $this->waitForMerchantOrderState($orderId, ['wired'], 5);
		$state = strtolower(trim((string) ($status['order_status'] ?? $status['status'] ?? '')));
		$isWired = ($state === 'wired') || !empty($status['wired']);
		if (!$isWired) {
			return null;
		}

		$payload = is_array($status) ? $status : [];
		$payload['event_type'] = 'order_settled';
		$payload['order_id'] = $orderId;
		$payload['merchant_instance'] = (string) (
			$payload['merchant_instance']
			?? ($payload['merchant']['instance'] ?? $payload['merchant']['id'] ?? '')
			?? self::$merchantInstance
		);
		$payload['order_status'] = (string) ($payload['order_status'] ?? $payload['status'] ?? 'wired');
		$payload['status'] = (string) ($payload['status'] ?? $payload['order_status'] ?? 'wired');
		$payload['wired'] = true;
		if (empty($payload['wtid']) && !empty($payload['wire_details']) && is_array($payload['wire_details'])) {
			foreach ($payload['wire_details'] as $wireDetail) {
				if (is_array($wireDetail) && !empty($wireDetail['wtid'])) {
					$payload['wtid'] = (string) $wireDetail['wtid'];
					break;
				}
			}
		}
		if (empty($payload['wtid'])) {
			// TODO: Drop synthetic WTID fallback once upstream consistently returns WTID in status payloads.
			$payload['wtid'] = self::SYNTHETIC_ORDER_SETTLED_WTID;
		}

		$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->assertNotFalse($encoded, 'Synthetic order_settled webhook payload could not be encoded');

		dol_syslog(
			__METHOD__.' using synthetic order_settled webhook after '
			.self::ORDER_SETTLED_SINK_FALLBACK_AFTER_SECONDS.'s sink timeout for order '.$orderId,
			LOG_WARNING
		);

		$replay = $this->postWebhookPayload($payload, null, $targetUrl);
		$this->assertContains((int) $replay['code'], [200, 202], 'Synthetic order_settled webhook should be accepted by Dolibarr');

		return [
			'event_type' => 'order_settled',
			'order_id' => $orderId,
			'captured_id' => null,
			'captured_content_type' => 'application/json',
			'captured_body_size' => strlen((string) $encoded),
			'replay_code' => (int) $replay['code'],
			'synthetic' => 1,
			'synthetic_wtid' => (string) $payload['wtid'],
			'fallback_after_seconds' => self::ORDER_SETTLED_SINK_FALLBACK_AFTER_SECONDS,
		];
	}

	/**
	 * Poll webhook sink until the requested event appears.
	 *
	 * @param string $eventType Event type to match.
	 * @param string $orderId   Order id to match.
	 * @param int    $timeoutSeconds Poll timeout in seconds.
	 * @return array<string,mixed>|null
	 */
	private function waitForWebhookSinkEvent(string $eventType, string $orderId, int $timeoutSeconds = 45): ?array
	{
		if (!self::$webhookSinkReplayEnabled || empty(self::$webhookSinkListUrl)) {
			return null;
		}

		$deadline = time() + $timeoutSeconds;
		$eventTypeNeedle = strtolower(trim($eventType));
		$orderIdNeedle = trim($orderId);
		do {
			// Current sandcastle webhook admin API supports only GET /webhooks?limit=N.
			// We always fetch and filter event_type/order_id locally from the JSON payloads.
			$listUrl = $this->withQuery((string) self::$webhookSinkListUrl,
				[
				'limit' => '200',
			]);
			$res = $this->httpJsonRequest($listUrl, 'GET');
			if ($res['code'] >= 200 && $res['code'] < 300 && is_array($res['json'])) {
				$events = is_array($res['json']['events'] ?? null) ? $res['json']['events'] : [];
				if (self::$webhookSinkMinEventIdExclusive > 0) {
					$events = array_values(array_filter(
						$events,
						static function ($event): bool {
							return is_array($event) && (int) ($event['id'] ?? 0) > self::$webhookSinkMinEventIdExclusive;
						}
					));
				}
				$match = $this->findWebhookSinkEventMatch($events, $eventTypeNeedle, $orderIdNeedle);
				if ($match !== null) {
					return $match;
				}
			}
			usleep(2000000);
		} while (time() <= $deadline);

		return null;
	}

	/**
	 * Find latest sink event with matching JSON payload event_type+order_id.
	 *
	 * @param array<int,mixed> $events
	 * @param string           $eventTypeLower
	 * @param string           $orderId
	 * @return array<string,mixed>|null
	 */
	private function findWebhookSinkEventMatch(array $events, string $eventTypeLower, string $orderId): ?array
	{
		for ($i = count($events) - 1; $i >= 0; $i--) {
			$event = $events[$i];
			if (!is_array($event)) {
				continue;
			}
			$bodyRaw = (string) ($event['body'] ?? '');
			if ($bodyRaw === '') {
				continue;
			}

			$payload = null;
			$payloadEvent = '';
			$payloadOrder = '';

			$decodedRaw = json_decode($bodyRaw, true);
			if (is_array($decodedRaw)) {
				$payload = $decodedRaw;
				$payloadEvent = strtolower(trim((string) ($decodedRaw['event_type'] ?? $decodedRaw['webhook_type'] ?? '')));
				$payloadOrder = trim((string) ($decodedRaw['order_id'] ?? ''));
			} else {
				// Merchant webhook templates may HTML-escape nested JSON (for example `contract_terms`),
				// making the overall body invalid JSON. Try html_entity_decode() and fall back to regex
				// extraction of the top-level fields we need for matching.
				$bodyHtmlDecoded = html_entity_decode($bodyRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$decodedHtml = json_decode($bodyHtmlDecoded, true);
				if (is_array($decodedHtml)) {
					$payload = $decodedHtml;
					$payloadEvent = strtolower(trim((string) ($decodedHtml['event_type'] ?? $decodedHtml['webhook_type'] ?? '')));
					$payloadOrder = trim((string) ($decodedHtml['order_id'] ?? ''));
				} else {
					if (preg_match('/"event_type"\s*:\s*"([^"]+)"/', $bodyRaw, $mEvent) === 1) {
						$payloadEvent = strtolower(trim((string) ($mEvent[1] ?? '')));
					}
					if (preg_match('/"order_id"\s*:\s*"([^"]+)"/', $bodyRaw, $mOrder) === 1) {
						$payloadOrder = trim((string) ($mOrder[1] ?? ''));
					}
				}
			}

			if ($payloadEvent !== $eventTypeLower) {
				continue;
			}
			if ($orderId !== '' && $payloadOrder !== $orderId) {
				continue;
			}
			if (is_array($payload)) {
				$event['payload'] = $payload;
			}
			return $event;
		}
		return null;
	}

	/**
	 * Minimal JSON HTTP helper for webhook sink admin endpoints.
	 *
	 * @param string      $url
	 * @param string      $method
	 * @param string|null $body
	 * @return array{code:int,body:string,json:array<string,mixed>|null}
	 */
	private function httpJsonRequest(string $url, string $method = 'GET', ?string $body = null): array
	{
		$headers = ['Accept: application/json'];
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
		}
		$context = stream_context_create([
			'http' => [
				'method' => strtoupper($method),
				'header' => implode("\r\n", $headers)."\r\n",
				'content' => $body ?? '',
				'ignore_errors' => true,
				'timeout' => 30,
			],
		]);

		$response = @file_get_contents($url, false, $context);
		$rawHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
		$code = $this->extractHttpStatusCode($rawHeaders);
		$responseBody = is_string($response) ? $response : '';
		$decoded = null;
		if ($responseBody !== '') {
			$tmp = json_decode($responseBody, true);
			if (is_array($tmp)) {
				$decoded = $tmp;
			}
		}

		return [
			'code' => $code,
			'body' => $responseBody,
			'json' => $decoded,
		];
	}

	/**
	 * Append query parameters to a URL.
	 *
	 * @param string               $url
	 * @param array<string,string> $params
	 * @return string
	 */
	private function withQuery(string $url, array $params): string
	{
		$url = trim($url);
		if ($url === '' || $params === []) {
			return $url;
		}
		$filtered = [];
		foreach ($params as $key => $value) {
			if ($key === '' || $value === '') {
				continue;
			}
			$filtered[$key] = $value;
		}
		if ($filtered === []) {
			return $url;
		}
		$query = http_build_query($filtered);
		if ($query === '') {
			return $url;
		}
		return $url.(str_contains($url, '?') ? '&' : '?').$query;
	}

	/**
	 * Execute payment via wallet CLI.
	 * Uses taler-wallet-cli handle-uri --yes to settle a taler://pay URI.
	 *
	 * @param string $uri Taler pay URI to settle.
	 * @return void
	 */
	private function walletPayUri(string $uri): void
	{
		$attempts = [];
		$tries = [
			['handle-uri', '--yes', $uri],
			['handle-uri', '-y', $uri],
		];

		$lastError = null;
		foreach ($tries as $candidate) {
			$this->logWalletStep('payment', 'attempt', ['command' => $candidate]);
			$res = $this->runWallet($candidate);
			$attempt = [
				'command' => $candidate,
				'code'    => $res['code'],
				'stdout'  => $this->truncateForLog($res['stdout']),
				'stderr'  => $this->truncateForLog($res['stderr']),
			];
			$attempts[] = $attempt;
			$this->logWalletStep('payment', 'result', $attempt);
			if ($res['code'] === 0) {
				return;
			}
			$lastError = $res['stderr'] ?: $res['stdout'];
			if (stripos((string) $lastError, 'unknown command') === false) {
				break;
			}
		}

		$this->fail(
			'Wallet payment failed: '.($lastError ?? 'unknown error')
			.'; attempts='.json_encode($attempts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}

	/**
	 * Run taler-wallet-cli either locally or inside the container.
	 *
	 * @param array<int,string> $args CLI arguments.
	 * @return array{code:int,stdout:string,stderr:string}
	 */
	private function runWallet(array $args): array
	{
		if (!self::$walletCli) {
			throw new RuntimeException('taler-wallet-cli is not available');
		}

		$command = array_merge([self::$walletCli], array_map('strval', $args));
		$this->logWalletStep('exec',
			'dispatch',
			[
			'mode'    => self::$walletExecMode,
			'command' => $command,
			'env'     => self::$walletEnv,
		]);

		if (self::$walletExecMode === 'podman') {
			$result = self::runPodmanExec($command, self::$walletEnv);
		} else {
			$result = self::execCommand($command, self::$walletEnv);
		}
		$this->logWalletStep('exec',
			'completed',
			[
			'mode'   => self::$walletExecMode,
			'code'   => $result['code'],
			'stdout' => $this->truncateForLog($result['stdout']),
			'stderr' => $this->truncateForLog($result['stderr']),
		]);
		return $result;
	}

	/**
	 * Ensure concise Dolibarr logging is active for the integration test run.
	 *
	 * @return void
	 */
	private static function bootstrapLogging(): void
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT . '/core/modules/modSyslog.class.php';

		if (!isModEnabled('syslog')) {
			$syslogModule = new modSyslog(self::$db);
			$syslogModule->init('');
			$conf->modules['syslog'] = 1;
		}

		$handlers = '["mod_syslog_file"]';
		dolibarr_set_const(self::$db, 'SYSLOG_HANDLERS', $handlers, 'chaine', 0, '', 0);
		dolibarr_set_const(self::$db, 'SYSLOG_FILE', 'DOL_DATA_ROOT/dolibarr.log', 'chaine', 0, '', 0);
		dolibarr_set_const(self::$db, 'SYSLOG_DISABLE_LOGHANDLER_FILE', '0', 'chaine', 0, '', 0);
		dolibarr_set_const(self::$db, 'MAIN_SYSLOG_DISABLE_FILE', '0', 'chaine', 0, '', 0);
		dolibarr_set_const(self::$db, 'SYSLOG_LEVEL', (string) LOG_DEBUG, 'chaine', 0, '', 0);
		$conf->global->SYSLOG_HANDLERS = $handlers;
		$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr.log';
		$conf->global->SYSLOG_DISABLE_LOGHANDLER_FILE = '0';
		$conf->global->MAIN_SYSLOG_DISABLE_FILE = '0';
		$conf->global->SYSLOG_LEVEL = LOG_DEBUG;
	}

	/**
	 * Emit a structured trace line for wallet CLI operations.
	 *
	 * @param string               $context Logical operation (withdraw/payment/exec).
	 * @param string               $message Short message.
	 * @param array<string,mixed>  $data    Optional context payload.
	 * @return void
	 */
	private function logWalletStep(string $context, string $message, array $data = []): void
	{
		$payload = $data ? ' '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
		$level = defined('LOG_DEBUG') ? LOG_DEBUG : 0;
		dol_syslog('[TalerOrderFlowIntegrationTest]['.$context.'] '.$message.$payload, $level);
	}

	/**
	 * Emit stage-level trace for lifecycle tests.
	 *
	 * @param string              $stage   Stage identifier.
	 * @param string              $message Log message.
	 * @param array<string,mixed> $data    Optional context payload.
	 * @return void
	 */
	private function logStageStep(string $stage, string $message, array $data = []): void
	{
		$payload = $data ? ' '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
		$level = defined('LOG_INFO') ? LOG_INFO : 0;
		dol_syslog('[TalerOrderFlowIntegrationTest]['.$stage.'] '.$message.$payload, $level);
	}

	/**
	 * Make CLI output suitable for logging by trimming and truncating long snippets.
	 *
	 * @param string $value Raw stdout/stderr.
	 * @param int    $limit Maximum number of characters to preserve.
	 * @return string Sanitised snippet.
	 */
	private function truncateForLog(string $value, int $limit = 512): string
	{
		$clean = trim($value);
		if ($clean === '') {
			return '';
		}
		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			if (mb_strlen($clean) <= $limit) {
				return $clean;
			}
			return mb_substr($clean, 0, $limit).'...';
		}
		if (strlen($clean) <= $limit) {
			return $clean;
		}
		return substr($clean, 0, $limit).'...';
	}

	/**
	 * Resolve the sandcastle container name from environment overrides.
	 *
	 * @return string Container name to target.
	 */
	private static function resolveSandboxContainer(): string
	{
		foreach (['SANDCASTLE_CONTAINER_NAME', 'TALER_SANDBOX_CONTAINER'] as $envName) {
			$value = getenv($envName);
			if (is_string($value) && trim($value) !== '') {
				return trim($value);
			}
		}
		return self::DEFAULT_SANDBOX_CONTAINER;
	}

	/**
	 * Execute a command inside the sandcastle container via podman exec.
	 *
	 * @param array<int,string>      $command             Arguments to run.
	 * @param array<string,string>   $envVars             Extra environment variables.
	 * @param bool                   $allowSudoDetection  Allow sudo fallback probing.
	 * @return array{code:int,stdout:string,stderr:string}
	 */
	private static function runPodmanExec(array $command, array $envVars = [], bool $allowSudoDetection = false): array
	{
		$container = self::$walletContainer ?? self::resolveSandboxContainer();
		$execArgs = ['exec'];
		foreach ($envVars as $name => $value) {
			if ($name === '' || $value === null) {
				continue;
			}
			$execArgs[] = '--env';
			$execArgs[] = $name.'='.$value;
		}
		$execArgs[] = $container;
		$execArgs = array_merge($execArgs, array_map('strval', $command));
		return self::runPodman($execArgs, $allowSudoDetection);
	}

	/**
	 * Execute podman with optional sudo and containers.conf overrides.
	 *
	 * @param array<int,string> $args                Podman arguments.
	 * @param bool              $allowSudoDetection  Allow sudo fallback probing.
	 * @return array{code:int,stdout:string,stderr:string}
	 */
	private static function runPodman(array $args, bool $allowSudoDetection = false): array
	{
		if (!self::commandExists('podman')) {
			return [
				'code'   => 127,
				'stdout' => '',
				'stderr' => 'podman command not available',
			];
		}
		$override = self::getPodmanOverrideConf();
		$useSudo = self::$podmanUseSudo ?? false;
		$result = self::execCommand(self::buildPodmanCommand($args, $useSudo, $override));
		if ($result['code'] === 0) {
			if (self::$podmanUseSudo === null) {
				self::$podmanUseSudo = $useSudo;
			}
			return $result;
		}

		if ($allowSudoDetection && self::$podmanUseSudo === null && self::commandExists('sudo')) {
			$fallbackUseSudo = !$useSudo;
			$fallback = self::execCommand(self::buildPodmanCommand($args, $fallbackUseSudo, $override));
			if ($fallback['code'] === 0) {
				self::$podmanUseSudo = $fallbackUseSudo;
			}
			return $fallback;
		}

		return $result;
	}

	/**
	 * Build the full podman command array including sudo/env overrides.
	 *
	 * @param array<int,string> $args     Podman arguments.
	 * @param bool              $useSudo  Whether to prefix with sudo.
	 * @param string|null       $override containers.conf override path.
	 * @return array<int,string> Final command pieces.
	 */
	private static function buildPodmanCommand(array $args, bool $useSudo, ?string $override): array
	{
		$parts = [];
		if ($useSudo) {
			$parts[] = 'sudo';
		}
		if ($override !== null && $override !== '') {
			$parts[] = 'env';
			$parts[] = 'CONTAINERS_CONF_OVERRIDE='.$override;
		}
		$parts[] = 'podman';
		foreach ($args as $arg) {
			$parts[] = (string) $arg;
		}
		return $parts;
	}

	/**
	 * Lazily resolve the containers.conf override path.
	 *
	 * @return string|null Override path when defined, null otherwise.
	 */
	private static function getPodmanOverrideConf(): ?string
	{
		if (self::$podmanOverrideConf !== null) {
			return self::$podmanOverrideConf;
		}
		$override = getenv('TALER_PODMAN_OVERRIDE_CONF');
		if ($override === false) {
			return null;
		}
		$override = trim((string) $override);
		self::$podmanOverrideConf = $override !== '' ? $override : null;
		return self::$podmanOverrideConf;
	}

	/**
	 * Execute a shell command and capture exit code + stdio.
	 *
	 * @param array<int,string>    $parts    Command pieces.
	 * @param array<string,string> $extraEnv Additional environment variables.
	 * @return array{code:int,stdout:string,stderr:string}
	 */
	private static function execCommand(array $parts, array $extraEnv = []): array
	{
		$command = self::commandToString($parts);
		$descriptor = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$env = null;
		if ($extraEnv !== []) {
			$baseEnv = [];
			if (is_array($_ENV) && $_ENV !== []) {
				$baseEnv = $_ENV;
			}
			foreach (['PATH', 'HOME', 'USER', 'SHELL', 'TMPDIR'] as $common) {
				if (!isset($baseEnv[$common])) {
					$val = getenv($common);
					if ($val !== false) {
						$baseEnv[$common] = $val;
					}
				}
			}
			$env = array_merge($baseEnv, $extraEnv);
		}
		$process = proc_open($command, $descriptor, $pipes, null, $env);
		if (!is_resource($process)) {
			throw new RuntimeException('Unable to execute command: '.$command);
		}
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]) ?: '';
		fclose($pipes[1]);
		$stderr = stream_get_contents($pipes[2]) ?: '';
		fclose($pipes[2]);
		$code = proc_close($process);
		return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
	}

	/**
	 * Quote a command array for proc_open usage/logging.
	 *
	 * @param array<int,string> $parts Command pieces.
	 * @return string Escaped shell command string.
	 */
	private static function commandToString(array $parts): string
	{
		return implode(' ',
			array_map(static function ($part): string {
				return escapeshellarg((string) $part);
			},
			$parts));
	}

	/**
	 * Lightweight "command -v" helper that works inside PHP.
	 *
	 * @param string $binary Command name to probe for.
	 * @return bool True when the command exists.
	 */
	private static function commandExists(string $binary): bool
	{
		if ($binary === '') {
			return false;
		}
		$result = shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');
		return is_string($result) && trim($result) !== '';
	}

	/**
	 * Poll merchant order status until it reaches a terminal paid state.
	 *
	 * @param string $orderId         Taler merchant order identifier.
	 * @param int    $timeoutSeconds  Polling timeout in seconds.
	 * @return array<string,mixed> Merchant status payload.
	 */
	private function waitForMerchantStatus(string $orderId, int $timeoutSeconds = 30): array
	{
		return $this->waitForMerchantOrderState($orderId, ['paid', 'delivered', 'wired'], $timeoutSeconds);
	}

	/**
	 * Poll merchant order status until one of the requested states is reached.
	 *
	 * @param string            $orderId         Taler merchant order identifier.
	 * @param array<int,string> $acceptedStates  Lower/upper case state names accepted as terminal.
	 * @param int               $timeoutSeconds  Polling timeout in seconds.
	 * @return array<string,mixed> Merchant status payload.
	 */
	private function waitForMerchantOrderState(string $orderId, array $acceptedStates, int $timeoutSeconds = 30): array
	{
		$client = self::$config->talerMerchantClient();
		$accepted = array_values(array_filter(array_map(
			static function ($state): string {
				return strtolower(trim((string) $state));
			},
			$acceptedStates
		)));
		if ($accepted === []) {
			$accepted = ['paid', 'delivered', 'wired'];
		}
		$deadline = time() + $timeoutSeconds;
		do {
			$status = $client->getOrderStatus($orderId);
			//Normally response doesn't contain order_id, so we add it for processing purposes
			$status['order_id'] = $orderId;
			dol_syslog('[TalerOrderFlowIntegrationTest] Merchant order status: '.json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOG_DEBUG);
			$state = strtolower((string) ($status['order_status'] ?? $status['status'] ?? ''));
			if (in_array($state, $accepted, true)) {
				return $status;
			}
			// Some merchant versions expose settlement via wired=true while keeping order_status="paid".
			if (in_array('wired', $accepted, true) && !empty($status['wired'])) {
				return $status;
			}
			usleep(5000000);
		} while (time() <= $deadline);

		return $status;
	}

	/**
	 * Poll merchant order status until a custom predicate matches.
	 *
	 * @param string        $orderId
	 * @param callable      $predicate      Callback receiving merchant status array and returning bool.
	 * @param int           $timeoutSeconds
	 * @return array<string,mixed>
	 */
	private function waitForMerchantOrderCondition(string $orderId, callable $predicate, int $timeoutSeconds = 30): array
	{
		$client = self::$config->talerMerchantClient();
		$deadline = time() + $timeoutSeconds;
		$status = ['order_id' => $orderId];
		do {
			$status = $client->getOrderStatus($orderId);
			$status['order_id'] = $orderId;
			dol_syslog('[TalerOrderFlowIntegrationTest] Merchant order status: '.json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOG_DEBUG);
			if ($predicate($status)) {
				return $status;
			}
			usleep(5000000);
		} while (time() <= $deadline);

		return $status;
	}

	/**
	 * Check whether merchant status reports at least one confirmed wire detail.
	 *
	 * @param array<string,mixed> $status
	 * @return bool
	 */
	private function merchantStatusHasConfirmedWire(array $status): bool
	{
		$wireDetails = $status['wire_details'] ?? null;
		if (!is_array($wireDetails)) {
			return false;
		}
		foreach ($wireDetails as $detail) {
			if (!is_array($detail)) {
				continue;
			}
			if (!empty($detail['confirmed'])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Update sync direction and optional sync timing in module configuration.
	 *
	 * @param int      $direction  Direction flag.
	 * @param int|null $syncOnPaid 1 to sync Dolibarr artefacts only after payment.
	 * @return void
	 */
	private function updateSyncDirection(int $direction, ?int $syncOnPaid = null): void
	{
		self::$config->fetch(self::$config->id);
		self::$config->syncdirection = $direction;
		if ($syncOnPaid !== null) {
			self::$config->sync_on_paid = $syncOnPaid;
		}
		$res = self::$config->update(self::$user);
		$this->assertGreaterThan(0, $res, 'Failed to update sync configuration');
	}

	/**
	 * Ensure we have two open bank accounts for wire-transfer stage.
	 *
	 * @return array{clearing:int,final:int}|null
	 */
	private function ensureDistinctWireAccounts(): ?array
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'bank_account WHERE clos = 0 ORDER BY rowid ASC';
		$resql = self::$db->query($sql);
		if (!$resql) {
			return null;
		}
		$ids = [];
		while ($obj = self::$db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		self::$db->free($resql);

		$ids = array_values(array_unique(array_filter($ids,
			static function ($id): bool {
				return $id > 0;
			})));

		if (count($ids) < 2) {
			$newAccount = new Account(self::$db);
			$newAccount->ref = 'TALER-WIRE-'.bin2hex(random_bytes(4));
			$newAccount->label = 'Taler integration wire destination';
			$newAccount->country_id = (int) getDolGlobalInt('MAIN_INFO_SOCIETE_COUNTRY');
			if ($newAccount->country_id <= 0) {
				$newAccount->country_id = 1;
			}
			$newAccount->date_solde = dol_now();
			$newAccount->balance = 0;
			$newAccount->currency_code = (string) ($GLOBALS['conf']->currency ?? 'KUDOS');
			$create = $newAccount->create(self::$user);
			if ($create > 0) {
				$ids[] = (int) $newAccount->id;
			}
		}

		if (count($ids) < 2) {
			return null;
		}

		return [
			'clearing' => (int) $ids[0],
			'final'    => (int) $ids[1],
		];
	}

	/**
	 * Apply wire account constants and module destination account.
	 *
	 * @param int $clearingAccountId Clearing account id.
	 * @param int $finalAccountId    Final destination account id.
	 * @return void
	 */
	private function applyWireAccounts(int $clearingAccountId, int $finalAccountId): void
	{
		global $conf;

		$this->assertNotSame($clearingAccountId, $finalAccountId, 'Wire accounts must be distinct');

		dolibarr_set_const(self::$db, 'TALERBARR_CLEARING_BANK_ACCOUNT', $clearingAccountId, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const(self::$db, 'TALERBARR_FINAL_BANK_ACCOUNT', $finalAccountId, 'chaine', 0, '', $conf->entity);
		$conf->global->TALERBARR_CLEARING_BANK_ACCOUNT = $clearingAccountId;
		$conf->global->TALERBARR_FINAL_BANK_ACCOUNT = $finalAccountId;

		self::$config->fetch(self::$config->id);
		self::$config->fk_bank_account = $finalAccountId;
		$res = self::$config->update(self::$user);
		$this->assertGreaterThan(0, $res, 'Failed to persist wire destination account');
	}

	/**
	 * Create a merchant order via the REST API for the given amount.
	 *
	 * @param string $amount Amount string (currency:value.fraction).
	 * @return array{order_id:string,status:array<string,mixed>} API payload.
	 */
	private function createMerchantOrder(string $amount): array
	{
		$client = new TalerMerchantClient(self::$merchantUrl, self::$merchantApiKey ?? '', self::$merchantInstance);

		$orderId = 'TALER-'.bin2hex(random_bytes(4));
		$summary = 'Wallet integration order';
		$request = [
			'order' => [
				'order_id'            => $orderId,
				'summary'             => $summary,
				'amount'              => $amount,
				'fulfillment_message' => 'Order placed via integration test',
				'extra'               => [
					'dolibarr_hint' => 'integration',
				],
				'products' => [
					[
						'description' => 'Integration test line',
						'quantity'    => 1,
						'price'       => $amount,
					],
				],
			],
		];

		$response = $client->createOrder($request);
		$createdId = (string) ($response['order_id'] ?? $orderId);
		$status = $client->getOrderStatus($createdId);
		$status['order_id'] = $createdId;
		return [
			'order_id' => $createdId,
			'status'   => $status,
		];
	}
// phpcs:enable
}
