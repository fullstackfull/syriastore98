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
     * THE CLASSIFICATIONS THIS INVENTORY UNDERSTANDS, and what each one claims.
     *
     * `'derivation'` was added for P1-INV-19 because `'comparison'` had stopped describing one of
     * its members, and an adversarial review caught the drift. The distinction is not cosmetic:
     *
     *   'tenant'      the statement carries its own `store_id` predicate. The only category whose
     *                 members are CHECKED below; every other is an exemption.
     *   'comparison'  the read deliberately carries NO predicate because it exists to FETCH
     *                 `store_id` so the caller's tenant can be compared against it and the request
     *                 REFUSED. A reader auditing this bucket may assume every member refuses
     *                 cross-tenant access.
     *   'derivation'  the read carries no predicate because it exists to fetch the authoritative
     *                 value and OVERWRITE what the caller supplied. It never refuses on tenant,
     *                 and conflating it with 'comparison' would make the sentence above false for
     *                 one member of that bucket.
     *   'platform'    the table has no `store_id` and cannot acquire one.
     *   'webhook'     there is no authenticated tenant on the request at all.
     */
    private const CLASSIFICATIONS = ['tenant', 'comparison', 'derivation', 'platform', 'webhook'];
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
            // AND app/Listeners/, FOR THE SAME REASON THE MIDDLEWARE SUBDIRECTORY WAS ADDED.
            // P1-INV-21 put a tenancy-relevant raw query in app/Listeners/ --
            // EnforceLotteryTicketPrice reads `lottery_settings.ticket_price` to overwrite a
            // client-posted amount -- and the glob did not reach it, so the site left this
            // inventory silently and only the platform-wide count in
            // tests/Feature/Tenancy/RawQueryTenancyInventoryTest noticed. That is the second time
            // a path this file claims to own has slipped out of its own glob; the first was the
            // Middleware subdirectory above.
            //
            // A model-event listener is exactly where such a query belongs when the handler is
            // vendored, so this will not be the last one.
            glob(base_path('app/Listeners/*.php')) ?: [],
            // AND app/Http/Controllers/Concerns/, WHICH IS THE THIRD TIME. Raised by the read-only
            // review of P1-ORD-02 criterion 2: ResolvesPendingPartialPayment reads
            // `order_partial_payments` to establish whose outstanding balance a checkout is paying,
            // and the glob did not reach the directory, so the site was counted only by the
            // platform-wide total in RawQueryTenancyInventoryTest and never classified per site --
            // exactly the escape the Middleware-subdirectory note above says this file exists to
            // prevent, one directory over.
            //
            // A Concern is where a tenancy predicate belongs when the same question is asked by a
            // controller and a service, so this will not be the last one either.
            glob(base_path('app/Http/Controllers/Concerns/*.php')) ?: [],
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
            // P1-ORD-02 criterion 2. ResolvesPendingPartialPayment reads the one
            // `order_partial_payments` row that states what a shopper still owes, and the statement
            // carries `->where('store_id', $store)` -- a store resolved from the ROUTE, never from
            // the body -- so 'tenant' is the honest category and the predicate check below is
            // satisfied by the predicate rather than by an accident of the column list.
            //
            // The CUSTOMER half of the ownership question is deliberately NOT in the statement, and
            // that is the one thing a reader should not mistake for an omission. The module declares
            // `customer_id` as a VARCHAR and inserts an integer into it, and Laravel binds an
            // integer as PDO::PARAM_INT, so MySQL converts the COLUMN to a number for the
            // comparison. MEASURED on MySQL 8.0.46 against the module's own schema
            // (tests/Support/Security/bin/verify-pending-partial-payment-lookup-on-mysql.php):
            // `->where('customer_id', 7)` matched rows holding '7', '7abc', ' 7' AND '07'. On SQLite
            // the same predicate matched only '7'. A SQL-side ownership check is therefore unsound
            // on the engine that matters and well behaved on the engine the suite runs -- the worst
            // combination available -- so the value is read out and parsed in PHP with the same
            // filter_var() applied to the input.
            'app/Http/Controllers/Concerns/ResolvesPendingPartialPayment.php' => 'tenant',
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
            // P1-SEC-04's ModuleAdministrativeRouteGuard. 'comparison', for the same reason as the sibling guards: its five raw reads
            // (campaigns, helpdesk_tickets, email_templates, user_email_templates, and the caller's own store for the Duo rule) fetch the
            // OWNER of the row the request NAMES - created_by / user_id - so that the caller's tenant (User::creatorId()) can be compared with
            // it and the request REFUSED. A store_id predicate would collapse "another tenant's row" into "no such row" and these module
            // tables (campaigns, helpdesk_tickets) have no store_id to put one on. The tenant compared against is never read from the request.
            'app/Http/Middleware/ModuleAdministrativeRouteGuard.php' => 'comparison',
            // P1-SEC-04's SIBLING GUARD, and the entry cdc213bd FORGOT -- which is why CI went red on this inventory and on the two
            // platform-wide counts in RawQueryTenancyInventoryTest. 'comparison', like the administrative guard, and the bucket's promise
            // ("every member refuses cross-tenant access") holds for it only because of P1-SEC-34: before that fix the two storefront
            // routes DERIVED a tenant without ever refusing on one, which is the exact drift that made 'derivation' a separate category.
            //
            // The file's six reads are not all one shape, and this inventory keys on the PATH, so they are named here instead:
            //   COMPARISON  `customers` (customerBelongsTo) -- fetches the customer's `store_id` with no predicate precisely so it can be
            //               compared with the caller's store and the request refused; the merchant-reply and thread-read/delete rules
            //               turn on it.
            //   TENANT      `customers` (sameShopperInStore, the P1-SEC-34 follow-up) -- carries its own `store_id` predicate: it resolves
            //               the SLUG STORE'S OWN row for the signed-in shopper, so a comment is always filed under a customer of the store
            //               being written, without refusing a shopper the storefront has already told is signed in there.
            //   TENANT      `products` (productOfStoreOrAbsent) -- carries its own `store_id` predicate; a named product must belong to
            //               the slug's store or the request is refused.
            //   DERIVATION  `stores` x3 -- the two {storeSlug} resolutions and the thread read's by-id lookup. These fetch the
            //               authoritative store and OVERWRITE whatever `store_id` the body sent; a store predicate is meaningless on the
            //               tenant REGISTRY, and the slug lookup IS the tenant resolution, so there is nothing to predicate on yet.
            'app/Http/Middleware/ModuleStorefrontInputGuard.php' => 'comparison',
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
            // P1-INV-19's TWO SITES. 'derivation', NOT 'comparison' -- and the first version of
            // this entry said 'comparison' while its own comment conceded the word did not fit.
            // An adversarial review was right to call that out: 'comparison' promises a reader
            // that the file REFUSES cross-tenant access, and this guard never refuses on tenant.
            // It fetches the authoritative store and OVERWRITES what the caller sent. Neither read
            // carries a store predicate, and neither should.
            //
            // `:118` reads `products` by id with NO store filter, and that is the POINT: bidding
            // across stores is the module's own design (place_bid.blade.php:314 renders
            // `store_id = $product->store_id`), so the guard wants the product whatever store owns
            // it. What it must not do is let the CALLER name the store, and it does not -- the
            // store is taken from the row and merged over the caller's value. A
            // `->where('store_id', getCurrentStore())` here would break the legitimate flow and
            // close nothing: the attacker can already see the product, since storefronts are
            // public.
            //
            // `:143` reads `max('bid_amount')` keyed on `product_id` alone, deliberately matching
            // the vendored display reads at AuctionProductController:1568 and :1635. The guard
            // enforces "higher than the current highest bid", and it has to ask the question the
            // PAGE asks or it would refuse a bid the page invited. `product_id` is globally unique
            // (`Product` carries no scope at all), so keying on it does identify one store's
            // product.
            //
            // Controls asserted at the bottom of this method: the derivation of `customer_id` from
            // the authenticated principal and of `store_id` from the product row.
            'app/Http/Middleware/AuctionBidIdentityGuard.php' => 'derivation',
            // P1-INV-21's price listener. 'derivation' -- it reads the authoritative
            // `lottery_settings.ticket_price` for the ticket's own `lottery_id` and OVERWRITES the
            // client-posted `amount` on the model, between the controller's assignments and the
            // INSERT.
            //
            // NO store_id PREDICATE, and adding one would be worse than useless. The lookup is by
            // PRIMARY KEY, which is already unique; the only `store_id` available to the listener
            // is the one on the model being written, which is the untrusted side. And re-deriving
            // `store_id` itself is a TAUTOLOGY -- `StoreLotteryPayment:595-597` selects the lottery
            // BY the store it then assigns at `:616` -- so a predicate here would be dressing a
            // check that cannot fail. The listener deliberately does not touch `store_id`, and
            // LotteryTicketPriceIntegrityTest pins that absence.
            'app/Listeners/EnforceLotteryTicketPrice.php' => 'derivation',
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
        $declaredHits = [];

        foreach ($sites as $site => $source) {
            $file = substr($site, 0, (int) strrpos($site, ':'));

            if (!array_key_exists($file, $classified)) {
                $unclassified[] = $site . '   ' . $source;
                continue;
            }

            // THE CATEGORY SET IS ASSERTED, not just switched on. A typo in a classification
            // string would otherwise land in the "not 'tenant', so skip" branch and exempt the
            // whole file silently -- which is the same shape as the manifest ratchet that was
            // reading a table it did not govern.
            $this->assertContains($classified[$file], self::CLASSIFICATIONS,
                "`{$file}` carries the classification `{$classified[$file]}`, which is not one of: "
                . implode(', ', self::CLASSIFICATIONS) . '. An unknown classification is treated '
                . 'as an exemption, so a typo would silently excuse every raw query in the file.');

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
                // A DECLARED store-less statement is not a missing predicate. See
                // STORE_LESS_BY_DESIGN: it is keyed by file AND by a fragment of the statement,
                // and every declaration is asserted to still match a real site below, so a
                // declaration cannot outlive the statement it was written for.
                if ($this->declaredStoreLess($file, $statement)) {
                    $declaredHits[] = $file . '::' . $this->declarationFor($file, $statement);
                    continue;
                }

                $missingPredicate[] = $site . '   ' . $source;
            }
        }

        // EVERY DECLARATION MUST STILL BE EARNED. A declaration whose statement has gained a
        // store_id predicate, been rewritten or been deleted is a stale exemption, and a stale
        // exemption in an inventory is exactly the hand-carved exception this class refuses to
        // carry. Asserted as a SET, in both directions.
        $declared = [];

        foreach (self::STORE_LESS_BY_DESIGN as $declaredFile => $fragments) {
            foreach (array_keys($fragments) as $fragment) {
                $declared[] = $declaredFile . '::' . $fragment;
            }
        }

        sort($declared);
        sort($declaredHits);

        $this->assertSame($declared, $declaredHits,
            "The declared store-less statements are not the ones this run found. A declaration "
            . "that matched nothing is stale -- the statement gained a predicate, was rewritten "
            . "or was deleted -- and must be removed from STORE_LESS_BY_DESIGN rather than left "
            . "exempting nothing.\n  declared: " . implode("\n  declared: ", $declared)
            . "\n  found:    " . implode("\n  found:    ", $declaredHits));

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
        // 19 -> 20 with P1-INV-21: EnforceLotteryTicketPrice's `lottery_settings` read. It is
        //       also the reason app/Listeners/ joined the glob above -- the site was outside this
        //       inventory until it did.
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
        // 17 -> 19 with P1-INV-19: the bid guard's product lookup and its current-maximum read.
        // Both are 'comparison' sites and both deliberately carry no store predicate -- see the
        // classification above, where the reason is that a store predicate would break the
        // module's own cross-store bidding and close nothing.
        // 20 -> 26: ModuleAdministrativeRouteGuard's five reads (P1-SEC-04, 'comparison' - see its classification) and OrderDocumentAccessGuard's
        // refund-id -> order-id read (P1-ORD-12, in a file already classified 'comparison').
        // 26 -> 31: ModuleStorefrontInputGuard's five reads (`customers`, `products`, `stores` x3), which cdc213bd added to the stack and
        // did not record here -- the omission that failed this assertion and the two totals in RawQueryTenancyInventoryTest.
        // 31 -> 32 with the P1-SEC-34 follow-up's sameShopperInStore() -- a sixth read in the same file, 'comparison' like the rest of it:
        // it resolves the slug store's OWN customer for the signed-in shopper so the write is always a customer of the store being written.
        // 32 -> 33 with OWNER DECISION D13 / P1-INV-12: BulkDeleteTenantGuard::categoriesStillHoldingProducts(), which counts the `products`
        // rows a bulk category delete would cascade away. It is the first statement in this inventory declared STORE-LESS BY DESIGN rather
        // than carrying a predicate -- see STORE_LESS_BY_DESIGN for why a predicate here would read zero and PERMIT the cascade, and for the
        // two-directional ratchet that stops that declaration outliving its statement.
        // 33 -> 34 with P1-ORD-02 criterion 2: the pending-balance read in
        // ResolvesPendingPartialPayment, classified 'tenant' above. The directory it lives in was
        // not in this file's own glob until this change -- the third time a path this inventory
        // claims to own had slipped out of it.
        $this->assertCount(34, $sites, "The owned-path raw query inventory changed:\n  "
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

        // THE FOURTH 'comparison' SITE'S CONTROLS -- P1-INV-19's bid guard. Its category rests on
        // the two DERIVATIONS, not on a predicate: the reads are unscoped on purpose, and what
        // makes that safe is that the caller cannot name either the customer or the store.
        $bid = (string) file_get_contents(
            base_path('app/Http/Middleware/AuctionBidIdentityGuard.php')
        );

        $this->assertStringContainsString("'customer_id' => \$customer->id,", $bid,
            'AuctionBidIdentityGuard no longer derives customer_id from the authenticated '
            . 'principal. Console/Auction.php:79 reads that column to decide who wins the auction '
            . 'and receives the order, so a caller-supplied value forges the winner.');

        $this->assertStringContainsString("'store_id' => \$productStore,", $bid,
            "AuctionBidIdentityGuard no longer derives store_id from the product's own row, so the "
            . 'bid lands in whichever store the caller names.');

        $this->assertStringContainsString('auth(\'customers\')->user()', $bid,
            'AuctionBidIdentityGuard no longer resolves the customer from the customers guard '
            . 'explicitly. `auth()` with no guard name reads the MUTABLE default guard.');

        // THE THREE CONTROLS THE FIRST VERSION OF THIS BLOCK DID NOT PIN. An adversarial review
        // noted that the file-part check, the bid floor and the product_id/bid_amount merges could
        // all be deleted with this test staying green -- and the file-part check was a LIVE hole at
        // the time, so that mattered.
        $this->assertStringContainsString('array_key_exists($key, $files)', $bid,
            'AuctionBidIdentityGuard no longer refuses a request whose file bag holds one of the '
            . 'derived keys. `all()` overlays allFiles() on top of the merge, and `hasFile()` is '
            . 'FALSE for an errored upload while its key still wins -- measured: an '
            . "UPLOAD_ERR_INI_SIZE part named store_id stored `''`.");

        $this->assertStringContainsString('$floor = $currentMax ?:', $bid,
            "AuctionBidIdentityGuard no longer falls back to the product's sale_price for the "
            . 'first bid. AuctionProductController:1571 uses that fallback for the figure it shows '
            . 'as the minimum, so without it a first bid of 0.01 is accepted on a product priced '
            . 'at 100 and Console/Auction.php awards the item at that price.');

        $this->assertStringContainsString("'product_id' => \$productId,", $bid,
            'AuctionBidIdentityGuard no longer merges the parsed product_id, so the handler writes '
            . 'the raw request value into an unsignedBigInteger column and can denote a different '
            . 'row than the guard validated.');

        $this->assertFileExists(base_path('tests/Feature/Security/AuctionBidForgeryTest.php'));

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
    /**
     * Raw statements in a `tenant`-classified file that deliberately carry NO `store_id`
     * predicate, each with the reason it must not have one.
     *
     * WHY THIS EXISTS AT ALL, given that this class elsewhere refuses hand-carved exceptions.
     * The rule above -- every raw query in a tenant file names `store_id` -- is right for a
     * statement that READS OR WRITES rows. It is wrong for one whose whole purpose is to ask a
     * question WIDER than the caller's tenant, and reclassifying the file to escape it would
     * exempt that file's real tenancy queries too. So the exemption is per STATEMENT, it carries
     * its reason, and it is ratcheted in both directions: the test above asserts the set of
     * declarations equals the set of store-less statements actually found, so a declaration
     * cannot outlive its statement and a new store-less statement cannot hide behind one.
     *
     * Keyed by a FRAGMENT of the statement rather than by line number, so an edit anywhere above
     * it cannot silently slide the declaration onto a different query.
     */
    private const STORE_LESS_BY_DESIGN = [
        'app/Http/Middleware/BulkDeleteTenantGuard.php' => [
            "whereIn('category_id', \$ids)" =>
                // OWNER DECISION D13 / P1-INV-12. `categoriesStillHoldingProducts()` counts the
                // `products` rows that the vendored BulkDelete controller's
                // `Category::whereIn('id', $ids)->delete()` would cascade away.
                //
                // IT MUST NOT CARRY A store_id PREDICATE, and that is the opposite of the usual
                // remedy. The FOREIGN KEY does not read `store_id`
                // (2023_12_26_092822_create_products_table.php:55 is ON DELETE CASCADE on
                // `category_id` alone), so a `products` row carrying another store's `store_id`
                // and this `category_id` is destroyed just the same. Narrowing this count to the
                // caller's store would make it read zero and wave that deletion through --
                // measured: mutant B of the task's removal check adds the predicate and fails
                // exactly the cross-store test.
                //
                // AND IT IS NOT AN EXPOSURE. It returns a COUNT, not rows, and the integer is
                // used only to REFUSE. The 409 body carries no ids (ids go to the log only), and
                // the tenant-side caller passes the ownership-narrowed `$owned` list, so no
                // merchant can learn anything about another store's catalogue through it. The
                // only principal whose raw input reaches this is the platform operator.
                'D13: counts the products a category delete would cascade; the FK ignores store_id, '
                . 'so narrowing it would read zero and permit the cascade. Returns a count, never rows.',
        ],
    ];

    /** Is this store-less statement one of the declared ones? */
    private function declaredStoreLess(string $file, string $statement): bool
    {
        return $this->declarationFor($file, $statement) !== null;
    }

    /** The declared fragment this statement matches, or null. */
    private function declarationFor(string $file, string $statement): ?string
    {
        foreach (self::STORE_LESS_BY_DESIGN[$file] ?? [] as $fragment => $reason) {
            if (str_contains($statement, $fragment)) {
                return $fragment;
            }
        }

        return null;
    }

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
