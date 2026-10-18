<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ITEM 7 OF THE BULK-DELETE BRIEF -- "re-review the remaining direct-query tenant escapes in the
 * paths you own" -- kept as a ratchet rather than a paragraph, because the paragraph it replaces
 * had already gone stale.
 *
 * `DB::table()` and friends build their own query from nothing, so NO Eloquent global scope ever
 * sees them (proven by execution in
 * EnforcedModelIsolationTest::test_raw_db_table_queries_bypass_the_scope_entirely). At such a
 * site the ONLY control is an explicit predicate written there. This enumerates every such site
 * in the paths this workstream owns and asserts each one either carries a tenant predicate or is
 * named here as deliberately platform-global.
 *
 * WHY A TEST AND NOT A NOTE: App\Scopes\StoreScope's docblock carried
 * "app/Http/Controllers/RoleController.php:182 is a live example" long after that site was
 * DELETED by the SEC-F3 fix -- the `DB::table('role_user')` /
 * `User::whereIn(...)->update(['type' => ...])` pair was the privilege-escalation primitive
 * itself, and only its commented record survives at RoleController.php:232-240. A reader
 * checking that citation would have found nothing and drawn their own conclusion about how
 * reliable the rest of the note is. The citation is corrected and this test now holds the
 * inventory.
 */
class OwnedPathRawQueryInventoryTest extends TestCase
{
    /**
     * The files this workstream owns that can contain a query at all.
     *
     * @return array<int, string>
     */
    private function ownedFiles(): array
    {
        $files = array_merge(
            [
                base_path('app/Http/Controllers/ModuleController.php'),
                base_path('app/Http/Controllers/UserController.php'),
                base_path('app/Http/Controllers/StoreController.php'),
                base_path('app/Http/Controllers/CustomerController.php'),
                base_path('app/Http/Controllers/RoleController.php'),
                base_path('app/Http/Controllers/OrderNoteController.php'),
                base_path('app/Http/Controllers/StoreFinanceController.php'),
            ],
            glob(base_path('app/Policies/*.php')) ?: [],
            glob(base_path('app/Http/Middleware/*.php')) ?: [],
            glob(base_path('routes/*.php')) ?: []
        );

        sort($files);

        return $files;
    }

    private const RAW_ENTRY_POINT_PATTERN =
        '/\\\\?DB::(table|select|insert|update|delete|statement|unprepared)\\s*\\(/';

    /**
     * @return array<string, string> "relative/path.php:line" => the source line, trimmed
     */
    private function rawSites(): array
    {
        $sites = [];

        foreach ($this->ownedFiles() as $file) {
            if (!is_file($file)) {
                continue;
            }

            $relative = ltrim(str_replace(base_path(), '', $file), '/');

            foreach (file($file) ?: [] as $index => $line) {
                $trimmed = ltrim($line);

                // A docblock or a commented-out record of a deleted query is not a query. This
                // matters here specifically: RoleController.php:232-240 keeps the SEC-F3
                // primitive as a comment on purpose.
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }

                if (preg_match(self::RAW_ENTRY_POINT_PATTERN, $line)) {
                    $sites[$relative . ':' . ($index + 1)] = rtrim($trimmed);
                }
            }
        }

        return $sites;
    }

    /**
     * Each site is listed with WHY it is safe. A new site fails this test by name and has to be
     * classified before it can ship.
     */
    public function test_every_raw_query_in_the_owned_paths_is_tenant_scoped_or_declared_global(): void
    {
        $classified = [
            // A tenant predicate written at the call site.
            'app/Http/Controllers/StoreController.php' => 'tenant',
            'app/Http/Controllers/StoreFinanceController.php' => 'tenant',
            // The tenant filter itself: these three ARE the predicate.
            'app/Http/Middleware/BulkDeleteTenantGuard.php' => 'tenant',
            // COMPARISON INPUT -- a fourth category, and the narrowest one. P01-SEC-2's
            // OrderDocumentAccessGuard reads the order row by id with NO tenant predicate ON
            // PURPOSE: the statement exists to FETCH `store_id` so the guard can compare it
            // against the caller's tenant and refuse. Writing
            // `->where('store_id', getCurrentStore())` here would collapse "another merchant's
            // order" into "no such order" and remove the guard's ability to tell a cross-tenant
            // request from a malformed id at all.
            //
            // IT IS NOT CLASSIFIED 'tenant', even though the literal string 'store_id' appears
            // in the statement and the predicate check below would therefore have PASSED. That
            // pass would have been an accident of the column list in `->first([...])`, not a
            // predicate, and a category that is satisfied by an accident is the kind of control
            // this whole inventory exists to catch. So the classification states what is
            // actually true and the control it depends on is asserted at the bottom of this
            // method instead.
            'app/Http/Middleware/OrderDocumentAccessGuard.php' => 'comparison',
            // PLATFORM-GLOBAL BY DESIGN. The framework's own `migrations` bookkeeping table has
            // no store_id and must never acquire a tenant predicate -- XSS::handle() compares
            // on-disk migration filenames against it to decide whether the platform operator is
            // sent to the updater.
            'app/Http/Middleware/XSS.php' => 'platform',
            // WEBHOOK INGEST, which is a third category and not a loophole. The two sites in
            // routes/web.php are inside the Zid webhook closure: there is NO AUTHENTICATED
            // TENANT on that request at all (it is a server-to-server POST from Zid,
            // authenticated by a shared secret with hash_equals, abort(401) otherwise -- pinned
            // by tests/Feature/Security/ZidWebhookSecretTest), and the `store_id` they carry is
            // an EXTERNAL ZID identifier taken from the payload, not a local stores.id. A
            // `getCurrentStore()` predicate here would compare a Zid string against a local
            // primary key and match nothing. The control is the secret, and it is asserted
            // below.
            //
            // The abort(401) is deliberately NOT changed to 403 despite this project's
            // "denials must be 403" rule: that rule exists because repo B's
            // lib/network/network.dart maps any 401 into a forced logout that clears
            // preferences, and the Flutter app never calls this endpoint -- it is a Zid-side
            // callback. 401 is also the correct status for a credential failure on a webhook.
            'routes/web.php' => 'webhook',
        ];

        $sites = $this->rawSites();

        $unclassified = [];
        $missingPredicate = [];

        foreach ($sites as $site => $source) {
            $file = substr($site, 0, (int) strrpos($site, ':'));

            if (!array_key_exists($file, $classified)) {
                $unclassified[] = $site . '   ' . $source;
                continue;
            }

            if ($classified[$file] !== 'tenant') {
                continue;
            }


            // The predicate may be on a following line in a fluent chain, so the check reads the
            // statement rather than the single line.
            $statement = $this->statementAt($site);

            // `$relatedColumn` is accepted alongside a literal 'store_id' for ONE reason, and
            // the reason is asserted rather than trusted: BulkDeleteTenantGuard's
            // DERIVED_OWNERSHIP branch binds the tenant column from that constant instead of
            // writing it inline (user_coupons has no store_id, so its tenant is reached through
            // coupons.store_id). The assertion below proves every entry in that constant names
            // 'store_id', so the variable cannot stand for something weaker.
            if (!str_contains($statement, 'store_id') && !str_contains($statement, '$relatedColumn')) {
                $missingPredicate[] = $site . '   ' . $source;
            }
        }

        $this->assertSame([], $unclassified,
            "A raw query entry point appeared in an owned path that this inventory does not "
            . "classify. No Eloquent global scope can reach it, so it needs an explicit tenant "
            . "predicate or an explicit 'platform' classification here:\n  "
            . implode("\n  ", $unclassified));

        $this->assertSame([], $missingPredicate,
            "A raw query in a tenant-classified owned file has no store_id predicate in its "
            . "statement:\n  " . implode("\n  ", $missingPredicate));

        // The count is asserted too, so a site that DISAPPEARS is noticed as well as one that
        // appears -- a vanished predicate is as interesting as a new query.
        $this->assertCount(10, $sites, "The owned-path raw query inventory changed:\n  "
            . implode("\n  ", array_keys($sites)));

        // Why `$relatedColumn` counts as a tenant predicate: every DERIVED_OWNERSHIP entry
        // resolves to a `store_id` column on the related table. If one ever does not, the
        // acceptance above becomes unsound and this fails first.
        foreach (\App\Http\Middleware\BulkDeleteTenantGuard::DERIVED_OWNERSHIP as $type => $spec) {
            $this->assertSame('store_id', $spec[2],
                "DERIVED_OWNERSHIP['{$type}'] no longer resolves ownership through a store_id "
                . 'column, so the raw query at BulkDeleteTenantGuard.php that binds it can no '
                . 'longer be counted as tenant-scoped by this inventory.');
        }

        // THE WEBHOOK'S ACTUAL CONTROL, asserted so the 'webhook' classification above is not a
        // free pass. If the secret comparison ever leaves this closure, the two raw writes
        // become anonymous.
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString('hash_equals($configuredSecret, $headerSecret)', $routes);
        $this->assertStringContainsString('abort(401)', $routes);
        $this->assertFileExists(base_path('tests/Feature/Security/ZidWebhookSecretTest.php'));

        // And the uninstall delete stays keyed on the payload's own store id, never widened.
        $this->assertStringContainsString(
            "DB::table('zid_stores')->where('store_id', \$storeId)->delete();",
            $routes,
            'The Zid uninstall delete is no longer keyed on the webhook payload\'s store id.'
        );

        // THE 'comparison' CLASSIFICATION'S ACTUAL CONTROL, asserted for the same reason the
        // webhook's is: the category says "the predicate is a comparison two lines later", so
        // if that comparison leaves the file the raw read becomes an unscoped cross-tenant
        // fetch feeding a handler that renders a document. These assert the comparison and the
        // fail-closed behaviour it depends on, not merely that the class exists.
        $guard = (string) file_get_contents(base_path('app/Http/Middleware/OrderDocumentAccessGuard.php'));

        // PINNED ON THE COMPARISON, NOT THE WHOLE STATEMENT, and the difference is a lesson this
        // ratchet taught at its own expense. The original assertion matched
        // `return $storeId === (int) $currentStore;` verbatim. A later fix (S14, the mixed-session
        // false denial) reshaped that statement into a ternary so the merchant limb could fall
        // through to the shopper limb instead of returning its own verdict -- the CONTROL was
        // untouched and strictly improved, but the exact text vanished and this test went red on
        // a change that strengthened the thing it guards.
        //
        // A ratchet that fails on a safe refactor of the code it protects teaches people to edit
        // the ratchet, which is how one stops meaning anything. Matching the comparison alone
        // keeps the real claim -- the order's store IS compared against the caller's -- while
        // surviving the statement being wrapped, returned early from, or assigned.
        $this->assertStringContainsString('$storeId === (int) $currentStore', $guard,
            'OrderDocumentAccessGuard no longer compares the order\'s store against the '
            . "caller's, so the raw read in its handle() is now an unscoped cross-tenant fetch "
            . "and the 'comparison' classification above is no longer true.");

        $this->assertStringContainsString('if (empty($currentStore) || $storeId === null) {', $guard,
            'OrderDocumentAccessGuard no longer fails closed when either side has no store. An '
            . 'order with a NULL store_id is invisible to every store_id predicate in the '
            . 'application and must not become visible through this guard.');

        $this->assertStringContainsString('getCurrentStore($user->id)', $guard,
            'OrderDocumentAccessGuard resolves the caller\'s store without passing the user id. '
            . 'getCurrentStore() with no argument reads the MUTABLE default guard, which can '
            . 'hold a Customer, and would then resolve null and fail a legitimate merchant.');

        $this->assertFileExists(base_path('tests/Feature/Security/OrderDocumentAccessTest.php'));
        $this->assertFileExists(base_path('tests/Feature/Security/OrderDocumentRouteResolutionTest.php'));
    }

    /**
     * The whole statement a `path:line` site belongs to, so a fluent chain's predicate on the
     * next line is not missed.
     */
    private function statementAt(string $site): string
    {
        [$relative, $line] = explode(':', $site);

        $lines = file(base_path($relative)) ?: [];
        $start = ((int) $line) - 1;
        $statement = '';

        for ($i = $start; $i < min($start + 12, count($lines)); $i++) {
            $statement .= $lines[$i];

            if (str_contains($lines[$i], ';')) {
                break;
            }
        }

        return $statement;
    }
    /**
     * THE CHATWOOT ABANDONED-CART ITEM IS CLOSED BY REMOVAL, not by a gate.
     *
     * This method used to pin two things: that both /chatwoot/abandoned-carts routes carried
     * `auth` + `EmailIsVerified`, and that the service resolved the customer with a store_id
     * predicate. Both are moot -- the routes, the controller and the service were deleted when
     * the legacy Chatwoot integration was retired.
     *
     * What replaces them is the stronger assertion: the surface is ABSENT. A deleted route
     * cannot be reachable anonymously and cannot leak across tenants. LegacyChatwootRemovedTest
     * owns the full retirement inventory; this keeps the tenancy-side claim honest in the file
     * that made it, so a reader of the raw-query inventory is not left believing a gate is
     * still being enforced somewhere.
     *
     * The two tables are deliberately NOT asserted absent. They are retained in production as
     * the store -> account_id/inbox_id mapping the replacement integration migrates from; see
     * database/migrations/_gate_f_pending/README.md. Asserting on them here would couple this
     * test to a data-retention decision that is not its business.
     */
    public function test_the_chatwoot_abandoned_cart_surface_is_retired(): void
    {
        foreach (['chatwoot.abandoned-carts', 'chatwoot.abandoned-carts.send'] as $name) {
            $this->assertFalse(
                Route::has($name),
                "Route {$name} is back. The legacy Chatwoot integration was retired; if a "
                . 'replacement is being built it belongs to the Store Syria x Lynomia project '
                . 'and must not reuse these names or this controller.'
            );
        }

        foreach ([
            'app/Http/Controllers/newsyria/AbandonedCartController.php',
            'app/Services/ChatwootAbandonedCartNotificationService.php',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path), "{$path} is back.");
        }
    }
}
