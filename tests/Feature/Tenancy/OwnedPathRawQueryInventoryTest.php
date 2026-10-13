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
        $this->assertCount(9, $sites, "The owned-path raw query inventory changed:\n  "
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
     * THE CHATWOOT ABANDONED-CART RE-CHECK ITEM, established rather than asserted.
     *
     * The ANONYMOUS exposure is closed: both declarations now sit inside
     * `Route::middleware(['auth','xss','setlocate','verified'])` at routes/web.php:325-363, and
     * tests/Feature/Security/AnonymousSettingsRoutesTest drives all five of those routes
     * anonymously. That is re-asserted here from the live route table rather than from the file,
     * so a group brace moving (which has broken newsletter signup in this project once already)
     * is caught.
     *
     * The CROSS-TENANT question is separate and is answered at the service layer:
     * AbandonedCartController::send passes `getCurrentStore()` plus a caller-supplied customer
     * id to ChatwootAbandonedCartNotificationService::send, which resolves the customer as
     * `Customer::where('id', $customerId)->where('store_id', $storeId)->first()` and throws
     * 'Customer not found.' otherwise -- BEFORE any outbound call. So a foreign customer id
     * cannot produce a message.
     *
     * THAT HALF IS ASSERTED AS A SOURCE PROPERTY, NOT AS BEHAVIOUR, AND THE REASON IS A
     * LIMITATION, NOT A PREFERENCE: the service's first two checks read
     * `chatwoot_notification_templates` and `chatwoot_integrations`, and NO MIGRATION ANYWHERE
     * CREATES EITHER TABLE -- verified across database/ and all 69 packages/. They are in the
     * same undeclared-table family as GATE F. An end-to-end test would therefore fail at the
     * template lookup and never reach the ownership predicate, which would be a green test
     * proving nothing. app/Services/** is also explicitly outside this cycle's scope, so the
     * predicate is pinned here and the behavioural proof is recorded as blocked.
     */
    public function test_the_chatwoot_abandoned_cart_routes_are_gated_and_tenant_filtered(): void
    {
        foreach (['chatwoot.abandoned-carts', 'chatwoot.abandoned-carts.send'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} has disappeared.");

            $stack = app('router')->gatherRouteMiddleware($route);

            $this->assertContains(
                \Illuminate\Auth\Middleware\Authenticate::class,
                $stack,
                "{$name} is reachable anonymously again. Before it was grouped, POST "
                . '/chatwoot/abandoned-carts/send answered 302 FROM THE CONTROLLER, i.e. it ran '
                . 'the loop over caller-supplied customer_ids and drove outbound WhatsApp '
                . 'traffic with no credential at all.'
            );
            $this->assertContains(\App\Http\Middleware\EmailIsVerified::class, $stack);
        }

        $service = (string) file_get_contents(
            base_path('app/Services/ChatwootAbandonedCartNotificationService.php')
        );

        $this->assertStringContainsString("Customer::where('id', \$customerId)", $service);
        $this->assertStringContainsString("->where('store_id', \$storeId)", $service);
        $this->assertStringContainsString("'Customer not found.'", $service);

        $controller = (string) file_get_contents(
            base_path('app/Http/Controllers/newsyria/AbandonedCartController.php')
        );

        // The controller must keep passing ITS OWN store, never one from the request. If this
        // ever becomes `$request->store_id`, the service's predicate is satisfied by the
        // attacker and the ownership test evaporates.
        $this->assertStringContainsString('$storeId = getCurrentStore();', $controller);
        $this->assertStringNotContainsString('$request->store_id', $controller);

        // And the behavioural half is BLOCKED, by name, so the dependency stays visible.
        foreach (['chatwoot_notification_templates', 'chatwoot_integrations'] as $table) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasTable($table),
                "`{$table}` now exists. The end-to-end cross-tenant test for "
                . 'chatwoot.abandoned-carts.send is no longer blocked and should be written: '
                . 'two stores, two customers, and an assertion that a foreign customer id '
                . 'produces no outbound call.'
            );
        }
    }
}
