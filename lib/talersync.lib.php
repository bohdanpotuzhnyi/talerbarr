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
 * \file        lib/talersync.lib.php
 * \ingroup     talerbarr
 * \brief       Lightweight launcher for the background synchroniser.
 *
 * @package    TalerBarr
 */
class TalerSyncUtil
{
	/**
	 * Fire-and-forget background run of core/sync/talerbarrsync.php
	 *
	 * @param bool $force  pass --force to the script
	 * @param ?string $path_to_core If your module has an unusual layout you
	 *                              can inject the absolute path to its
	 *                              “…/core” directory here.
	 * @return void
	 */
	public static function launchBackgroundSync(bool $force = false, string $path_to_core = null): void
	{
		/* --------------------------------------------------------------------
		 * 1) Reject unsuitable environments
		 * ------------------------------------------------------------------ */
		if (php_sapi_name() === 'cli') {
			dol_syslog("TalerSyncUtil::launchBackgroundSync called from CLI – skipping", LOG_ERR);
			return;
		}
		if (!function_exists('exec')) {
			dol_syslog("TalerSyncUtil::launchBackgroundSync: exec() disabled – cannot run", LOG_ERR);
			return;
		}

		/* --------------------------------------------------------------------
		 * 2) Locate a PHP-CLI binary that is executable
		 * ------------------------------------------------------------------ */
		$candidates = [
			'/usr/bin/php',
			'/usr/bin/php8.4',
			PHP_BINDIR.'/php',
		];
		$cliBinary = null;
		foreach ($candidates as $c) {
			if (is_executable($c)) {
				$cliBinary = $c;
				break;
			}
		}
		if (!$cliBinary) {
			dol_syslog(__METHOD__.': no php-cli binary found', LOG_ERR);
			return;
		}

		/* --------------------------------------------------------------------
		 * 3) Resolve the path to core/sync/talerbarrsync.php
		 * ------------------------------------------------------------------ */
		if ($path_to_core) {
			$coreDir = rtrim($path_to_core, '/');
		} else {
			// Detect module root: either …/talerbarr/ or …/talerbarr/lib/
			$moduleDir = basename(__DIR__) === 'lib' ? dirname(__DIR__) : __DIR__;
			$coreDir   = $moduleDir.'/core';
		}

		$syncScript = realpath($coreDir.'/sync/talerbarrsync.php');
		if (!$syncScript || !is_file($syncScript) || !is_readable($syncScript)) {
			dol_syslog(__METHOD__.": sync script not found or not readable at $coreDir/sync/talerbarrsync.php", LOG_ERR);
			return;
		}


		/* --------------------------------------------------------------------
		 * 4) Build and launch the detached command
		 * ------------------------------------------------------------------ */
		$php  = escapeshellarg($cliBinary);
		$script = escapeshellarg($syncScript);
		$cmd  = "$php $script".($force ? ' --force' : '').' > /dev/null 2>&1 &';

		dol_syslog("TalerSyncUtil: launching background sync with command: $cmd", LOG_DEBUG);
		exec($cmd);
	}
}
