<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

/**
 * THE SERVICE LAYER'S RAW QUERIES, WHICH NO EXISTING RATCHET COULD SEE.
 *
 * HOW THIS WAS FOUND, because the route matters more than the result. F216 added four raw
 * statements to `app/Services/GatewayReference.php` and `RawQueryTenancyInventoryTest`'s
 * per-directory count moved, as designed. But `OwnedPathRawQueryInventoryTest` — the one that
 * actually asks "does this statement name `store_id`, and if not, why not" — stayed green, because
 * its scan does not reach `app/Services`. So a money-bearing service could be added with an
 * unscoped raw write and only a COUNT would move; nothing would ask the question.
 *
 * Measuring the directory for the first time found **25 raw statements across 10 service files,
 * 8 of which name `store_id` nowhere**, five of them in `OrderFinancialService` writing
 * `store_order_financials` and moving `store_wallets.total_income`.
 *
 * **NONE OF THE EIGHT IS A LIVE DEFECT, and that conclusion is traced rather than assumed.** The
 * five money writes key on a PRIMARY KEY that their only caller has already ownership-verified:
 * `StoreFinanceController::updateFinancialPaymentStatus` gates on
 * `canFinance('Edit Finance Payment Status')`, then loads the row with
 * `->where('id', $id)->where('store_id', $storeId)` where `$storeId` is `getCurrentStore()` — the
 * authenticated principal's store, NOT request input — refuses when it does not match, and passes
 * `$financial->id`. That is derived ownership and it holds today.
 *
 * **WHAT DOES NOT HOLD IS THAT IT WILL STAY TRUE.** `markFinancialAsPaid(int $financialId)` is a
 * public method taking a bare integer. A second caller written next month that passes
 * `$request->financial_id` would move another store's wallet balance, and this codebase's own
 * record says that is not hypothetical: "a tenant predicate built from caller-supplied input is
 * not a tenant check — this has produced four separate defects". So the containment here is a
 * ratchet on the CALLER SET, not a change to the service: the service is correct, its safety is
 * just conditional on something no test was asserting.
 *
 * Production code is deliberately untouched by this file.
 */
class ServiceLayerRawQueryInventoryTest extends TestCase
{
    /** `DB::table(` or `\DB::table(`, not preceded by a word character. */
    private const RAW_PATTERN = '/(?<!\w)(?:\\\\)?DB::table\(/';

    /**
     * The store-less statements, keyed by a FRAGMENT that identifies the statement rather than by
     * line number, so an edit above does not invalidate the declaration.
     *
     * Ratcheted in BOTH directions below: a declaration cannot outlive its statement, and a new
     * store-less statement cannot hide behind one.
     */
    private const STORE_LESS_BY_DESIGN = [
        /* ---- F229 / D-31: the PLAN purchase intent. A SaaS plan sale is not a tenant event.
           `plan_purchase_intents` carries no `store_id` COLUMN at all, and that is the design
           rather than an omission: a plan is bought by a USER from the platform, not by one of a
           merchant's stores from another. `plans` has no `store_id` either, and `plan_orders` --
           the row these intents produce -- keys on `user_id`. The isolation this table needs is
           PER-PURCHASER, which is requirement 5's "cross-user reference reuse", and it is
           enforced: `verifyAndConsume()` refuses an intent whose `user_id` is not the
           authenticated principal, BEFORE it compares the amount, so a stranger probing a
           reference cannot even learn what that purchase was worth.
           `PlanPurchaseIntentTest::test_another_account_cannot_consume_someone_elses_intent`
           ratchets that, and `verify-plan-purchase-intent-on-mysql.php` drives it on a real engine
           together with the two-connection race. Scoping these statements by `store_id` would
           require inventing a column whose value no plan purchase has. */
        "DB::table(self::TABLE)->insert" =>
            'Creating a plan purchase intent. A plan sale belongs to a USER and the platform, not '
            . 'to a store, so there is no store_id to predicate on. The purchaser is the isolation '
            . 'boundary and is written here from the authenticated principal.',

        "DB::table(self::TABLE) ->where('provider', \$provider)" =>
            'Reading or consuming an intent by provider + reference. The reference is 128 bits of '
            . 'random minted by the server, so it is not guessable, and every consuming path '
            . 'checks `user_id` against the authenticated principal before it discloses or '
            . 'changes anything. Per-purchaser isolation, not per-tenant -- a plan has no tenant.',

        "DB::table(self::TABLE) ->where('id', \$intent->id)" =>
            'The one conditional UPDATE that consumes an intent, keyed on the row the reads above '
            . 'already resolved and already checked the purchaser of. `where status = pending` is '
            . 'what makes two concurrent callbacks produce exactly one activation; a store_id '
            . 'predicate would add nothing a plan purchase has.',

        "DB::table(self::TABLE) ->where(function (\$query)" =>
            'The unreconciled-intents review query: captured payments that activated nothing. It '
            . 'is deliberately platform-wide, because that is the population an operator needs to '
            . 'see -- narrowing it to one tenant would hide exactly the rows it exists to surface. '
            . 'Read-only, called by no request path, and it decides nothing (requirement 9 forbids '
            . 'acting on it).',

        // ---- the tenant RESOLVER. It cannot be tenant-scoped; it is what produces the tenant.
        "DB::table('users')->whereNotNull('deliverysyria_secret')" =>
            'This IS the tenant resolution step for the DeliverySyria integration: given a bearer '
            . 'secret and nothing else, find which users hold it. A `store_id` predicate would '
            . 'require knowing the answer first. The comparison is `hash_equals` against each '
            . 'candidate rather than a WHERE on the secret, so the query cannot be used as an '
            . 'oracle, and the result is narrowed to that owner\'s stores by the next statement.',

        "DB::table('stores')->whereIn('created_by', \$owners)" =>
            'The second half of the same resolution: owner ids -> their store ids. `$owners` comes '
            . 'from the hash_equals match above, never from request input. Scoping this by '
            . '`store_id` would be circular.',

        // ---- a row already reached through a store-scoped parent.
        "DB::table('store_devices') ->where('id', \$device->id)" =>
            'Deactivates ONE device by its own primary key, after a push to it returned an '
            . 'invalid-token error. `$device` comes from the loop over `$notification`\'s own '
            . 'store devices, and `$notification->store_id` is logged on the line above, so the '
            . 'row is already that store\'s. A `store_id` predicate here would be redundant and '
            . 'would hide that the parent loop is where the scoping lives.',

        // ---- the money writes. Derived ownership, ratcheted by the caller test below.
        "->update([ 'payment_status' => \$orderStatus," =>
            'syncFromOrder(): `$financial` was loaded earlier in the same method FROM THE ORDER '
            . 'passed in, so the row is that order\'s own. The Order is the ownership-bearing '
            . 'object here, and the callers are the order observer and the gateway handlers, none '
            . 'of which take a financial id from request input.',

        "->where('id', \$financialId) ->lockForUpdate() ->first();" =>
            'ONE DECLARATION FOR TWO STATEMENTS, because they are byte-identical: the guarded read '
            . 'at the top of markFinancialAsPaid() (:289) and of markFinancialAsUnpaid() (:402). '
            . 'Both key on the primary key their ONE caller has already ownership-verified — see '
            . 'the class docblock and the caller ratchet below. `lockForUpdate()` is the real '
            . 'point of the statement: it serialises two concurrent paid/unpaid flips on the same '
            . 'row, which is why it is raw and inside a transaction rather than an Eloquent read. '
            . 'Each method then refuses a row already in the target state, so neither a '
            . 'double-paid nor a double-unpaid can move wallet income twice.',

        "->update([ 'payment_status' => 'paid'," =>
            'The paid write inside markFinancialAsPaid\'s transaction, on the row the '
            . 'lockForUpdate above returned and the status check accepted.',

        "->update([ 'payment_status' => 'unpaid'," =>
            'The unpaid write inside markFinancialAsUnpaid\'s transaction, on the row the '
            . 'lockForUpdate above returned and the status check accepted.',
    ];

    /**
     * The ONE caller of each wallet-moving method, and the ownership check it must carry.
     *
     * This is the containment. The methods are safe because of what their caller does; this
     * asserts the caller still does it and that no second caller has appeared.
     */
    private const WALLET_MOVING_METHODS = ['markFinancialAsPaid', 'markFinancialAsUnpaid'];

    public function test_the_service_layer_raw_query_census_is_exactly_this(): void
    {
        [$total, $scoped, $storeLess] = $this->census();

        /*
         * 25 -> 31 on 2026-10-31: `app/Services/StockMovement.php` (E13's stock-evidence record)
         * adds SIX raw statements, and every one of them names `store_id` -- so the store-less
         * count below is unchanged at eight. The table has no model and therefore no global scope,
         * which is exactly why each read and write carries the predicate at the call site rather
         * than relying on one: `record()` refuses a movement with no store at all,
         * `movedOutFor()` and its observed-fallback both predicate on it, and `attributeToOrder()`,
         * `unattributed()` and `forOrder()` do too.
         *
         * 31 -> 33 on 2026-10-31 [F237]: `StockMovement::attributionStateFor()` adds TWO more,
         * and both name `store_id`, so the store-less count below is still eight. They exist
         * because `attributeToOrder()` answers with a row count, and a count of zero conflates
         * "already attributed" (success) with "rows exist but did not move" (a failure that must
         * not be read as success) — so the state is asked as its own question. Same reasoning for
         * raw-over-Eloquent as the six above: this table is written on the checkout path and read
         * immediately afterwards, and a `Cachable` model would serve a pre-write row.
         */
        /*
         * 33 -> 39 on 2026-10-31 [F229 / D-31 req 1]: `PlanPurchaseIntent` adds SIX, and -- unlike
         * every previous delta in this file -- **none of them names `store_id`, so the store-less
         * count moves too (8 -> 14)**. That is the design and not an oversight: the table has no
         * `store_id` column, because a SaaS plan is bought by a USER from the platform. The
         * isolation is per-purchaser and is enforced before any disclosure. All six are declared
         * with their reason in STORE_LESS_BY_DESIGN above, which is what
         * `test_every_store_less_service_statement_is_declared_with_its_reason` requires.
         */
        $this->assertSame(
            39,
            $total,
            "The number of raw query-builder statements in app/Services moved. Every one is "
            . "invisible to every Eloquent global scope, so a new one needs a `store_id` "
            . "predicate written at the call site or a declaration in STORE_LESS_BY_DESIGN.\n  "
            . implode("\n  ", array_keys($storeLess))
        );

        $this->assertSame(25, $scoped, 'statements naming store_id');
        $this->assertCount(14, $storeLess, 'statements naming store_id nowhere');
    }

    public function test_every_store_less_service_statement_is_declared_with_its_reason(): void
    {
        [, , $storeLess] = $this->census();

        $undeclared = [];

        foreach ($storeLess as $site => $statement) {
            $matched = false;

            foreach (array_keys(self::STORE_LESS_BY_DESIGN) as $fragment) {
                if (str_contains($this->normalise($statement), $this->normalise($fragment))) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $undeclared[] = $site;
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            "A raw statement in app/Services names `store_id` nowhere and is not declared in "
            . "STORE_LESS_BY_DESIGN. Either scope it, or declare it there WITH THE REASON it must "
            . "be wider than one tenant — and if the reason is 'the caller checks', add a caller "
            . "ratchet like the one for the wallet-moving methods, because an unratcheted caller "
            . "is the whole shape of this risk.\n  " . implode("\n  ", $undeclared)
        );
    }

    public function test_no_declaration_outlives_the_statement_it_describes(): void
    {
        [, , $storeLess] = $this->census();

        $found = array_map(fn ($s) => $this->normalise($s), array_values($storeLess));
        $stale = [];

        foreach (array_keys(self::STORE_LESS_BY_DESIGN) as $fragment) {
            $normalised = $this->normalise($fragment);
            $hit = false;

            foreach ($found as $statement) {
                if (str_contains($statement, $normalised)) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                $stale[] = $fragment;
            }
        }

        $this->assertSame(
            [],
            $stale,
            'A STORE_LESS_BY_DESIGN declaration no longer matches any statement. If the statement '
            . 'was scoped or deleted, delete its declaration too — a stale exemption is how the '
            . "next unscoped statement hides.\n  " . implode("\n  ", $stale)
        );
    }

    /**
     * THE CONTAINMENT. `markFinancialAsPaid`/`markFinancialAsUnpaid` move
     * `store_wallets.total_income`, and they take a bare `int`. They are safe only because their
     * single caller verifies the row's ownership first.
     */
    public function test_each_wallet_moving_method_has_exactly_one_ownership_verified_caller(): void
    {
        foreach (self::WALLET_MOVING_METHODS as $method) {
            $callers = [];

            foreach ($this->firstPartyPhpFiles() as $file) {
                if (str_ends_with($file, 'app/Services/OrderFinancialService.php')) {
                    continue; // its own declaration and internal call
                }

                $source = (string) file_get_contents($file);

                if (str_contains($source, $method . '(')) {
                    $callers[] = str_replace(base_path() . '/', '', $file);
                }
            }

            $this->assertSame(
                ['app/Http/Controllers/StoreFinanceController.php'],
                $callers,
                $method . '() moves store_wallets.total_income and takes a bare int financial id. '
                . 'A NEW CALLER HAS APPEARED. Before this test goes green again, prove that caller '
                . 'resolves the financial row from the AUTHENTICATED principal\'s store — '
                . '`->where(\'store_id\', getCurrentStore())` or equivalent — and not from request '
                . 'input. A predicate built from `$request->store_id` is not a tenant check; it '
                . 'only compares the caller\'s own two values, and that pattern has produced four '
                . 'separate defects in this codebase.'
            );
        }

        // And the caller still does what makes the five raw statements safe.
        $caller = (string) file_get_contents(base_path('app/Http/Controllers/StoreFinanceController.php'));

        $this->assertStringContainsString(
            "canFinance('Edit Finance Payment Status')",
            $caller,
            'the permission gate in front of the wallet-moving path is gone'
        );

        $this->assertMatchesRegularExpression(
            "/DB::table\('store_order_financials'\)\s*->where\('id', \\\$id\)\s*->where\('store_id', \\\$storeId\)/",
            $caller,
            'StoreFinanceController::updateFinancialPaymentStatus no longer loads the financial '
            . 'row scoped to the caller\'s own store. THIS IS THE CHECK THE WHOLE SERVICE DEPENDS '
            . 'ON: without it, the id reaching markFinancialAsPaid() is unverified and a merchant '
            . 'can move another store\'s wallet balance.'
        );

        $this->assertMatchesRegularExpression(
            '/\$storeId\s*=\s*getCurrentStore\(\)/',
            $caller,
            'the store used in that predicate must come from the authenticated principal '
            . '(getCurrentStore()), never from request input'
        );
    }

    public function test_the_owned_path_inventory_still_does_not_reach_this_directory(): void
    {
        /*
         * The premise of this whole file, asserted rather than assumed — and asserted so that the
         * day somebody DOES extend `OwnedPathRawQueryInventoryTest` to `app/Services`, this test
         * fails and tells them these declarations are now duplicated there.
         */
        $owned = (string) file_get_contents(
            base_path('tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest.php')
        );

        $this->assertStringNotContainsString(
            "'app/Services/GatewayReference.php'",
            $owned,
            'OwnedPathRawQueryInventoryTest now classifies a service file. If its scan has been '
            . 'extended to app/Services, this file\'s declarations belong there instead of being '
            . 'maintained twice — move them and delete this test.'
        );
    }

    // ------------------------------------------------------------------ the scanner

    /** @return array{0:int,1:int,2:array<string,string>} total, scoped, store-less by site */
    private function census(): array
    {
        $total = 0;
        $scoped = 0;
        $storeLess = [];

        foreach (glob(base_path('app/Services/*.php')) ?: [] as $file) {
            $lines = file($file) ?: [];

            foreach ($lines as $index => $line) {
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                if (! preg_match(self::RAW_PATTERN, $line)) {
                    continue;
                }

                $total++;
                $statement = $this->statementFrom($lines, $index);
                $site = str_replace(base_path() . '/', '', $file) . ':' . ($index + 1);

                if (str_contains($statement, 'store_id')) {
                    $scoped++;
                } else {
                    $storeLess[$site] = $statement;
                }
            }
        }

        return [$total, $scoped, $storeLess];
    }

    /** The whole statement a site begins, so a predicate on a later line is not missed. */
    private function statementFrom(array $lines, int $index): string
    {
        $statement = '';

        for ($i = $index; $i < min($index + 25, count($lines)); $i++) {
            $statement .= $lines[$i];

            if (preg_match('/;\s*$/', rtrim($lines[$i]))) {
                break;
            }
        }

        return $statement;
    }

    /** Whitespace-insensitive, so a reformat does not invalidate a declaration. */
    private function normalise(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($text));
    }

    /** @return string[] */
    private function firstPartyPhpFiles(): array
    {
        $files = [];

        foreach (['app', 'routes', 'database', 'config'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
