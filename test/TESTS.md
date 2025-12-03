# TalerBarr Test Inventory and Design Notes

This file tracks current test suites, what they cover, and where they live.
The repository is now organized by test type, with integration suites grouped together.

## Integration suites (real Dolibarr + Taler stack)

These suites are intended to run against real services and module code.
No fake `TalerMerchantClient` fallback is used in integration tests.

1. `test/phpunit/integration/TalerOrderFlowIntegrationTest.php`
- End-to-end order lifecycle between Dolibarr and GNU Taler wallet/merchant.
- Covers order creation sync, wallet withdrawal/payment, merchant status polling, invoice creation, and reverse sync direction flow.

2. `test/phpunit/integration/TalerOrderLinkStaticTest.php`
- Database-backed coverage for `TalerOrderLink` static helpers and payout/refund-related scenarios.
- Includes currency mapping hints and repayment synchronization checks that require Dolibarr bootstrap.

3. `test/phpunit/integration/TalerProductLinkTest.php`
- Integration-style product synchronization checks for Dolibarr <-> Taler product flows.
- Uses real module classes (`TalerMerchantClient`, `TalerProductLink`, `TalerConfig`) with Dolibarr DB/module initialization.

## Unit suites

1. `test/phpunit/unit/merchant/TalerMerchantResponseParserTest.php`
- Pure parser validation for merchant API payload shapes and fallback behavior.
- No Dolibarr DB bootstrap required.

## CI wiring

`devtools/podman/ci-run.sh` (invoked by GitHub Actions via `devtools/podman/run-tests-podman.sh`) injects integration suites from:
- `custom/talerbarr/test/phpunit/unit/merchant/TalerMerchantResponseParserTest.php`
- `custom/talerbarr/test/phpunit/integration/TalerProductLinkTest.php`
- `custom/talerbarr/test/phpunit/integration/TalerOrderLinkStaticTest.php`
- `custom/talerbarr/test/phpunit/integration/TalerOrderFlowIntegrationTest.php`
