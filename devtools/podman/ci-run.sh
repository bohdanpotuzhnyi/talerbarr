#!/usr/bin/env bash
set -euo pipefail

echo "=== TalerBarr Podman CI runner ==="

# --- Configurable bits via env ----------------------------------------
: "${DOLIBARR_BRANCH:=22.0.3}"
: "${DB:=mysql}"
: "${TRAVIS_PHP_VERSION:=8.3}"
: "${TALER_STACK_HOST:=test.taler.potuzhnyi.com}"
: "${MODULE_NAME:=talerbarr}"
: "${MYSQL_PORT:=13306}"
: "${MYSQL_PASSWORD:=password}"
: "${WEB_PORT:=8000}"

# MODULE_DIR will be mounted from host (see run-tests-podman.sh)
: "${MODULE_DIR:=/opt/talerbarr-src}"

export DOLIBARR_BRANCH DB TRAVIS_PHP_VERSION TALER_STACK_HOST MODULE_NAME MODULE_DIR MYSQL_PORT MYSQL_PASSWORD

echo "Using:"
echo "  DOLIBARR_BRANCH   = ${DOLIBARR_BRANCH}"
echo "  DB                = ${DB}"
echo "  TRAVIS_PHP_VERSION= ${TRAVIS_PHP_VERSION}"
echo "  TALER_STACK_HOST  = ${TALER_STACK_HOST}"
echo "  MODULE_DIR        = ${MODULE_DIR}"
echo "  MYSQL_PORT        = ${MYSQL_PORT}"
echo "  WEB_PORT          = ${WEB_PORT}"
echo "  MYSQL_PASSWORD    = (hidden)"

TRAVIS_BUILD_DIR="/opt/dolibarr"
export TRAVIS_BUILD_DIR
DOLIBARR_DIR="${TRAVIS_BUILD_DIR}"

# ----------------------------------------------------------------------
# 1) Stage the module and clone Dolibarr
# ----------------------------------------------------------------------
echo "== Preparing Dolibarr and module from baked sources =="
cd "${DOLIBARR_DIR}"
export TRAVIS_BUILD_DIR="$(pwd)"
echo "TRAVIS_BUILD_DIR=${TRAVIS_BUILD_DIR}"
export PATH="${TRAVIS_BUILD_DIR}/vendor/bin:${TRAVIS_BUILD_DIR}/htdocs/includes/bin:${PATH}"

echo "Placing module into htdocs/custom/${MODULE_NAME}..."
MODULE_DEST="${TRAVIS_BUILD_DIR}/htdocs/custom/${MODULE_NAME}"
rm -rf "${MODULE_DEST}"
mkdir -p "${MODULE_DEST}"
if [ ! -d "${MODULE_DIR}" ]; then
  echo "Module source directory does not exist: ${MODULE_DIR}"
  exit 1
fi
cp -a "${MODULE_DIR}/." "${MODULE_DEST}/"
echo "== Dolibarr root contents =="
ls -la "${TRAVIS_BUILD_DIR}"
echo "== Module tree (${MODULE_DEST}) =="
find "${MODULE_DEST}" -print

# ----------------------------------------------------------------------
# 2) Bootstrap GNU Taler remote stack (kept close to your Travis logic)
# ----------------------------------------------------------------------
echo "== Bootstrapping GNU Taler remote stack =="
set -o pipefail
BASE_URL="https://${TALER_STACK_HOST}"
START_URL="${BASE_URL}/start"
STATUS_URL="${BASE_URL}/status"

echo "Calling start endpoint at ${START_URL}"
START_BODY=$(mktemp)
START_CODE=$(curl -s -X POST -o "${START_BODY}" -w "%{http_code}" "${START_URL}" || true)
echo "Start response (HTTP ${START_CODE}):"
cat "${START_BODY}" || true
rm -f "${START_BODY}"

if [ "${START_CODE}" != "200" ]; then
  echo "Failed to trigger remote stack reset (HTTP ${START_CODE})"
  exit 1
fi

echo "Polling status endpoint ${STATUS_URL} (up to 10 minutes)..."
DEADLINE=$((SECONDS + 600))
ATTEMPT=0
while true; do
  ATTEMPT=$((ATTEMPT + 1))
  STATUS_BODY=$(mktemp)
  STATUS_CODE=$(curl -s -o "${STATUS_BODY}" -w "%{http_code}" "${STATUS_URL}" || true)
  STATUS_TEXT=$(tr -d '\r' < "${STATUS_BODY}")
  rm -f "${STATUS_BODY}"
  echo "[${ATTEMPT}] HTTP ${STATUS_CODE} body='${STATUS_TEXT}'"
  if [ "${STATUS_CODE}" = "200" ] && echo "${STATUS_TEXT}" | grep -qi 'active'; then
    echo "Remote sandcastle is active."
    break
  fi
  if [ "${SECONDS}" -ge "${DEADLINE}" ]; then
    echo "Timeout waiting for remote sandcastle to become active."
    exit 1
  fi
  sleep 10
done
set +o pipefail

# ----------------------------------------------------------------------
# 3) Start MariaDB and prepare database
# ----------------------------------------------------------------------
echo "== Resetting MariaDB datadir (clean start) =="
rm -rf /var/lib/mysql/* || true
rm -rf /run/mysqld/* || true

echo "== Starting MariaDB and creating DB 'travis' =="

MYSQL_SOCKET="/run/mysqld/mysqld.sock"
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld || true
MYSQL_PASSWORD_SQL=$(printf "%s" "${MYSQL_PASSWORD}" | sed "s/'/''/g")

# Initialize datadir if needed
if [ ! -d /var/lib/mysql/mysql ]; then
  if command -v mariadb-install-db >/dev/null 2>&1; then
    mariadb-install-db --user=mysql --ldata=/var/lib/mysql --basedir=/usr >/dev/null
  else
    mysql_install_db --user=mysql --ldata=/var/lib/mysql --basedir=/usr >/dev/null
  fi
fi

chown -R mysql:mysql /var/lib/mysql || true

mysqld --user=mysql \
  --bind-address=127.0.0.1 \
  --port="${MYSQL_PORT}" \
  --socket="${MYSQL_SOCKET}" \
  --datadir=/var/lib/mysql \
  --pid-file=/run/mysqld/mysqld.pid \
  >/var/log/mysqld.log 2>&1 &
MYSQL_PID=$!

# wait for server to be ready
for i in {1..60}; do
  if mysqladmin --protocol=socket --socket="${MYSQL_SOCKET}" ping --silent; then
    break
  fi
  sleep 1
done
if ! mysqladmin --protocol=socket --socket="${MYSQL_SOCKET}" ping --silent; then
  echo "MariaDB failed to start; last log lines:"
  tail -n 200 /var/log/mysqld.log || true
  exit 1
fi

mysql --protocol=socket --socket="${MYSQL_SOCKET}" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS travis CHARACTER SET utf8;
CREATE USER IF NOT EXISTS 'travis'@'127.0.0.1' IDENTIFIED BY '${MYSQL_PASSWORD_SQL}';
GRANT ALL PRIVILEGES ON travis.* TO 'travis'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "Loading initial Dolibarr demo dump (3.5.0)..."
mysql --protocol=socket --socket="${MYSQL_SOCKET}" -uroot travis < dev/initdemo/mysqldump_dolibarr_3.5.0.sql
echo "Validating DB connection with application user..."
mysql --protocol=tcp --host=127.0.0.1 --port="${MYSQL_PORT}" --user=travis --password="${MYSQL_PASSWORD}" -e "SELECT 1" travis >/dev/null

# ----------------------------------------------------------------------
# 4) Install Composer tools (phpunit, lint, phpcs…) as in Travis
# ----------------------------------------------------------------------
echo "== Installing Composer tools for PHP ${TRAVIS_PHP_VERSION} =="

cd "${TRAVIS_BUILD_DIR}"

if [[ "${TRAVIS_PHP_VERSION}" =~ ^7\.[1-4]$ ]]; then
  composer self-update 2.2.18
  composer -n require \
    phpunit/phpunit ^7.5 \
    php-parallel-lint/php-parallel-lint ^1.2 \
    php-parallel-lint/php-console-highlighter ^0 \
    php-parallel-lint/php-var-dump-check ~0.4 \
    squizlabs/php_codesniffer ^3
else
  # phpunit 8+ for PHP 8.x
  composer self-update 2.4.4
  composer -n require --ignore-platform-reqs \
    phpunit/phpunit ^8 \
    php-parallel-lint/php-parallel-lint ^1.2 \
    php-parallel-lint/php-console-highlighter ^0 \
    php-parallel-lint/php-var-dump-check ~0.4 \
    squizlabs/php_codesniffer ^3
fi

# phpunit etc will now be under vendor/bin (on PATH)
echo "Binaries in vendor/bin:"
ls vendor/bin || true

# ----------------------------------------------------------------------
# 5) Dolibarr conf.php
# ----------------------------------------------------------------------
echo "== Generating htdocs/conf/conf.php =="

CONF_FILE="htdocs/conf/conf.php"
cat > "${CONF_FILE}" <<PHP
<?php
error_reporting(E_ALL);
\$dolibarr_main_url_root='http://127.0.0.1:${WEB_PORT}';
\$dolibarr_main_document_root='${TRAVIS_BUILD_DIR}/htdocs';
\$dolibarr_main_data_root='${TRAVIS_BUILD_DIR}/documents';
\$dolibarr_main_db_host='127.0.0.1';
\$dolibarr_main_db_name='travis';
\$dolibarr_main_instance_unique_id='travis1234567890';
\$dolibarr_main_db_type='mysqli';
\$dolibarr_main_db_port=${MYSQL_PORT};
\$dolibarr_main_db_user='travis';
\$dolibarr_main_db_pass='${MYSQL_PASSWORD}';
\$dolibarr_main_db_character_set='utf8';
\$dolibarr_main_db_collation='utf8_general_ci';
\$dolibarr_main_authentication='dolibarr';
PHP

mkdir -p "${TRAVIS_BUILD_DIR}/documents/admin/temp"
chmod -R a+rwx "${TRAVIS_BUILD_DIR}/documents"
echo "***** First line of dolibarr.log" > "${TRAVIS_BUILD_DIR}/documents/dolibarr.log"

# ----------------------------------------------------------------------
# 6) Install-forced file (simplified from Travis)
# ----------------------------------------------------------------------
echo "== Creating install.forced.php =="

INSTALL_FORCED_FILE="htdocs/install/install.forced.php"
cat > "${INSTALL_FORCED_FILE}" <<PHP
<?php
error_reporting(E_ALL);
\$force_install_noedit=2;
\$force_install_type='mysqli';
\$force_install_port=${MYSQL_PORT};
\$force_install_dbserver='127.0.0.1';
\$force_install_database='travis';
\$force_install_databaselogin='travis';
\$force_install_databasepass='${MYSQL_PASSWORD}';
\$force_install_prefix='llx_';
\$force_install_createdatabase=false;
\$force_install_createuser=false;
\$force_install_mainforcehttps=false;
\$force_install_main_data_root='${TRAVIS_BUILD_DIR}/documents';
PHP

# ----------------------------------------------------------------------
# 7) (Optional) Upgrade chain
# ----------------------------------------------------------------------
echo "== Running DB upgrade chain (shortened) =="
set +e
cd htdocs/install

run_upgrade() {
  local from="$1" to="$2"
  php upgrade.php "$from" "$to" ignoredbversion
  php upgrade2.php "$from" "$to"
  php step5.php "$from" "$to"
}

run_upgrade 3.5.0 3.6.0
run_upgrade 3.6.0 3.7.0
run_upgrade 3.7.0 3.8.0
run_upgrade 3.8.0 3.9.0
run_upgrade 3.9.0 4.0.0
run_upgrade 4.0.0 5.0.0
run_upgrade 5.0.0 6.0.0
run_upgrade 6.0.0 7.0.0
run_upgrade 7.0.0 8.0.0
run_upgrade 8.0.0 9.0.0
run_upgrade 9.0.0 10.0.0
run_upgrade 10.0.0 11.0.0
run_upgrade 11.0.0 12.0.0
run_upgrade 12.0.0 13.0.0
run_upgrade 13.0.0 14.0.0
run_upgrade 14.0.0 15.0.0
run_upgrade 15.0.0 16.0.0
run_upgrade 16.0.0 17.0.0
run_upgrade 17.0.0 18.0.0
run_upgrade 18.0.0 19.0.0
run_upgrade 19.0.0 20.0.0
run_upgrade 20.0.0 21.0.0
run_upgrade 21.0.0 22.0.0

set -e
cd "${TRAVIS_BUILD_DIR}"

# ----------------------------------------------------------------------
# 7b) Enable extra modules
# ----------------------------------------------------------------------
echo "== Enabling common Dolibarr modules =="
set +e
cd htdocs/install
ENABLE_LOG="${TRAVIS_BUILD_DIR}/enablemodule.log"
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_API,MAIN_MODULE_ProductBatch,MAIN_MODULE_SupplierProposal,MAIN_MODULE_STRIPE,MAIN_MODULE_ExpenseReport > "${ENABLE_LOG}" 2>&1
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_WEBSITE,MAIN_MODULE_TICKET,MAIN_MODULE_ACCOUNTING,MAIN_MODULE_MRP >> "${ENABLE_LOG}" 2>&1
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_RECEPTION,MAIN_MODULE_RECRUITMENT >> "${ENABLE_LOG}" 2>&1
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_KnowledgeManagement,MAIN_MODULE_EventOrganization,MAIN_MODULE_PARTNERSHIP >> "${ENABLE_LOG}" 2>&1
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_EmailCollector >> "${ENABLE_LOG}" 2>&1
set -e
echo "Enable modules log (tail):"
tail -n 40 "${ENABLE_LOG}" || true
cd "${TRAVIS_BUILD_DIR}"

# ----------------------------------------------------------------------
# 8.5) Start a web server for REST API tests
# ----------------------------------------------------------------------
echo "== Starting PHP built-in server for REST API tests =="
php -S 127.0.0.1:${WEB_PORT} -t htdocs >/tmp/php-server.log 2>&1 &
PHPSERVER_PID=$!

# ----------------------------------------------------------------------
# 8) Patch AllTests.php to include your module tests
# ----------------------------------------------------------------------
echo "== Patching test/phpunit/AllTests.php to include Taler tests =="

ALLTESTS="test/phpunit/AllTests.php"
if grep -q "TalerMerchantResponseParserTest" "${ALLTESTS}"; then
  echo "AllTests.php already references Taler parser + integration tests"
elif grep -q "TalerProductLinkTest" "${ALLTESTS}"; then
  echo "AllTests.php references integration tests; adding missing parser unit test"
  perl -0777 -pe 'BEGIN{
      $ins = "require_once DOL_DOCUMENT_ROOT.\x27/custom/talerbarr/test/phpunit/unit/merchant/TalerMerchantResponseParserTest.php\x27;\n" .
             "\$suite->addTestSuite(\x27TalerMerchantResponseParserTest\x27);\n";
    }
    s/return \$suite;/ $ins\nreturn \$suite;/s' -i "${ALLTESTS}"
else
  perl -0777 -pe 'BEGIN{
      $ins = "// ---- talerbarr --------------------------------------------------------------\n" .
             "require_once DOL_DOCUMENT_ROOT.\x27/custom/talerbarr/test/phpunit/unit/merchant/TalerMerchantResponseParserTest.php\x27;\n" .
             "\$suite->addTestSuite(\x27TalerMerchantResponseParserTest\x27);\n" .
             "require_once DOL_DOCUMENT_ROOT.\x27/custom/talerbarr/test/phpunit/integration/TalerProductLinkTest.php\x27;\n" .
             "\$suite->addTestSuite(\x27TalerProductLinkTest\x27);\n" .
             "require_once DOL_DOCUMENT_ROOT.\x27/custom/talerbarr/test/phpunit/integration/TalerOrderLinkStaticTest.php\x27;\n" .
             "\$suite->addTestSuite(\x27TalerOrderLinkStaticTest\x27);\n" .
             "require_once DOL_DOCUMENT_ROOT.\x27/custom/talerbarr/test/phpunit/integration/TalerOrderFlowIntegrationTest.php\x27;\n" .
             "\$suite->addTestSuite(\x27TalerOrderFlowIntegrationTest\x27);\n";
    }
    s/return \$suite;/ $ins\nreturn \$suite;/s' -i "${ALLTESTS}"
fi

tail -n 40 "${ALLTESTS}" || true

# ----------------------------------------------------------------------
# 9) Run PHPUnit
# ----------------------------------------------------------------------
echo "== Running PHPUnit =="
export TALER_INTEGRATION_TEST=1
export TALER_EXCHANGE_URL="${TALER_EXCHANGE_URL:-https://exchange.test.taler.potuzhnyi.com/}"
export TALER_BANK_URL="${TALER_BANK_URL:-https://bank.test.taler.potuzhnyi.com/}"
export TALER_BANK_WITHDRAW_ACCOUNT="${TALER_BANK_WITHDRAW_ACCOUNT:-merchant-sandbox}"
export TALER_BANK_WITHDRAW_PASSWORD="${TALER_BANK_WITHDRAW_PASSWORD:-sandbox}"
export TALER_MERCHANT_URL="${TALER_MERCHANT_URL:-https://merchant.test.taler.potuzhnyi.com/}"
SINK_HOST_BASE="https://${TALER_STACK_HOST:-test.taler.potuzhnyi.com}"
export TALER_WEBHOOK_SINK_URL="${TALER_WEBHOOK_SINK_URL:-${SINK_HOST_BASE}/webhooks}"
export TALER_WEBHOOK_SINK_RESET_URL="${TALER_WEBHOOK_SINK_RESET_URL:-${SINK_HOST_BASE}/webhooks/reset}"
PHPUNIT_FLAGS="${PHPUNIT_FLAGS:---debug}"
echo "TALER_WEBHOOK_SINK_URL=${TALER_WEBHOOK_SINK_URL:-<unset>}"
echo "TALER_WEBHOOK_SINK_RESET_URL=${TALER_WEBHOOK_SINK_RESET_URL:-<unset>}"

set +e
phpunit -d memory_limit=-1 ${PHPUNIT_FLAGS} -c test/phpunit/phpunittest.xml test/phpunit/AllTests.php | tee /tmp/phpunit.log | \
  grep -qE "(OK .*[0-9]+ tests.*[0-9]+ assertions|Tests: [0-9]+)" 
phpunitresult=$((PIPESTATUS[0]?PIPESTATUS[0]:PIPESTATUS[2]))
echo "Phpunit return code = ${phpunitresult}"
[ "${phpunitresult}" = 0 ] || { echo "=== PHPUnit log ==="; cat /tmp/phpunit.log; }
[ "${phpunitresult}" = 0 ] || { kill "${PHPSERVER_PID}" || true; kill "${MYSQL_PID}" || true; exit "${phpunitresult}"; }
if [ "${phpunitresult}" = 0 ]; then
  echo "=== Last 100 lines of PHPUnit log (success) ==="
  tail -n 100 /tmp/phpunit.log || true
  echo "=== Skipped/incomplete/risky summary (if any) ==="
  grep -n "skip" /tmp/phpunit.log || echo "None"
fi
set -e

echo "== Done. Tests passed. =="
kill "${PHPSERVER_PID}" || true
kill "${MYSQL_PID}" || true
