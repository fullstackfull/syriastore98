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
            // SUBDIRECTORIES TOO, and this was not pedantry: P1-INV-17 moved the ownership
            // predicate shared by the two module guards into
            // app/Http/Middleware/Concerns/ComparesRowOwnership.php, taking its `DB::table()`
            // read with it -- and a non-recursive glob would have let the raw query leave this
            // inventory silently, which is precisely the escape this file exists to prevent. The
            // site count caught it (11 -> 10). Any future Concerns/, Traits/ or similar folder
            // under Middleware is covered by this.
            glob(base_path('app/Http/Middleware/*/*.php')) ?: [],
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
            // THE SECOND 'comparison' SITE, and it is the same category for the same reason.
            // P1-INV-14's ModuleOrderMutationGuard reads the target row by id with no tenant
            // predicate in order to FETCH `store_id` and compare it. Six anonymous admin
            // mutations in two vendored module route files -- two of them writing the host
            // `orders` table, one of those a money write -- are what it refuses, and the table it
            // reads varies per uri (`orders`, `auction_product_orders`,
            // `auction_product_settings`), which is why the statement is `DB::table($target[...])`
            // rather than Eloquent: two of the three models are vendored and carry no scope, and
            // the third is Cachable, so an Eloquent read here could serve a mutated in-memory
            // instance. Its controls are asserted at the bottom of this method alongside the
            // sibling guard's.
            // THE PREDICATE ITSELF, shared by ModuleOrderMutationGuard (P1-INV-14, six anonymous
            // module mutations) and ModuleResourceOwnershipGuard (P1-INV-17, four cross-tenant
            // writes behind Route::resource, two of them on the HOST products table). Same
            // 'comparison' category and the same reason: the read deliberately carries no store
            // predicate BECAUSE it exists to fetch `store_id` so the caller's tenant can be
            // compared against it and the request refused. Writing
            // `->where('store_id', getCurrentStore())` here would collapse "another merchant's
            // row" into "no such row" and remove the guards' ability to tell a cross-tenant
            // request from a malformed id.
            //
            // It lives in one place rather than two on purpose: ModuleOrderMutationGuard shipped
            // with BOTH a false "the permission is not seeded" premise and a resolveId() that
            // fell through to the route segment, each found by review rather than by a test, so a
            // second copy would have inherited whichever version was current that day.
            'app/Http/Middleware/Concerns/ComparesRowOwnership.php' => 'comparison',
            // P1-INV-20's REFUND GUARD. 'comparison', and the classification is the FILE's, not
            // the site's -- this inventory keys on the path. The first draft of this entry split
            // its two reads into two categories, which the inventory cannot express; the entry now
            // states the file's weakest claim and describes both reads under it.
            //
            // `:150` is the slug lookup and it is why the file is 'comparison': `stores` has no
            // `store_id` and cannot acquire one -- it IS the tenant table -- and the read exists
            // to resolve an id so it can be compared with the signed-in customer's own
            // `store_id` four lines later. The slug is CALLER-SUPPLIED and is used only to NAME a
            // store; the comparison is the authorisation. CLAUDE.md records four separate defects
            // produced by treating a predicate built from request input as a tenant check, so the
            // distinction between naming a store and authorising one is the whole point, and the
            // comparison is asserted at the bottom of this method.
            //
            // `:111` is the `$ownedByCaller` read and it is STRICTER than the category requires:
            // it carries `->where('store_id', $store)` AND `->where('customer_id', ...)` in the
            // statement itself. It exists because the trait's set predicate answers a question
            // about STORES while the handler (LotteryPrizesController:870) also keys on the
            // customer -- a guard checking only the store would be WEAKER than the handler it
            // protects, letting one shopper refund another shopper's ticket in the same store.
            'app/Http/Middleware/LotteryRefundOwnershipGuard.php' => 'comparison',
            // P1-INV-20's THIRD SITE, and the only WRITE in this inventory.
            // `LotteryMailTemplatePrerequisite:115` inserts the authenticated merchant's own
            // `email_templates` rows before a draw, because LotteryResultController:157 does
            // `$FailTemplate['id']` on null and 500s AFTER the draw has written its order and
            // `lottery_customers` rows -- measured, on an otherwise entirely valid own-store draw.
            //
            // 'platform' is the honest category and not a convenience: `email_templates` has NO
            // `store_id` column at all. It is keyed on `created_by`, which is the owner, and the
            // insert sets `created_by` to the authenticated user's own id -- so the row cannot
            // land in another tenant even though no `store_id` predicate is possible. The control
            // is that the id comes from `auth()->user()`, never from request input, and it is
            // asserted at the bottom of this method.
            'app/Http/Middleware/LotteryMailTemplatePrerequisite.php' => 'platform',
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
        //
        // 10 -> 11 with P1-INV-14: the shared trait's `readRowStore()`, classified 'comparison'
        //       above and its controls asserted below.
        // 11 -> 13 with P1-INV-18: the trait's SET predicate, which is two queries by design --
        //       limb 1 runs the handler's own `whereIn` WITHOUT a store filter and asks whether
        //       any matched row is foreign (the collation-safe core), and limb 2 asks whether
        //       every requested value is accounted for by an owned row. Collapsing them into one
        //       query would be collapsing two different questions; the first version of that
        //       predicate asked only the second and was bypassable on MySQL.
        // 13 -> 17 with P1-INV-20, FOUR sites across two files:
        //       LotteryRefundOwnershipGuard  -- the `$ownedByCaller` read, and the slug lookup
        //       LotteryMailTemplatePrerequisite -- the existence READ and the INSERT
        // The prerequisite's read is raw rather than Eloquent on purpose, and that is the whole
        // reason it is a second site here: App\Models\EmailTemplate is Cachable, the Eloquent
        // builder's pluck() caches even an EMPTY result, and DB::table()->insert() flushes
        // nothing -- so an Eloquent read behind that raw write served the pre-write empty set and
        // every draw after the first inserted three more rows. Measured at 3 -> 6 across two
        // draws. Adding a raw read to this inventory was the correct trade: it is visible and
        // classified here, where an invisible stale cache was not.
        $this->assertCount(17, $sites, "The owned-path raw query inventory changed:\n  "
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

        // THE THIRD 'comparison' SITE'S CONTROLS -- P1-INV-20's refund guard. Same discipline:
        // pinned on the comparison and on where the authority comes from, not on whole statements.
        $refund = (string) file_get_contents(
            base_path('app/Http/Middleware/LotteryRefundOwnershipGuard.php')
        );

        // WHAT THIS FILE'S 'comparison' CLASSIFICATION ACTUALLY RESTS ON, after an adversarial
        // review corrected an earlier, STRICTER version of the guard.
        //
        // That version also required the slug's store to equal the signed-in customer's own
        // `store_id`, and these assertions pinned that comparison. It was a regression: the
        // module's own screen (`refund_lottery():862`) lists a ticket by
        // `where('store_id', $slugStore)->where('customer_id', $caller)`, so a shopper registered
        // at store A who bought on store B's storefront saw their ticket offered on B's form and
        // was then refused permanently. The mismatch is the PREMISE of the defect, not an anomaly.
        //
        // With that limb gone, the slug NAMES the store and the two remaining checks authorise
        // against it -- which is still not "the slug is the tenant predicate", and that is what
        // these assertions now pin.
        $this->assertStringContainsString("where('store_id', \$store)", $refund,
            'LotteryRefundOwnershipGuard no longer confines its ownership read to the resolved '
            . "store, so the caller-supplied slug is no longer compared with anything and the "
            . "'comparison' classification above is no longer true.");

        $this->assertStringContainsString("where('customer_id', \$customer->id)", $refund,
            'LotteryRefundOwnershipGuard no longer keys the ownership read on the authenticated '
            . 'customer, so it is now WEAKER than the handler it guards: one shopper could refund '
            . "another shopper's ticket within the same store.");

        // PINNED ON THE CALL SHAPE, NOT THE BARE NAME, and this is the SECOND time in this one
        // method that distinction was needed -- the first version was
        // `assertStringContainsString('noMatchedRowOutsideStore(', ...)`, which the guard's own
        // DOCBLOCK satisfies at :47, where it names the method to explain what it calls. Deleting
        // the real call left this assertion green. Found by adversarial review, and it is the same
        // mistake the `userTemplateDefaultData` assertion below already records.
        $this->assertStringContainsString(
            '$this->noMatchedRowOutsideStore(self::TABLE, self::COLUMN, [$code], $store)',
            $refund,
            'LotteryRefundOwnershipGuard no longer runs the shared set predicate. Its own single-'
            . 'value read is LOOSER than it looks under MySQL\'s _ci collation -- measured in '
            . 'tests/Support/Security/bin/verify-lottery-refund-collation-on-mysql.php, where it '
            . 'reports a request owned while the handler\'s unscoped ->first() reaches a foreign '
            . 'row.');

        $this->assertFileExists(base_path('tests/Feature/Security/LotteryTicketWriterTest.php'));
        $this->assertFileExists(
            base_path('tests/Support/Security/bin/verify-lottery-refund-collation-on-mysql.php')
        );

        // THE 'platform' WRITE'S CONTROL -- the mail-template prerequisite. `email_templates` has
        // no store_id, so no predicate is possible and the classification cannot be checked the
        // usual way. What makes it safe is that `created_by` comes from the authenticated user and
        // never from request input, which IS checkable.
        $prereq = (string) file_get_contents(
            base_path('app/Http/Middleware/LotteryMailTemplatePrerequisite.php')
        );

        $this->assertStringContainsString("'created_by' => \$userId,", $prereq,
            'LotteryMailTemplatePrerequisite no longer stamps the row with the owner id it was '
            . 'given. Without it the insert could land a template under another tenant.');

        // THE F2 CONTROL: the existence read must go through the SAME builder as the write.
        // App\Models\EmailTemplate is Cachable and the Eloquent builder's pluck() caches even an
        // empty result, while DB::table()->insert() flushes nothing -- so an Eloquent read here
        // served the pre-write empty set and the SECOND draw re-inserted all three rows. Measured
        // at email_templates 3 -> 6 across two draws, and pinned behaviourally by
        // LotteryTicketWriterTest::test_a_second_draw_does_not_insert_the_templates_again.
        $this->assertStringContainsString("DB::table('email_templates')\n                ->whereIn('name', self::TEMPLATES)", $prereq,
            'LotteryMailTemplatePrerequisite no longer reads through the query builder it writes '
            . 'with. An Eloquent read on this Cachable model serves a cached empty set that the '
            . 'raw insert does not flush, and every draw after the first inserts three more rows.');

        $this->assertStringNotContainsString('EmailTemplate::query()', $prereq,
            'LotteryMailTemplatePrerequisite is reading through the Cachable Eloquent builder '
            . 'again. See the assertion above: this is what made the second draw duplicate.');

        // PINNED STRUCTURALLY BECAUSE IT CANNOT BE PINNED BEHAVIOURALLY, and that is stated rather
        // than dressed up. `auth()` with no guard name reads the MUTABLE default guard, which can
        // hold a Customer or DeliveryBoy -- CustomAuth logs a token into whichever guard matches
        // its tokenable class. The `instanceof` is the sibling guards' own rule
        // (LotteryDrawOwnershipGuard:156-168) applied here.
        //
        // A removal check on it comes back GREEN, and the reason is that it is NOT REACHABLE
        // TODAY: config/auth.php defaults to `web`, the route's own `auth` gates on `web`, nothing
        // in app/ calls Auth::shouldUse(), and LotteryDrawOwnershipGuard's explicit
        // auth('web')->user() null-limb would deny first in any case. So no test can drive it, and
        // claiming it is covered by one would be false. This assertion is what stops it being
        // deleted as dead code by someone who runs the removal check and sees green.
        $this->assertStringContainsString('if ($user instanceof User) {', $prereq,
            'LotteryMailTemplatePrerequisite no longer checks that the principal is a User before '
            . 'writing. auth() reads the mutable default guard, so a Customer or DeliveryBoy id '
            . 'could reach created_by -- bounded by the users.id foreign key, but not a control.');

        $this->assertStringContainsString('$this->ensureTemplatesExist((int) $user->id)', $prereq,
            'LotteryMailTemplatePrerequisite no longer takes the owner id from the authenticated '
            . 'user. An id read from request input here would write into any tenant -- the exact '
            . 'pattern CLAUDE.md names as the most productive defect shape in this codebase.');

        // PINNED ON THE CALL SHAPE, NOT THE NAME. The first version of this assertion matched the
        // bare string `userTemplateDefaultData` and failed immediately -- on the guard's own
        // DOCBLOCK, which names that method in order to explain why it is deliberately NOT called.
        // A ratchet that reddens on the comment explaining the control is worse than no ratchet.
        $this->assertStringNotContainsString("table('user_email_templates')", $prereq,
            'LotteryMailTemplatePrerequisite now writes user_email_templates join rows. Those are '
            . 'what decides whether mail is SENT, so creating them would start sending '
            . 'lottery-loser email on behalf of a merchant who never configured a template. '
            . 'Removing a crash must not become an outbound behaviour change.');

        // THE SECOND 'comparison' SITE'S CONTROLS -- the shared trait, which is where the raw
        // read and the store comparison now live for BOTH module guards. Pinned on the
        // comparison and the fail-closed limbs rather than on whole statements, for the reason
        // the sibling's block above records at its own expense: a ratchet that reddens on a safe
        // refactor of the code it protects teaches people to edit the ratchet.
        $shared = (string) file_get_contents(
            base_path('app/Http/Middleware/Concerns/ComparesRowOwnership.php')
        );

        $this->assertStringContainsString('(int) $rowStore === (int) $currentStore', $shared,
            'ComparesRowOwnership no longer compares the row\'s store against the caller\'s, so '
            . "the raw read in readRowStore() is now an unscoped cross-tenant fetch feeding both "
            . "module guards, and the 'comparison' classification above is no longer true.");

        $this->assertStringContainsString('empty($currentStore) || empty($rowStore)', $shared,
            'ComparesRowOwnership no longer fails closed when either side has no store. Without '
            . 'it, a caller with no resolvable store and a row with a NULL store_id compare as '
            . '`0 === 0` -- two absences matching, which is not ownership. Both states are real: '
            . 'the auction tables declare store_id nullable, and getCurrentStore() returns null '
            . 'for a merchant with no current_store and no store of their own.');

        $this->assertStringContainsString('getCurrentStore($user->id)', $shared,
            'ComparesRowOwnership resolves the caller\'s store without passing the user id. '
            . 'getCurrentStore() with no argument reads the MUTABLE default guard, which can hold '
            . 'a Customer, and would then resolve the User whose id happens to equal the '
            . "customer's. This assertion is the ONLY coverage that control has -- no behavioural "
            . 'test can reach it, because the guards deny a non-User principal before this line.');

        $this->assertStringContainsString("return ['unreadable table', null];", $shared,
            'ComparesRowOwnership no longer fails closed when the target table cannot be read. A '
            . 'module registers its routes before its migrations have necessarily run, so a '
            . 'missing table is a real state and must deny rather than raise past the guard.');

        $this->assertStringContainsString('filter_var((string) $value, FILTER_VALIDATE_INT)', $shared,
            'ComparesRowOwnership no longer parses ids strictly. `is_numeric` accepts \'5.9\' and '
            . 'rejects \'5abc\', while MySQL coerces \'5abc\' to row 5 and rejects \'5.9\' -- so the '
            . 'loose form let a guard authorise one row while the handler mutated another. '
            . 'Measured in tests/Support/Security/bin/'
            . 'verify-module-order-mutation-id-coercion-on-mysql.php.');

        // BOTH GUARDS MUST STILL USE IT. A guard that stopped using the trait would take its own
        // copy of the predicate with it, which is the drift this arrangement exists to prevent.
        foreach ([
            'app/Http/Middleware/ModuleOrderMutationGuard.php',
            'app/Http/Middleware/ModuleResourceOwnershipGuard.php',
        ] as $guardFile) {
            $this->assertStringContainsString(
                'use ComparesRowOwnership;',
                (string) file_get_contents(base_path($guardFile)),
                $guardFile . ' no longer uses the shared ownership trait, so it now carries its '
                . 'own copy of the predicate and the two can drift.'
            );
        }

        $this->assertFileExists(base_path('tests/Feature/Security/ModuleResourceOwnershipTest.php'));
        $this->assertFileExists(base_path('tests/Feature/Security/ModuleOrderMutationGuardTest.php'));
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
