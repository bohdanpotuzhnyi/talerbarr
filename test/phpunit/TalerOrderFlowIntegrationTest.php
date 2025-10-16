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
 *  - TALER_EXCHANGE_URL                       → Exchange URL (default: http://127.0.0.1:16001/)
 *  - TALER_BANK_URL                           → Bank URL (default: http://127.0.0.1:16007/)
 *  - TALER_MERCHANT_URL                       → Merchant URL (default: http://127.0.0.1:16000/)
 *  - TALER_MERCHANT_API_KEY                   → OAuth token for the merchant instance
 *  - TALER_INSTANCE                           → Merchant instance name (default: "sandbox")
 * Optional helpers when sandcastle-ng is used:
 *  - SANDCASTLE_CONTAINER_NAME / TALER_SANDBOX_CONTAINER → Podman container name (default: taler-sandcastle)
 *  - TALER_PODMAN_OVERRIDE_CONF                          → Path to containers.conf override (see CI tooling)
 *  - TALER_PODMAN_USE_SUDO                               → Force/disable sudo when invoking podman (default: auto)
 */

global $conf, $user, $db, $langs;

require_once dirname(__FILE__, 6) . '/htdocs/master.inc.php';
require_once dirname(__FILE__, 6) . '/test/phpunit/CommonClassTest.class.php';

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
	private const DEFAULT_EXCHANGE_URL = 'http://127.0.0.1:16001/';
	private const DEFAULT_BANK_URL = 'http://127.0.0.1:16007/';
	private const DEFAULT_MERCHANT_URL = 'http://127.0.0.1:16000/';
	private const DEFAULT_MERCHANT_TOKEN = 'secret-token:sandbox';
	private const DEFAULT_INSTANCE = 'sandbox';
	private const DEFAULT_SANDBOX_CONTAINER = 'taler-sandcastle';

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
	private static ?string $merchantUrl = null;
	private static ?string $merchantApiKey = null;
	private static string $merchantInstance = 'sandbox';
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
		self::$merchantUrl = self::ensureEnvUrl('TALER_MERCHANT_URL', self::DEFAULT_MERCHANT_URL);
		self::$merchantApiKey = self::ensureEnv('TALER_MERCHANT_API_KEY', self::DEFAULT_MERCHANT_TOKEN);
		self::$merchantInstance = self::ensureEnv('TALER_INSTANCE', self::DEFAULT_INSTANCE);

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
	 * Full Dolibarr ↔ Taler happy-path lifecycle covering both sync directions.
	 *
	 * @return void
	 */
	public function testCompleteOrderLifecycle(): void
	{
		// Stage 1: Create order within Dolibarr and push it to Taler
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

		//NORMALLY THIS IS NOT NEEDED, as the taler will communicate with webhook to merchant, so we just need to wait
		$rcPayment = TalerOrderLink::upsertFromTalerOfPayment(self::$db, $statusPaid, self::$user);
		$this->assertSame(1, $rcPayment, 'Payment sync should succeed');

		$linkPaid = $this->refetchLink((int) $link->id);
		$this->assertNotEmpty($linkPaid->fk_facture, 'Invoice should be linked after payment');

		$invoice = new Facture(self::$db);
		$invoiceFetch = $invoice->fetch((int) $linkPaid->fk_facture);
		$this->assertGreaterThan(0, $invoiceFetch, 'Invoice record must exist');
		$this->assertGreaterThanOrEqual(Facture::STATUS_VALIDATED, (int) $invoice->status, 'Invoice expected to be validated');

		// Stage 2: Flip sync direction and originate order on Taler
		$this->updateSyncDirection(1);

		$orderFromTaler = $this->createMerchantOrder(self::TALER_ORDER_AMOUNT);
		$this->assertArrayHasKey('status', $orderFromTaler);
		$this->assertArrayHasKey('contract_terms', $orderFromTaler['status']);

		$rcCreation = TalerOrderLink::upsertFromTalerOnOrderCreation(
			self::$db,
			$orderFromTaler['status'],
			self::$user,
			$orderFromTaler['status']['contract_terms']
		);
		$this->assertSame(1, $rcCreation, 'Order creation sync should succeed');

		$linkFromTaler = $this->fetchLinkByOrderId($orderFromTaler['status']['order_id']);
		$this->assertNotNull($linkFromTaler, 'Order link missing for Taler-origin order');

		// Ensure Dolibarr order gets materialised via payment synchronisation
		$payUri2 = $orderFromTaler['status']['taler_pay_uri'] ?? $orderFromTaler['status']['pay_url'] ?? '';
		$this->assertNotSame('', $payUri2, 'Merchant status must expose a pay URI');
		$this->walletPayUri($payUri2);
		$statusPaid2 = $this->waitForMerchantStatus((string) $orderFromTaler['status']['order_id'], 45);
		$this->assertNotEmpty($statusPaid2);

		$paymentRc2 = TalerOrderLink::upsertFromTalerOfPayment(self::$db, $statusPaid2, self::$user);
		$this->assertSame(1, $paymentRc2, 'Second payment sync should succeed');

		$linkPaid2 = $this->refetchLink((int) $linkFromTaler->id);
		$this->assertNotEmpty($linkPaid2->fk_commande, 'Dolibarr order expected after payment sync');
		$this->assertNotEmpty($linkPaid2->fk_facture, 'Invoice expected after payment sync');
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
	 * @return Commande Newly created order instance.
	 */
	private function createDolibarrOrder(): Commande
	{
		$cmd = new Commande(self::$db);
		$cmd->socid = self::$customer->id;
		$cmd->date = dol_now();
		$cmd->entity = self::$customer->entity;
		$cmd->cond_reglement_id = 1;
		$cmd->mode_reglement_id = 1;
		$cmd->multicurrency_code = 'KUDOS';

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
				'--wait',
			],
			[
				'testing', 'withdraw-testkudos',
				'--wait',
			],
			[
				'withdraw', 'manual',
				'--exchange', self::$exchangeUrl,
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
				return;
			}
			$lastError = $res['stderr'] ?: $res['stdout'];
			if (stripos((string) $lastError, 'unknown command') === false) {
				break;
			}
		}

		$this->fail(
			'Wallet withdraw failed: '.($lastError ?? 'unknown error')
			.'; attempts='.json_encode($attempts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
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
			} else {
				foreach (['PATH', 'HOME', 'USER', 'SHELL', 'TMPDIR'] as $common) {
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
		$client = self::$config->talerMerchantClient();
		$deadline = time() + $timeoutSeconds;
		do {
			$status = $client->getOrderStatus($orderId);
			//Normally response doesn't contain order_id, so we add it for processing purposes
			$status['order_id'] = $orderId;
			dol_syslog('[TalerOrderFlowIntegrationTest] Merchant order status: '.json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOG_DEBUG);
			$state = strtolower((string) ($status['order_status'] ?? ''));
			if (in_array($state, ['paid', 'delivered', 'wired'], true)) {
				return $status;
			}
			usleep(5000000);
		} while (time() <= $deadline);

		return $status;
	}

	/**
	 * Update the config sync direction flag.
	 *
	 * @param int $direction Direction flag.
	 * @return void
	 */
	private function updateSyncDirection(int $direction): void
	{
		self::$config->fetch(self::$config->id);
		self::$config->syncdirection = $direction;
		self::$config->update(self::$user);
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
		return [
			'order_id' => $createdId,
			'status'   => $status,
		];
	}
}
