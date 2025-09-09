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
require_once DOL_DOCUMENT_ROOT.'/custom/talerbarr/core/modules/modTalerBarr.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/talerbarr/class/talerproductlink.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/talerbarr/class/talerconfig.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

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
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for TalerProductLink synchronisation helpers.
 */
class TalerProductLinkTest extends TestCase
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

		// 0) init module
		print __METHOD__." init modTalerBarr ...\n";
		$mod = new modTalerBarr($db);
		$rcInit = $mod->init('');
		print __METHOD__." modTalerBarr::init rc=".$rcInit."\n";
		if ($rcInit <= 0) {
			self::printDbStatus(__METHOD__." INIT ERR");
			throw new RuntimeException('Unable to init modTalerBarr');
		}
		$conf->modules[$mod->numero] = get_class($mod);

		// 1) config row
		self::$instance              = getenv('TALER_USER') ?: 'phpunit-ci';
		self::$cfg                   = new TalerConfig($db);
		self::$cfg->entity           = $conf->entity;
		self::$cfg->talermerchanturl = getenv('TALER_BASEURL') ?: 'http://stub.local/';
		self::$cfg->talertoken       = getenv('TALER_TOKEN')   ?: 'dummy';
		self::$cfg->username         = self::$instance;
		self::$cfg->syncdirection    = 0;
		self::$cfg->verification_ok  = 1;
		self::$cfg->verification_ts  = dol_now();

		$cfgId = self::$cfg->create($user);
		print __METHOD__." TalerConfig::create rc=".$cfgId."\n";
		if ($cfgId <= 0) {
			self::printDbStatus(__METHOD__." TALERCFG ERR");
			print __METHOD__." cfg->error=".self::$cfg->error."\n";
		}
		self::assertGreaterThan(0, $cfgId, 'Failed to create TalerConfig');

		// 2) sanity checks on tables
		foreach ([MAIN_DB_PREFIX.'taler_config', MAIN_DB_PREFIX.'taler_product_link'] as $t) {
			$rs = $db->query("SELECT 1 FROM ".$t." LIMIT 1");
			print __METHOD__." table_check ".$t." rc=".($rs ? 1 : 0)."\n";
			if (!$rs) self::printDbStatus(__METHOD__." TABLE ".$t." ERR");
			self::assertNotFalse($rs, "Table missing: $t");
		}

		// 3) wrap all tests in one transaction
		print __METHOD__." BEGIN TRANSACTION\n";
		$db->begin();

		print __METHOD__." END\n";
	}

	/**
	 * Rollback the big transaction so database stays pristine.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		print __METHOD__." START\n";
		if (self::$db) {
			$connected = property_exists(self::$db, 'connected') ? (bool) self::$db->connected : true;
			print __METHOD__." connected=".($connected ? '1' : '0')."\n";
			if ($connected) {
				print __METHOD__." ROLLBACK\n";
				self::$db->rollback();
			} else {
				print __METHOD__." SKIP ROLLBACK (db closed)\n";
			}
		} else {
			print __METHOD__." SKIP (no db)\n";
		}
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
}
