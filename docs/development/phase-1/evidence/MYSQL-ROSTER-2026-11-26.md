# MySQL/MariaDB verification roster — run on the integration candidate

**Head:** `ff8581b394e719a131a7d768be9a89469edaf86b` (`ff8581b39`, branch `int/checkpoint`)
**Date:** 2026-11-26
**Engine:** 10.11.14-MariaDB-0ubuntu0.24.04.1
**Command:** `tests/Support/Security/bin/run-mysql-verifications.sh`

```
MySQL verification runner
=========================
socket : /var/run/mysqld/mysqld.sock
host   : 127.0.0.1:3306
user   : root

---------------------------------------------------------------------------
>>> verify-sec13-migration-on-mysql
    PASS  (see /tmp/mysqlverify-verify-sec13-migration-on-mysql.log)
    checks: 31   failures: 0
    
    ALL ASSERTIONS HELD on 10.11.14-MariaDB-0ubuntu0.24.04.1.

---------------------------------------------------------------------------
>>> verify-product-ownership-on-mysql
    PASS  (see /tmp/mysqlverify-verify-product-ownership-on-mysql.log)
    checks: 17   failures: 0
    
    ALL ASSERTIONS HELD on 10.11.14-MariaDB-0ubuntu0.24.04.1.

---------------------------------------------------------------------------
>>> verify-cost-price-migration-on-mysql
    PASS  (see /tmp/mysqlverify-verify-cost-price-migration-on-mysql.log)
    
    ======================================================================================
    PASS 13/13

---------------------------------------------------------------------------
>>> verify-coupon-code-matching-on-mysql
    PASS  (see /tmp/mysqlverify-verify-coupon-code-matching-on-mysql.log)
      cleaned up: dropped `coupon_verify`
    ------------------------------------------------------------------------------
    6 checks, 0 failures

---------------------------------------------------------------------------
>>> verify-order-export-column-order-on-mysql
    PASS  (see /tmp/mysqlverify-verify-order-export-column-order-on-mysql.log)
      cleaned up: dropped `exportorder_verify`
    ------------------------------------------------------------------------------
    23 checks, 0 failure(s)

---------------------------------------------------------------------------
>>> verify-cart-quantity-guard-on-mysql
    PASS  (see /tmp/mysqlverify-verify-cart-quantity-guard-on-mysql.log)
    throwaway database dropped.
    ------------------------------------------------------------------------------
    OK: every check passed on real MySQL.

---------------------------------------------------------------------------
>>> verify-category-product-cascade-on-mysql
    PASS  (see /tmp/mysqlverify-verify-category-product-cascade-on-mysql.log)
    
    This asserts nothing about the live Store Syria database, whose schema this script
    never touches and cannot see.

---------------------------------------------------------------------------
>>> verify-module-order-mutation-id-coercion-on-mysql
    PASS  (see /tmp/mysqlverify-verify-module-order-mutation-id-coercion-on-mysql.log)
      [ OK ] database `p1inv14_verify` dropped
    
    RESULT: ALL CHECKS PASSED

---------------------------------------------------------------------------
>>> verify-module-resource-ownership-on-mysql
    PASS  (see /tmp/mysqlverify-verify-module-resource-ownership-on-mysql.log)
      [ OK ] database `p1inv17_verify` dropped
    
    RESULT: ALL CHECKS PASSED

---------------------------------------------------------------------------
>>> verify-lottery-code-collation-on-mysql
    PASS  (see /tmp/mysqlverify-verify-lottery-code-collation-on-mysql.log)
      [ OK ] database `p1inv18_verify` dropped
    
    RESULT: ALL CHECKS PASSED

---------------------------------------------------------------------------
>>> verify-lottery-refund-collation-on-mysql
    PASS  (see /tmp/mysqlverify-verify-lottery-refund-collation-on-mysql.log)
             dropped p1inv20_verify
    
    RESULT: every assertion held

---------------------------------------------------------------------------
>>> verify-reserved-role-name-collation-on-mysql
    SKIPPED  -- the script declined to measure (see /tmp/mysqlverify-verify-reserved-role-name-collation-on-mysql.log)
    SKIP: no MySQL reachable at 127.0.0.1 -- SQLSTATE[HY000] [1698] Access denied for user 'root'@'localhost'

---------------------------------------------------------------------------
>>> verify-refund-freshness-lock-and-limiter-keys-on-mysql
    PASS  (see /tmp/mysqlverify-verify-refund-freshness-lock-and-limiter-keys-on-mysql.log)
    
    ------------------------------------------------------------------------------
    10 checks, 0 failure(s)

---------------------------------------------------------------------------
>>> verify-last-unit-oversell-on-mysql
    PASS  (see /tmp/mysqlverify-verify-last-unit-oversell-on-mysql.log)
    
    ------------------------------------------------------------------------------
    22 checks, 0 failures

---------------------------------------------------------------------------
>>> verify-guest-cart-clear-coercion-on-mysql
    PASS  (see /tmp/mysqlverify-verify-guest-cart-clear-coercion-on-mysql.log)
    FINDING
      On production's non-strict MySQL, `customer_id = ''` is read as 0: ONE guest's WhatsApp/Telegram order deletes 2 basket row(s),
      including OTHER guests' baskets for the same product in the same store (guest-B above). Their carts are emptied by a stranger's checkout.

---------------------------------------------------------------------------
>>> verify-store-id-coercion-on-mysql
    PASS  (see /tmp/mysqlverify-verify-store-id-coercion-on-mysql.log)
    
    SQLite (the Feature suite's engine):
      [ OK ] SQLite does NOT match '5/../../x' -- the suite is blind to this

---------------------------------------------------------------------------
>>> verify-settings-name-collation-on-mysql
    PASS  (see /tmp/mysqlverify-verify-settings-name-collation-on-mysql.log)
      13  app/Services/HostClaims.php:278
          App\Services\HostClaims::heldByAnotherStore()
    

---------------------------------------------------------------------------
>>> verify-host-claims-on-mysql
    PASS  (see /tmp/mysqlverify-verify-host-claims-on-mysql.log)
      [ OK ] the host lookup still resolves the victim host to store 2
             store 2
      [ OK ] heldByAnotherStore sees the victim's host under an accent in the value

---------------------------------------------------------------------------
>>> verify-money-column-precision-on-mysql
    PASS  (see /tmp/mysqlverify-verify-money-column-precision-on-mysql.log)
    throwaway database dropped
    
    18 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-setting-name-writer-on-mysql
    PASS  (see /tmp/mysqlverify-verify-setting-name-writer-on-mysql.log)
             2:folded, 2:folded, 5:padded
    
    every check held

---------------------------------------------------------------------------
>>> verify-setting-name-writer-on-mysql
    PASS  (see /tmp/mysqlverify-verify-setting-name-writer-on-mysql.log)
             skipped: the query in the document is written for the UCA collation the project names, and this column is utf8mb4_general_ci
    
    every check held

---------------------------------------------------------------------------
>>> verify-setting-name-writer-on-mysql
    PASS  (see /tmp/mysqlverify-verify-setting-name-writer-on-mysql.log)
             2:folded, 2:folded, 5:padded
    
    every check held

---------------------------------------------------------------------------
>>> verify-stock-oversell-concurrency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-stock-oversell-concurrency-on-mysql.log)
    dropped the throwaway database `oversell_verify`
    ------------------------------------------------------------------------------
    14 checks, 0 failure(s)

---------------------------------------------------------------------------
>>> verify-store-slug-identity-on-mysql
    PASS  (see /tmp/mysqlverify-verify-store-slug-identity-on-mysql.log)
    dropped database slug_verify
    
    RESULT: all checks passed

---------------------------------------------------------------------------
>>> verify-gateway-return-claim-on-mysql
    PASS  (see /tmp/mysqlverify-verify-gateway-return-claim-on-mysql.log)
    
    ------------------------------------------------------------------------------
    12 checks, 0 failures

---------------------------------------------------------------------------
>>> verify-ord15-billing-geography-on-mysql
    PASS  (see /tmp/mysqlverify-verify-ord15-billing-geography-on-mysql.log)
    [PASS]  NULL is accepted in both columns, which is what the fix stores when a posted value cannot be resolved -- so the chosen behaviour is possible on the production engine, not just on SQLite
    
    ALL ASSERTIONS HELD.

---------------------------------------------------------------------------
>>> verify-pending-partial-payment-lookup-on-mysql
    PASS  (see /tmp/mysqlverify-verify-pending-partial-payment-lookup-on-mysql.log)
      [ OK ] database `p1ord02_pending_verify` dropped
    
    RESULT: every assertion held.

---------------------------------------------------------------------------
>>> verify-product-review-category-set-null-on-mysql
    PASS  (see /tmp/mysqlverify-verify-product-review-category-set-null-on-mysql.log)
      [ OK ] database `p1inv24_verify` dropped
    
    RESULT: every assertion held.

---------------------------------------------------------------------------
>>> verify-model-cache-isolation-on-mysql
    PASS  (see /tmp/mysqlverify-verify-model-cache-isolation-on-mysql.log)
    dropped the throwaway database `modelcache_verify`
    ==========================================================================================
    16 checks, 0 failure(s)

---------------------------------------------------------------------------
>>> verify-model-cache-flush-scope-on-mysql
    PASS  (see /tmp/mysqlverify-verify-model-cache-flush-scope-on-mysql.log)
    dropped the throwaway database `flushscope_verify`
    ----------------------------------------------------------------------------------
    5 checks, 0 failure(s)

---------------------------------------------------------------------------
>>> verify-variant-removal-consent-on-mysql
    PASS  (see /tmp/mysqlverify-verify-variant-removal-consent-on-mysql.log)
    failures: 0
    
    ALL ASSERTIONS HELD on 10.11.14-MariaDB-0ubuntu0.24.04.1.

---------------------------------------------------------------------------
>>> verify-login-and-settings-cache-paths-on-mysql
    PASS  (see /tmp/mysqlverify-verify-login-and-settings-cache-paths-on-mysql.log)
    dropped the throwaway database `logincache_verify`
    ========================================================================================
    8 checks, 0 failure(s)

---------------------------------------------------------------------------
>>> verify-replay-claim-with-model-cache-isolation-on-mysql
    PASS  (see /tmp/mysqlverify-verify-replay-claim-with-model-cache-isolation-on-mysql.log)
    dropped the throwaway database `claimisolation_verify`
    ================================================================================================
    16 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-replay-claim-survives-a-model-save-on-mysql
    PASS  (see /tmp/mysqlverify-verify-replay-claim-survives-a-model-save-on-mysql.log)
    dropped the throwaway database `replayclaim_verify`
    ----------------------------------------------------------------------------------------------
    9 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-gateway-reference-idempotency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-gateway-reference-idempotency-on-mysql.log)
    dropped the throwaway database `ogr_verify`
    ======================================================================================
    14 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-cart-reconciliation-concurrency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-cart-reconciliation-concurrency-on-mysql.log)
        dropped the throwaway database `cartrec_verify`
    ======================================================================================
    16 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-empty-variant-name-wipe-on-mysql
    PASS  (see /tmp/mysqlverify-verify-empty-variant-name-wipe-on-mysql.log)
    ==========================================================================================
    checks  : 13
    failures: 0

---------------------------------------------------------------------------
>>> verify-plan-coupon-refusal-ordering-on-mysql
    PASS  (see /tmp/mysqlverify-verify-plan-coupon-refusal-ordering-on-mysql.log)
    platform-wide, and a credential test placed first writes nothing.
    
    dropped the throwaway database `f234_verify`.

---------------------------------------------------------------------------
>>> verify-plan-purchase-intent-on-mysql
    PASS  (see /tmp/mysqlverify-verify-plan-purchase-intent-on-mysql.log)
    exact where float equality is not.
    
    dropped the throwaway database `f229_verify`.

---------------------------------------------------------------------------
>>> verify-plan-payment-reconciliation-on-mysql
    PASS  (see /tmp/mysqlverify-verify-plan-payment-reconciliation-on-mysql.log)
    
    ------------------------------------------------------------------------------
    checks: 20   failed: 0

---------------------------------------------------------------------------
>>> verify-module-payment-boundary-on-mysql
    PASS  (see /tmp/mysqlverify-verify-module-payment-boundary-on-mysql.log)
    
    ------------------------------------------------------------------------------
    checks: 16   failed: 0

---------------------------------------------------------------------------
>>> verify-order-replacement-concurrency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-order-replacement-concurrency-on-mysql.log)
    serialises and the index guarantees -- D-15 condition 8 does not rest on a SELECT.
    
    dropped the throwaway database `e13f_verify`.

---------------------------------------------------------------------------
>>> verify-report-window-boundary-on-mysql
    PASS  (see /tmp/mysqlverify-verify-report-window-boundary-on-mysql.log)
    
    12 checks, 0 failed
    Dropped report_boundary_verify_8146

---------------------------------------------------------------------------
>>> verify-top-five-reports-on-mysql
    PASS  (see /tmp/mysqlverify-verify-top-five-reports-on-mysql.log)
    
    19 checks, 0 failed
    Dropped top_five_verify_8190

---------------------------------------------------------------------------
>>> verify-consent-ledger-concurrency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-consent-ledger-concurrency-on-mysql.log)
    
    ------------------------------------------------------------------------------
    17 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-gap222-wallet-currency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-gap222-wallet-currency-on-mysql.log)
      ok    number_format of that ceiling reads as money, not as scientific notation
    
    19 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-settled-order-guard-enum-on-mysql
    PASS  (see /tmp/mysqlverify-verify-settled-order-guard-enum-on-mysql.log)
      ok    SUM() over the column is exact to the cent, and returns a STRING on this engine
    
    19 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-gap223-lastmonth-window-on-mysql
    PASS  (see /tmp/mysqlverify-verify-gap223-lastmonth-window-on-mysql.log)
      ok    and on none of them does the fixed window disagree with its own label
    
    17 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-ana009-alert-key-on-mysql
    PASS  (see /tmp/mysqlverify-verify-ana009-alert-key-on-mysql.log)
      ok    reference_type for `order_value` is stored whole (28 chars)
    
    19 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-metric-layer-on-mysql
    PASS  (see /tmp/mysqlverify-verify-metric-layer-on-mysql.log)
    
    65 checks, 0 failed
    Dropped metriclayer_verify

---------------------------------------------------------------------------
>>> verify-loyalty-redemption-currency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-loyalty-redemption-currency-on-mysql.log)
    
    12 checks, 0 failed
    Dropped loyaltyredemption_verify

---------------------------------------------------------------------------
>>> verify-ana005-sales-collection-on-mysql
    PASS  (see /tmp/mysqlverify-verify-ana005-sales-collection-on-mysql.log)
    throwaway database dropped
    
    22 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-analytics-breakdowns-on-mysql
    PASS  (see /tmp/mysqlverify-verify-analytics-breakdowns-on-mysql.log)
    
    12 checks, 0 failed
    Dropped breakdowns_verify

---------------------------------------------------------------------------
>>> verify-saas-revenue-on-mysql
    PASS  (see /tmp/mysqlverify-verify-saas-revenue-on-mysql.log)
    checks: 30   failures: 0
    
    ALL ASSERTIONS HELD on 10.11.14-MariaDB-0ubuntu0.24.04.1.

---------------------------------------------------------------------------
>>> verify-store-inventory-valuation-on-mysql
    PASS  (see /tmp/mysqlverify-verify-store-inventory-valuation-on-mysql.log)
    
    dropped inv_metric_verify
    ALL CHECKS PASSED on mysql

---------------------------------------------------------------------------
>>> verify-gate-f-migrations-on-mysql
    PASS  (see /tmp/mysqlverify-verify-gate-f-migrations-on-mysql.log)
    --------------------------------------------------------------------------------------------
    29 checks, 0 failed
    GATE F VERIFIED on 10.11.14-MariaDB-0ubuntu0.24.04.1: the 15 migrations reproduce production exactly on a fresh database, are a structural and data no-op against a production shape, roll back without dropping anything, and the structural guard refuses all five corruptions.

---------------------------------------------------------------------------
>>> verify-interrupted-theme-migration-on-mysql
    PASS  (see /tmp/mysqlverify-verify-interrupted-theme-migration-on-mysql.log)
      dropped p3m3e_interrupted_8657
    
      6 checks, 0 failed, 0 inconclusive

---------------------------------------------------------------------------
>>> verify-theme-cache-flush-after-commit-on-mysql
    PASS  (see /tmp/mysqlverify-verify-theme-cache-flush-after-commit-on-mysql.log)
      ok    REQUIREMENT 4: the storefront read sees the new value after commit
    
      4 checks, 0 failed, 0 inconclusive

---------------------------------------------------------------------------
>>> verify-theme-order-theme-id-migration-on-mysql
    PASS  (see /tmp/mysqlverify-verify-theme-order-theme-id-migration-on-mysql.log)
      dropped p3m3d_order_8736
    
      20 checks, 0 failed, 0 inconclusive

---------------------------------------------------------------------------
>>> verify-theme-publish-concurrency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-theme-publish-concurrency-on-mysql.log)
      dropped p3m3d_publish_8791
    
      21 checks, 0 failed, 0 inconclusive

---------------------------------------------------------------------------
>>> verify-theme-version-concurrency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-theme-version-concurrency-on-mysql.log)
      dropped p3m3c_concurrency_8841
    
      7 passed, 0 failed, 0 inconclusive

---------------------------------------------------------------------------
>>> verify-theme-version-integrity-on-mysql
    PASS  (see /tmp/mysqlverify-verify-theme-version-integrity-on-mysql.log)
      dropped p3m3_theme_versions_8937
    
      21 passed, 0 failed, 0 inconclusive

---------------------------------------------------------------------------
>>> verify-pos-sale-claim-concurrency-on-mysql
    PASS  (see /tmp/mysqlverify-verify-pos-sale-claim-concurrency-on-mysql.log)
    
    ------------------------------------------------------------------------------
    28 checks, 0 failures

---------------------------------------------------------------------------
>>> verify-store-wallet-provisioning-on-mysql
    PASS  (see /tmp/mysqlverify-verify-store-wallet-provisioning-on-mysql.log)
      ok    and no process died trying -- all 8 recovered from the race
    ------------------------------------------------------------------------------
      13 checks, 0 failed, 0 skipped

---------------------------------------------------------------------------
>>> verify-dashboard-top-sales-scope-on-mysql
    PASS  (see /tmp/mysqlverify-verify-dashboard-top-sales-scope-on-mysql.log)
    single divisor could have corrected it. The NEW computation returns the figures that
    arithmetic on the fixture predicts, excludes order-level money, tolerates both line
    spellings, isolates each store and honours a half-open date window.

---------------------------------------------------------------------------
>>> verify-customer-email-uniqueness-on-mysql
    PASS  (see /tmp/mysqlverify-verify-customer-email-uniqueness-on-mysql.log)
    ================================================================================================
    
    (dropped throwaway database ceu_verify_1d1c7f7a)

---------------------------------------------------------------------------
>>> verify-dashboard-rankings-on-mysql
    PASS  (see /tmp/mysqlverify-verify-dashboard-rankings-on-mysql.log)
    
    ------------------------------------------------------------
    13 checks, 0 failed

---------------------------------------------------------------------------
>>> verify-coupon-allocation-on-mysql
    PASS  (see /tmp/mysqlverify-verify-coupon-allocation-on-mysql.log)
    
    ------------------------------------------------------------------------------
    13 checks, 0 failures

---------------------------------------------------------------------------
>>> verify-tax-band-totals-on-mysql
    PASS  (see /tmp/mysqlverify-verify-tax-band-totals-on-mysql.log)
    19 checks, 0 failures
    NOTHING WAS CHANGED. §C's excess and §D's band selection are both reported for an owner
    decision; this script exists so the figures stop being invisible.

---------------------------------------------------------------------------
>>> verify-refund-total-coercion-on-mysql
    PASS  (see /tmp/mysqlverify-verify-refund-total-coercion-on-mysql.log)
    ------------------------------------------------------------------------------
    14 checks, 0 failures
    NOTHING WAS CHANGED. §B's zero ceiling on a NULL total is reported for an owner decision.

---------------------------------------------------------------------------
>>> verify-order-write-transaction-on-mysql
    PASS  (see /tmp/mysqlverify-verify-order-write-transaction-on-mysql.log)
    
    ------------------------------------------------------------------------------
    13 checks, 0 failures

---------------------------------------------------------------------------
>>> verify-null-final-price-ceiling-on-mysql
    PASS  (see /tmp/mysqlverify-verify-null-final-price-ceiling-on-mysql.log)
    ------------------------------------------------------------------------------
    11 checks, 0 failures
    NO MIGRATION WAS APPLIED and no production data was read. The deciding count is in §F.

===========================================================================
passed: 71   failed: 0   skipped: 1
  SKIPPED (measured nothing): verify-reserved-role-name-collation-on-mysql
every MySQL verification script on disk is accounted for, and every script that measured
anything reported all assertions held.
```
