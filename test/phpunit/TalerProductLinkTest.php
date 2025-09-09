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
 *      \file       htdocs/custom/talerbarr/test/phpunit/ProductTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf, $user, $db, $langs;

require_once dirname(__FILE__, 6).'/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/talerbarr/core/modules/modTalerBarr.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/talerbarr/class/talerproductlink.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/talerbarr/class/talerconfig.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}


if (!class_exists('TalerMerchantClient')) {
	print("TalerMerchantClient is missing creating a fake one");
	/**
	 * Very small in-memory fake that honours only the methods the test needs.
	 */
	class TalerMerchantClient
	{
		/** @var array<string,array> keyed by product_id */
		private static array $store = [];

		/**
		 *
		 * @param string $baseUrl ignored
		 * @param string $token ignored
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
	/** @var DoliDB */
	private static ?DoliDB $db = null;
	/** @var User */
	private static ?User $user = null;
	/** @var TalerConfig */
	private static ?TalerConfig $cfg = null;

	/**
	 * @var string Taler “instance” name used for this run
	 */
	private static ?string $instance = null;
	/**
	 * @var int    Rowid of the specimen Dolibarr product we create
	 */
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

	/* ---------- global fixtures ----------------------------------------- */

	/**
	 * Prepare one verified TalerConfig row and open a transaction.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $conf, $user, $db;

		self::$db   = $db;
		self::$user = $user;

		/* 0) bootstrap the module (creates llx_taler_* tables) */

		$mod = new modTalerBarr($db);
		if ($mod->init([]) <= 0) {
			throw new RuntimeException('Unable to init modTalerBarr: ' . $db->lasterror());
		}
		$conf->modules[$mod->numero] = get_class($mod);   // flag as enabled

		/* 1) config row used by the helpers */
		self::$instance                  = getenv('TALER_USER') ?: 'phpunit-ci';
		self::$cfg                       = new TalerConfig($db);
		self::$cfg->entity               = $conf->entity;
		self::$cfg->talermerchanturl     = getenv('TALER_BASEURL') ?: 'http://stub.local/';
		self::$cfg->talertoken           = getenv('TALER_TOKEN')   ?: 'dummy';
		self::$cfg->username             = self::$instance;
		self::$cfg->syncdirection        = 0;   // 0 = push & pull allowed
		self::$cfg->verification_ok      = 1;
		self::$cfg->verification_ts      = dol_now();
		self::$cfg->create($user);                          // persist it

		$db->begin();                    // everything inside a single trx
	}

	/**
	 * Rollback the big transaction so database stays pristine.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		if (self::$db) {
			self::$db->rollback();
		}
	}

	/* ---------- 1)  push flow: Dolibarr → Taler -------------------------- */

	/**
	 * Creates a specimen Product then calls upsertFromDolibarr().
	 *
	 * @return void
	 */
	public function testUpsertFromDolibarr(): void
	{
		// (1) create Product
		$prod = new Product(self::$db);
		$prod->initAsSpecimen();
		$prod->ref        = 'PHPUNIT-'.self::randstr();
		$prod->price_ttc  = 42.00;
		$prod->status     = 1;
		$prod->status_buy = 1;

		$this->assertGreaterThan(0, $prod->create(self::$user));
		self::$prodId = $prod->id;

		// (2) call helper
		$rc = TalerProductLink::upsertFromDolibarr(self::$db, $prod, self::$user, self::$cfg);
		$this->assertSame(1, $rc, 'upsertFromDolibarr returned');

		if ($rc !== 1) {                                     // extra debug
			printf("\n[DBG] upsertFromDolibarr rc=%d\n[DBG] SQL=%s\n[DBG] ERR=%s\n",
				$rc,
				self::$db->lastquery(),
				self::$db->lasterror()
			);
		}
		$this->assertSame(1, $rc, 'upsertFromDolibarr should return 1');

		$link = new TalerProductLink(self::$db);
		$this->assertGreaterThan(0, $link->fetchByProductId($prod->id));

		$this->assertSame($prod->ref, $link->product_ref_snap);
		$this->assertNotEmpty($link->taler_product_id);
		$this->assertSame('EUR:42', substr($link->taler_amount_str, 0, 7));
	}

	/* ---------- 2)  pull flow: Taler → Dolibarr -------------------------- */

	/**
	 * @depends testUpsertFromDolibarr
	 *
	 * @return void
	 * @throws \Random\RandomException
	 */
	public function testUpsertFromTaler(): void
	{
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

		$rc = TalerProductLink::upsertFromTaler(self::$db, $detail, self::$user, ['instance' => self::$instance]);

		if ($rc !== 1) {                                     // extra debug
			printf("\n[DBG] upsertFromTaler rc=%d\n[DBG] SQL=%s\n[DBG] ERR=%s\n",
				$rc,
				self::$db->lastquery(),
				self::$db->lasterror()
			);
		}
		$this->assertSame(1, $rc, 'upsertFromTaler should return 1');

		// link must be reachable by (instance, pid)
		$link = new TalerProductLink(self::$db);
		$this->assertGreaterThan(
			0,
			$link->fetchByInstancePid(self::$instance, $detail['product_id'])
		);

		// if Dolibarr product created/updated, price must match
		if ($link->fk_product) {
			$p = new Product(self::$db);
			$p->fetch($link->fk_product);
			$this->assertEqualsWithDelta($priceNew, $p->price_ttc, 0.001);
		}
	}
}
