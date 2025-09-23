<?php
/* Copyright (C) 2025       Bohdan Potuzhnyi
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *      \file       htdocs/custom/talerbarr/test/phpunit/TalerProductLinkTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for TalerBarr synchronisation
 *      \remarks    Run as CLI: phpunit TalerProductLinkTest.php
 */

global $conf, $user, $db, $langs;

require_once dirname(__FILE__, 6).'/htdocs/master.inc.php';
require_once dirname(__FILE__, 6).'/test/phpunit/CommonClassTest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/talerbarr/core/modules/modTalerBarr.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/talerbarr/class/talerproductlink.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/talerbarr/class/talerconfig.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php'; // needed to activate the module

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}


if (!class_exists('TalerMerchantClient')) {
	print "TalerMerchantClient is missing; creating a fake one\n";

	/**
	 * Very small in-memory fake that honours only the methods the test needs.
	 */
	class TalerMerchantClient
	{
		/** @var array<string,array> keyed by product_id */
		private static array $store = [];

		/**
		 * @param string $baseUrl ignored
		 * @param string $token   ignored
		 */
		public function __construct(string $baseUrl, string $token)
		{
			/* no-op */
		}

		/**
		 * Simulate POST /products
		 *
		 * @param array $detail ProductDetail with mandatory product_id
		 * @throws Exception on duplicate product_id
		 * @return void
		 */
		public function addProduct(array $detail): void
		{
			if (empty($detail['product_id'])) {
				throw new Exception('Missing product_id');
			}
			if (isset(self::$store[$detail['product_id']])) {
				throw new Exception('HTTP 409');          // emulate conflict
			}
			self::$store[$detail['product_id']] = $detail;
		}

		/**
		 * Simulate PATCH /products/{id}
		 *
		 * @param string $pid    product_id
		 * @param array  $detail fields to merge
		 * @throws Exception if product unknown
		 * @return void
		 */
		public function updateProduct(string $pid, array $detail): void
		{
			if (!isset(self::$store[$pid])) {
				throw new Exception('HTTP 404');
			}
			self::$store[$pid] = array_merge(self::$store[$pid], $detail);
		}

		/**
		 * Simulate GET /products/{id}
		 *
		 * @param string $pid product_id
		 * @throws Exception if product unknown
		 * @return array
		 */
		public function getProduct(string $pid): array
		{
			if (!isset(self::$store[$pid])) {
				throw new Exception('HTTP 404');
			}
			return self::$store[$pid];
		}

		/**
		 * Simulate DELETE /products/{id}
		 *
		 * @param string $pid product_id
		 * @return void
		 */
		public function deleteProduct(string $pid): void
		{
			unset(self::$store[$pid]);
		}
	}
}

/* --------------------------------------------------------------------------
 * 4. PHPUnit test-case
 * ------------------------------------------------------------------------ */

/**
 * Functional tests for TalerProductLink synchronisation helpers.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 * @remarks backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class TalerProductLinkTest extends CommonClassTest
{
	/** @var DoliDB|null */
	private static ?DoliDB $db = null;
	/** @var User|null */
	private static ?User $user = null;
	/** @var TalerConfig|null */
	private static ?TalerConfig $cfg = null;

	/** @var string|null Taler instance name used for this run */
	private static ?string $instance = null;
	/** @var int|null Rowid of the specimen Dolibarr product we create */
	private static ?int $prodId = null;

	/* ---------- helpers -------------------------------------------------- */

	/**
	 * Produce a short random hex-string – handy for unique refs & IDs.
	 *
	 * @param int $len bytes of entropy (hex length will be $len*2)
	 * @return string
	 * @throws \Random\RandomException
	 */
	private static function randstr(int $len = 6): string
	{
		return substr(bin2hex(random_bytes($len)), 0, $len);
	}

	/**
	 * Print last SQL + DB error (for debugging).
	 *
	 * @param string $prefix Context string
	 * @return void
	 */
	private static function printDbStatus(string $prefix = ''): void
	{
		if (!self::$db) return;
		$lastsql = method_exists(self::$db, 'lastquery') ? self::$db->lastquery() : '';
		$lasterr = method_exists(self::$db, 'lasterror') ? self::$db->lasterror() : '';
		print $prefix." lastsql=".($lastsql ?: '(none)')." lasterr=".($lasterr ?: '(none)')."\n";
	}

	/* ---------- global fixtures ----------------------------------------- */

	/**
	 * Prepare module + config row, open transaction.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $conf, $user, $db;

		print __METHOD__." START\n";

		self::$db   = $db;
		self::$user = $user;

		print __METHOD__." Entity=".$conf->entity." UserId=".$user->id." Prefix=".MAIN_DB_PREFIX."\n";

		// 0) init module (enable flags, menus, cron, etc.)
		print __METHOD__." init modTalerBarr ...\n";
		$mod = new modTalerBarr($db);
		$rcInit = $mod->init('');
		print __METHOD__." modTalerBarr::init rc=".$rcInit."\n";
		if ($rcInit <= 0) {
			self::printDbStatus(__METHOD__." INIT ERR");
			throw new RuntimeException('Unable to init modTalerBarr');
		}
		$conf->modules[$mod->numero] = get_class($mod);

		// 1) ensure SQL tables are there (use the same installer as admin UI)
		self::ensureTalerTables();

		// 2) only now create the config row
		self::$instance              = getenv('TALER_USER') ?: 'phpunit-ci';
		self::$cfg                   = new TalerConfig($db);
		self::$cfg->entity           = $conf->entity;
		self::$cfg->talermerchanturl = getenv('TALER_BASEURL') ?: 'http://stub.local/';
		self::$cfg->talertoken       = getenv('TALER_TOKEN')   ?: 'dummy';
		self::$cfg->username         = self::$instance;
		self::$cfg->syncdirection    = 0;
		self::$cfg->verification_ok  = 1;
		self::$cfg->verification_ts  = dol_now();
		self::$cfg->fk_bank_account  = 1;

		$cfgId = self::$cfg->create($user);
		print __METHOD__." TalerConfig::create rc=".$cfgId."\n";
		if ($cfgId <= 0) {
			self::printDbStatus(__METHOD__." TALERCFG ERR");
			print __METHOD__." cfg->error=".self::$cfg->error."\n";
		}
		self::assertGreaterThan(0, $cfgId, 'Failed to create TalerConfig');

		// 3) single transaction around everything
		print __METHOD__." BEGIN TRANSACTION\n";
		$db->begin();

		print __METHOD__." END\n";
	}

	/* ---------- 1)  push flow: Dolibarr → Taler -------------------------- */

	/**
	 * Creates a specimen Product then calls upsertFromDolibarr().
	 *
	 * @return void
	 */
	public function testUpsertFromDolibarr(): void
	{
		print __METHOD__." START\n";

		// (1) create Product
		$prod = new Product(self::$db);
		$prod->initAsSpecimen();
		$prod->ref        = 'PHPUNIT-'.self::randstr();
		$prod->price_ttc  = 42.00;
		$prod->status     = 1;
		$prod->status_buy = 1;
		print __METHOD__." product ref=".$prod->ref." price_ttc=".$prod->price_ttc."\n";

		$rcCreate = $prod->create(self::$user);
		print __METHOD__." Product::create rc=".$rcCreate." id=".$prod->id."\n";
		if ($rcCreate <= 0) {
			self::printDbStatus(__METHOD__." PROD CREATE ERR");
			print __METHOD__." prod->error=".($prod->error ?? '')."\n";
		}
		$this->assertGreaterThan(0, $rcCreate);
		self::$prodId = (int) $prod->id;

		// (2) call helper
		print __METHOD__." call upsertFromDolibarr\n";
		$rc = TalerProductLink::upsertFromDolibarr(self::$db, $prod, self::$user, self::$cfg);
		print __METHOD__." upsertFromDolibarr rc=".$rc."\n";
		if ($rc !== 1) {
			self::printDbStatus(__METHOD__." UPSERT_D2T ERR");
			$link = new TalerProductLink(self::$db);
			$link->fetchByProductId($prod->id);
			print __METHOD__." link->error=".($link->error ?? '')."\n";
		}
		$this->assertSame(1, $rc, 'upsertFromDolibarr returned');

		// (3) verify link
		$link = new TalerProductLink(self::$db);
		$rcLink = $link->fetchByProductId($prod->id);
		print __METHOD__." link->fetch rc=".$rcLink." taler_product_id=".$link->taler_product_id."\n";
		$this->assertGreaterThan(0, $rcLink);

		print __METHOD__." END\n";
	}

	/* ---------- 2)  pull flow: Taler → Dolibarr -------------------------- */

	/**
	 * Import a product from Taler then verify Dolibarr state.
	 *
	 * @depends testUpsertFromDolibarr
	 *
	 * @return void
	 * @throws \Random\RandomException
	 */
	public function testUpsertFromTaler(): void
	{
		print __METHOD__." START\n";

		$priceNew = 99.99;
		$detail = [
			'product_id'   => 'phpunit-'.self::randstr(),
			'product_name' => 'Imported from Merchant',
			'description'  => 'pull-flow test',
			'price'        => TalerProductLink::talerAmountFromFloat($priceNew, 'EUR'),
			'total_stock'  => 123,
			'categories'   => [],
			'taxes'        => [],
		];
		print __METHOD__." taler_pid=".$detail['product_id']." price=".$priceNew."\n";

		$rc = TalerProductLink::upsertFromTaler(self::$db, $detail, self::$user, ['instance' => self::$instance]);
		print __METHOD__." upsertFromTaler rc=".$rc."\n";
		if ($rc !== 1) {
			self::printDbStatus(__METHOD__." UPSERT_T2D ERR");
		}
		$this->assertSame(1, $rc, 'upsertFromTaler should return 1');

		// link must be reachable by (instance, pid)
		$link = new TalerProductLink(self::$db);
		$rcFetch = $link->fetchByInstancePid(self::$instance, $detail['product_id']);
		print __METHOD__." link->fetchByInstancePid rc=".$rcFetch." fk_product=".$link->fk_product."\n";
		$this->assertGreaterThan(0, $rcFetch);

		// if Dolibarr product created/updated, price must match
		if ($link->fk_product) {
			$p = new Product(self::$db);
			$p->fetch($link->fk_product);
			print __METHOD__." fetched product id=".$p->id." price_ttc=".$p->price_ttc."\n";
			$this->assertEqualsWithDelta($priceNew, $p->price_ttc, 0.001);
		}

		print __METHOD__." END\n";
	}

	/**
	 * Execute a module SQL file using the same helper the admin UI uses.
	 *
	 * @param string $file Absolute path to .sql file
	 * @return void
	 */
	private static function runSqlFile(string $file): void
	{
		global $conf;
		if (!is_readable($file)) {
			print __METHOD__." SKIP (not readable) file=".$file."\n";
			return;
		}
		print "Admin.lib::run_sql file=".$file." silent=1 entity=".$conf->entity." usesavepoint=1 handler=default\n";
		// signature: run_sql($file, $silent=1, $entity=0, $usesavepoint=1, $handler='default', $okerror='default')
		run_sql($file, 1, $conf->entity, 1, 'default', 'default');
	}

	/**
	 * Mimic admin activation: run all SQL files in the module SQL dir in alpha order.
	 *
	 * @return void
	 */
	private static function installModuleSqlLikeAdmin(): void
	{
		$dir = DOL_DOCUMENT_ROOT.'/custom/talerbarr/sql';
		print __METHOD__." dir=".$dir."\n";
		if (!is_dir($dir)) {
			print __METHOD__." SKIP (no sql dir)\n";
			return;
		}
		$files = glob($dir.'/*.sql');
		sort($files);
		foreach ($files as $f) {
			self::runSqlFile($f);
		}
	}

	/**
	 * Ensure the module tables exist; install them like the admin UI if missing.
	 *
	 * @return void
	 */
	private static function ensureTalerTables(): void
	{
		$need = [
			MAIN_DB_PREFIX.'talerbarr_product_link',
			MAIN_DB_PREFIX.'talerbarr_category_map',
			MAIN_DB_PREFIX.'talerbarr_error_log',
			MAIN_DB_PREFIX.'talerbarr_tax_map',
			MAIN_DB_PREFIX.'talerbarr_talerconfig',
			MAIN_DB_PREFIX.'talerbarr_talerconfig_extrafields',
		];

		$missing = [];
		foreach ($need as $t) {
			$rs = self::$db->query("SELECT 1 FROM ".$t." LIMIT 1");
			print __METHOD__." table_check ".$t." rc=".($rs ? 1 : 0)."\n";
			if (!$rs) $missing[] = $t;
		}

		if ($missing) {
			print __METHOD__." missing=".implode(',', $missing)." -> running SQL like admin\n";
			self::installModuleSqlLikeAdmin();

			// re-check
			$still = [];
			foreach ($missing as $t) {
				$rs = self::$db->query("SELECT 1 FROM ".$t." LIMIT 1");
				print __METHOD__." recheck ".$t." rc=".($rs ? 1 : 0)."\n";
				if (!$rs) $still[] = $t;
			}
			if ($still) {
				print __METHOD__." STILL MISSING: ".implode(',', $still)."\n";
				self::markTestSkipped('Taler SQL tables not installed on this CI: '.implode(',', $still));
			}
		}
	}

	/**
	 * Data‑provider for parseTalerAmount() cases.
	 * @return array[]
	 */
	public static function providerParseTalerAmount(): array
	{
		return [
			// basic major+minor
			['EUR:12.34', ['currency'=>'EUR','value'=>12,'fraction'=>34000000]],
			// many decimals → trimmed/rounded to 8 places (here: 1 µ‑unit)
			['EUR:0.000001', ['currency'=>'EUR','value'=>0,'fraction'=>100]],
			// currency alias / mapping
			['KUDOS:5', ['currency'=>'EUR','value'=>5,'fraction'=>0]],
			// invalid → nulls
			['xyz', ['currency'=>'','value'=>null,'fraction'=>null]],
			// spaces & lowercase
			[" chf:7.70 \n", ['currency'=>'CHF','value'=>7,'fraction'=>70000000]],
		];
	}


	/**
	 * @dataProvider providerParseTalerAmount
	 *
	 * @param string $input input
	 * @param array  $expected output
	 * @return void
	 */
	public function testParseTalerAmount(string $input, array $expected): void
	{
		$out = TalerProductLink::parseTalerAmount($input);
		$this->assertSame($expected['currency'], $out['currency'], 'currency');
		$this->assertSame($expected['value'], $out['value'], 'value');
		$this->assertSame($expected['fraction'], $out['fraction'], 'fraction');
	}


	/**
	 * Provider for amount‑string formatting helpers.
	 *
	 * @return array
	 */
	public static function providerAmountStr(): array
	{
		return [
			[12.5, 'eur', 2, 'EUR:12.5'], // trims trailing zero
			[0.0, 'EUR', 2, 'EUR:0'],
			[12.345, 'CHF', 2, 'CHF:12.35'], // conventional rounding
			[-7.0, 'CHF', 2, 'CHF:0'], // negatives → clamped to zero
		];
	}


	/**
	 * @dataProvider providerAmountStr
	 *
	 * @param float  $price price in float
	 * @param string $cur 	currency
	 * @param int    $scale scale
	 * @param string $exp   expected
	 *
	 * @return void
	 */
	public function testAmountStrFromPrice(float $price, string $cur, int $scale, string $exp): void
	{
		$got = TalerProductLink::amountStrFromPrice($price, $cur, $scale);
		$this->assertSame($exp, $got);
		// talerAmountFromFloat is just a wrapper with scale=2
		if ($scale === 2) {
			$this->assertSame($exp, TalerProductLink::talerAmountFromFloat($price, $cur));
		}
	}


	/**
	 * Ensure computeSha256Hex() hashes strings and that input order affects hashes for raw arrays.
	 *
	 * @return void
	 */
	public function testComputeSha256Hex(): void
	{
		$this->assertSame(hash('sha256', 'abc'), TalerProductLink::computeSha256Hex('abc'));

		// same associative array with different key order → same JSON canonicalisation? No – function keeps order.
		$a1 = ['a'=>1,'b'=>2];
		$a2 = ['b'=>2,'a'=>1];
		$h1 = TalerProductLink::computeSha256Hex($a1);
		$h2 = TalerProductLink::computeSha256Hex($a2);
		$this->assertNotSame($h1, $h2, 'order matters for raw computeSha256Hex');
	}


	/**
	 * Minimal smoke-test for dolibarrArrayFromTalerDetail() mapping.
	 *
	 * @return void
	 */
	public function testDolibarrArrayFromTalerDetailBasic(): void
	{
		$detail = [
			'product_name' => 'Test prod',
			'description' => 'desc',
			'price' => 'EUR:12.34',
			'taxes' => [['percent'=>7.7]],
			'unit' => 'PIECE',
			'categories' => [1,2,3],
		];

		$out = TalerProductLink::dolibarrArrayFromTalerDetail($detail);

		$this->assertSame('Test prod', $out['label']);
		$this->assertEqualsWithDelta(12.34, (float) $out['price_ttc'], 0.00001);
		$this->assertSame('TTC', $out['price_base_type']);
		$this->assertSame('PIECE', $out['_unit_code']);
		$this->assertSame([1,2,3], $out['_categories']);
	}
}
