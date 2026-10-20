<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * R4-05, THE RAW-QUERY HALF -- the inventory the brief asks to be continued.
 *
 * NO ELOQUENT GLOBAL SCOPE CAN EVER REACH THESE SITES. `DB::table()` and friends build their own
 * query from nothing, so `App\Scopes\StoreScope` is not consulted -- proven by execution rather
 * than assumed in EnforcedModelIsolationTest::test_raw_db_table_queries_bypass_the_scope_entirely.
 * The only possible remedy at such a site is an explicit `store_id` predicate written there, and
 * the files those sites live in belong to other workstreams, so THE INVENTORY IS THE DELIVERABLE.
 *
 * ResidualTenancyExposureTest already inventories the raw sites in THIS workstream's own files,
 * at file:line precision. This suite covers the rest of the codebase -- app/ and packages/ -- at
 * PER-TABLE precision, which is the right granularity for a set this size: a file:line ratchet
 * over 136 sites in other agents' files would go red on every unrelated edit above them and
 * would be deleted within a phase.
 *
 * ============================================================================================
 *  WHAT IS MEASURED, AND THE TWO LIMITS OF THE MEASUREMENT, STATED UP FRONT.
 * ============================================================================================
 *
 * For each `DB::table('literal')` site, the whole STATEMENT is read -- the matched line plus
 * following lines up to the first `;`, capped at 25 lines -- and classified by whether the word
 * `store_id` appears anywhere in it. Comment and docblock lines are skipped so documentation
 * cannot satisfy its own ratchet.
 *
 * LIMIT 1. `store_id` appearing in the statement does NOT prove the predicate is CORRECT. It
 * proves only that the author was aware of the discriminator. A site that filters on a store id
 * taken from the request without an ownership check still has an IDOR, and this inventory counts
 * it as "aware". So the `no-store_id` column is a LOWER BOUND on the exposure, never an upper one.
 *
 * LIMIT 2. Only `DB::table('literal')` is attributable to a table. A site built from a variable
 * (`DB::table($table)`) cannot be, and those are counted in the overall raw-entry-point total
 * below but not in the per-table breakdown. The slug-uniqueness helper is exactly that shape and
 * is already inventoried at file:line in ResidualTenancyExposureTest.
 *
 * Neither limit is a reason not to measure. An exact, reproducible lower bound that moves when
 * the code moves is worth more than an estimate, and both limits are recorded so no reader can
 * mistake this for a clean bill of health.
 */
class RawQueryTenancyInventoryTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Every raw query ENTRY POINT in app/ and packages/, regardless of whether its table is a
     * literal. `DB::raw()` is excluded on purpose: it is a select EXPRESSION inside an Eloquent
     * builder, and the surrounding query still carries its scopes, so conflating the two would
     * overstate this inventory.
     */
    public function test_the_raw_entry_point_totals_are_the_measured_one_hundred_and_sixty_six(): void
    {
        $perDir = [];

        foreach (['app', 'packages'] as $dir) {
            $perDir[$dir] = count($this->rawEntryPoints($dir));
        }

        // 112, DOWN FROM 114, AND THE DELTA IS TWO DELETED SITES -- P01-SEC-14 WORK PACKAGE C.
        //
        // Both were in app/Http/Controllers/Api/ApiController.php::notify_user, and both are
        // GONE rather than rewritten:
        //
        //     $NotifyUser = DB::table('notify_users')
        //         ->where('user_id', $request->user_id)
        //         ->where('product_id', $request->product_id)
        //         ->where('store_id', $store->id)
        //         ->first();                                          // the `aware` one
        //     $NotifyUser = DB::table('notify_users')->insert($values);  // the unscoped one
        //
        // THIS IS A REDUCTION IN RAW EXPOSURE, NOT A RELAXED RATCHET. The handler stored a
        // caller-supplied `user_id` as the subscriber of a back-in-stock notification, and the
        // consumer of that row sends an FCM push to the matching `users` row -- so a shopper
        // could enlist an arbitrary platform user as the recipient. `notify_users.user_id`
        // carries a foreign key to `users`, so a storefront customer's own id is not storable
        // there at all: there is no correct value this handler could have written. It now fails
        // closed (store-scoped product lookup, log, refusal envelope) and the write block was
        // deleted outright rather than left after a `return`, because dead code reads as a live
        // fallback. The schema decision that would let the feature work is an owner item --
        // see S22 in docs/security/P01-SECURITY-WORK-QUEUE.md -- and when it is taken, the
        // replacement write belongs in this inventory again, with a store predicate.
        //
        // The refusal and the absence of the write are asserted by
        // tests/Feature/Security/ResidualSafetyCorrectionsTest.
        //
        // THE PRECEDING DELTA, PRESERVED VERBATIM: 114, UP FROM 113, AND THE DELTA IS ONE ADDED
        // SITE -- P01-SEC-2.
        //
        // app/Http/Middleware/OrderDocumentAccessGuard.php, in handle()
        //
        //     $row = DB::table($target['table'])
        //         ->where('id', $orderId)
        //         ->first(['store_id', 'customer_id']);
        //
        // IT IS DELIBERATELY UNSCOPED, AND THAT IS THE POINT RATHER THAN AN OVERSIGHT. The
        // statement's job is to FETCH the discriminator so the guard can compare it against the
        // caller's tenant and refuse; a `where('store_id', getCurrentStore())` predicate here
        // would turn "another merchant's order" into "no such order" and lose the ability to
        // distinguish a cross-tenant request from a malformed one at all. The tenant control is
        // the comparison in OrderDocumentAccessGuard::authorises(), two lines later, and it
        // fails closed when either side's store is empty.
        //
        // It is raw rather than Eloquent for the same reason the BulkDeleteTenantGuard sites
        // below are: one target table (`auction_product_orders`) belongs to a module class that
        // does not exist when that module is not loaded, and `Order` carries neither the
        // tenancy trait nor an entry in config/tenancy.php's scoped_models allowlist, so an
        // Eloquent read would add no predicate while implying one.
        //
        // The PER-TABLE breakdown below is unchanged by it, correctly: it is
        // `DB::table($target['table'])` with the table resolved at runtime, so LIMIT 2 in this
        // class's docblock applies -- counted in this total only. It is pinned at file:line,
        // with its classification and the comparison it depends on asserted, by
        // tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest, and exercised end to end
        // against the resolved route and middleware stack by
        // tests/Feature/Security/OrderDocumentAccessTest.
        //
        // THE PRECEDING DELTA, PRESERVED VERBATIM: 113, DOWN FROM 114, ONE DELETED SITE.
        //
        // Commit 1bd56358 ("retire the legacy Chatwoot integration") removed
        // app/Services/ChatwootOrderNotificationService.php, and with it the ONLY raw entry
        // point this branch changes:
        //
        //     $rawPhone = \DB::table('order_billing_details')
        //         ->where('order_id', $order->id)
        //         ->value('telephone');
        //
        // It filtered on order_id alone. `order_billing_details` carries no store_id column at
        // all, so nothing downstream could have scoped it either: it was a raw, unscoped read
        // of a customer's telephone number, which is exactly the shape this census exists to
        // count. Its removal is a reduction in exposure, and the surviving site on that table
        // (ReportController.php:2952) joins `orders` and filters orders.store_id against
        // getCurrentStore(), so the table's no-store_id count is now zero -- see the per-table
        // breakdown and the discriminated-and-unscoped list below, which move for this one
        // reason and no other.
        //
        // The three BulkDeleteTenantGuard sites that took this number from 111 to 114 are
        // untouched and the reasoning for them is preserved verbatim:
        //
        // THE EARLIER DELTA, ACCOUNTED FOR SITE BY SITE. All three are in
        // app/Http/Middleware/BulkDeleteTenantGuard.php (:431, :454, :469) and they are not new
        // exposure -- they ARE the tenant predicate for NEW-BULKDELETE-01. The guard rewrites
        // the bulk-delete `ids` list to the subset the caller's tenant provably owns, and it
        // has to read that back with a raw query precisely BECAUSE an Eloquent global scope
        // cannot be relied on here: 56 of the 61 models in BulkDeleteController's map are not
        // allowlisted, so the scope adds no predicate for them at all.
        //
        // The PER-TABLE breakdown below is unchanged by them, and that is correct rather than
        // an oversight: all three are `DB::table($table)` with the table resolved from the
        // request type at runtime, so they fall under LIMIT 2 in this class's docblock -- not
        // attributable to a table name, counted in this total only.
        //
        // Two of the three write the predicate COLUMN inline as 'store_id'; the third
        // (BulkDeleteTenantGuard.php:454) binds the column from
        // BulkDeleteTenantGuard::DERIVED_OWNERSHIP, whose only entry resolves user_coupons'
        // tenant through `coupons.store_id`. All three are pinned, per-site and with the
        // variable binding justified, by
        // tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest, and exercised end to end by
        // tests/Feature/Tenancy/BulkDeleteAuthorizationTest.
        // app 112 -> 113 with P1-INV-14: App\Http\Middleware\ModuleOrderMutationGuard's
        // `DB::table($target['table'])->where('id', $rowId)` read. It is deliberately WITHOUT a
        // store predicate -- the read exists to fetch `store_id` so the guard can compare it and
        // refuse -- and it is classified and its controls asserted in
        // tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest, which is where the 'comparison'
        // category lives.
        // app 113 -> 115 with P1-INV-18: the shared trait's SET predicate adds two
        // `DB::table($table)` reads -- both variable-table, so the per-table breakdown below is
        // correctly unchanged. See OwnedPathRawQueryInventoryTest for why it is two and not one.
        // app 115 -> 119 with P1-INV-20, FOUR sites, and unlike the two above most of them name
        // their table literally, so the per-table breakdown below DOES move:
        //   LotteryRefundOwnershipGuard's `$ownedByCaller` read   -> DB::table(self::TABLE), so
        //                                                           VARIABLE: not attributed
        //   LotteryRefundOwnershipGuard's slug lookup             -> stores  (18 -> 19)
        //   LotteryMailTemplatePrerequisite's existence READ      -> email_templates (NEW)
        //   LotteryMailTemplatePrerequisite's INSERT              -> email_templates
        // `email_templates` enters this inventory for the first time, with two sites.
        //
        // THE READ IS RAW ON PURPOSE and that is why it is here at all: App\Models\EmailTemplate
        // is Cachable, the Eloquent builder's pluck() caches even an EMPTY result, and
        // DB::table()->insert() flushes nothing -- so an Eloquent read behind that raw write served
        // the pre-write empty set and every draw after the first inserted three more rows,
        // measured at 3 -> 6 across two draws. One more visible, classified raw site is a better
        // trade than an invisible stale cache.
        // app 119 -> 121 with P1-INV-19: the bid guard's product lookup and its current-maximum
        // read. Both name their table literally, so the per-table breakdown below gains TWO rows.
        $this->assertSame(['app' => 121, 'packages' => 45], $perDir,
            'The raw query-builder entry-point count moved. Every one of these is invisible to '
            . 'every Eloquent global scope, so a NEW site needs an explicit `store_id` predicate '
            . 'written at the call site -- there is no other remedy. A REMOVED one probably means '
            . 'a raw query became Eloquent, which is an improvement worth recording here.');

        $this->assertSame(166, array_sum($perDir));
    }

    /**
     * THE EXPOSURE, PER TABLE. One row per table reached by a literal `DB::table('x')`, with the
     * number of statements that never mention `store_id`.
     *
     * This is the ratchet that matters: if a table's `no-store_id` count GOES UP, a raw
     * cross-tenant query was just added somewhere no scope can see it.
     */
    public function test_the_per_table_raw_query_exposure_is_exactly_this(): void
    {
        $actual = [];

        foreach ($this->literalTableSites() as $table => $info) {
            $actual[$table] = [count($info['all']), count($info['unscoped'])];
        }

        $this->assertSame([
            // table                         [ total sites, sites with no `store_id` anywhere ]
            // NEW WITH P1-INV-19, and unscoped BY DESIGN. AuctionBidIdentityGuard:143 reads
            // `max('bid_amount')` keyed on `product_id` alone, deliberately matching the vendored
            // display reads at AuctionProductController:1568 and :1635 -- the guard enforces
            // "higher than the current highest bid" and must ask the question the PAGE asks, or it
            // would refuse a bid the page invited. `product_id` is globally unique, since `Product`
            // carries no scope at all, so keying on it does identify one store's product.
            'auction_product_bids' => [1, 1],
            'coupon_requests' => [3, 3],
            'coupons' => [2, 0],
            'currency' => [3, 3],
            'customers' => [5, 3],
            // NEW WITH P1-INV-20, and the only WRITE in this population.
            // LotteryMailTemplatePrerequisite inserts the authenticated merchant's own
            // `Lottery Win` / `Lottery Fail` / `Lottery Remainder` rows before a draw, because
            // LotteryResultController:157 dereferences a null template and 500s AFTER the draw has
            // written its order and lottery_customers rows -- measured on an otherwise valid
            // own-store draw.
            //
            // IT COUNTS AS UNSCOPED AND THAT IS CORRECT, NOT AN EXCEPTION BEING CARVED OUT:
            // `email_templates` HAS NO `store_id` COLUMN. It is keyed on `created_by`, and the
            // insert sets that from `auth()->user()->id`, never from request input -- so the row
            // cannot land in another tenant even though no `store_id` predicate is possible here.
            // This inventory measures `store_id` predicates, so a table without the column reads
            // as unscoped by construction; the real control is asserted per-site in
            // tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest, where the file is classified
            // 'platform' and the owner-id derivation is pinned.
            'email_templates' => [2, 2],
            'migrations' => [1, 1],
            // `notify_users` USED TO BE LISTED HERE AT [2, 1] AND HAS LEFT THIS POPULATION
            // ALTOGETHER: it now has no literal raw statement at all. P01-SEC-14 Work Package C
            // deleted both -- the aware read and the unscoped insert -- when
            // Api/ApiController.php::notify_user was made to fail closed. See the site-by-site
            // note in the entry-point test above for why there was no correct value that insert
            // could have written. If a store-scoped replacement write lands after the S22 schema
            // decision, this row comes back and the ratchet will say so.
            // [1, 0], DOWN FROM [2, 1]. Commit 1bd56358 deleted
            // ChatwootOrderNotificationService, whose `DB::table('order_billing_details')
            // ->where('order_id', ...)->value('telephone')` was the single unscoped statement on
            // this table. The one that remains (ReportController.php:2952) joins `orders` and
            // filters orders.store_id against getCurrentStore(), so the unscoped count is now
            // zero. The ratchet this assertion guards is a count going UP; this one went down.
            'order_billing_details' => [1, 0],
            'owner_menu_items' => [1, 1],
            'owner_menu_settings' => [1, 1],
            'owner_menus' => [1, 1],
            'personal_access_tokens' => [2, 2],
            // NEW WITH P1-INV-19, and the [1, 0] is a HEURISTIC ARTIFACT worth naming rather than
            // reading as reassurance. AuctionBidIdentityGuard:118 is
            // `DB::table('products')->where('id', $productId)->first(['id', 'store_id'])` -- it
            // carries NO store_id PREDICATE, and must not: bidding across stores is the module's
            // own design. It counts as "scoped" only because this inventory looks for the string
            // `store_id` in the statement, and the statement SELECTS that column in order to
            // derive the bid's store from the row instead of from the caller. The real control is
            // asserted per-site in OwnedPathRawQueryInventoryTest, where the file is classified
            // 'comparison'.
            'products' => [1, 0],
            'settings' => [15, 9],
            'shetabit_visits' => [16, 0],
            'side_menus_option' => [28, 0],
            'store_devices' => [2, 1],
            'store_order_financials' => [7, 3],
            // 18 -> 19 AND 17 -> 18 with P1-INV-20: LotteryRefundOwnershipGuard's slug lookup,
            // `DB::table('stores')->where('slug', $slug)->value('id')`.
            //
            // IT HAS NO `store_id` PREDICATE AND MUST NOT HAVE ONE: `stores` IS the tenant table.
            // The slug is CALLER-SUPPLIED and this read only NAMES a store; the authorisation is
            // the comparison of the resolved id against the signed-in customer's own `store_id`
            // four lines later, which is what makes the slug not a tenant check. That comparison
            // is pinned in OwnedPathRawQueryInventoryTest, where the file is classified
            // 'comparison'.
            //
            // Note the shape of this row generally: 18 of 19 `stores` sites are 'unscoped' for the
            // same structural reason, which is why a rising count on THIS table is not by itself a
            // finding -- the per-site classification is.
            'stores' => [19, 18],
            'theme_customizes' => [2, 0],
            'users' => [5, 5],
            'zid_events' => [2, 2],
            'zid_orders' => [11, 11],
            'zid_stores' => [7, 2],
        ], $actual, 'The per-table raw-query inventory changed. If a `no-store_id` count went UP, '
            . 'a raw cross-tenant query was added where no global scope can reach it.');

        // 133, DOWN FROM 135, AND 65, DOWN FROM 66 -- the two notify_user deletions above,
        // flowing through both totals. Both were attributable to a literal table name
        // (notify_users) so both come off the first sum; only one of them lacked a store_id
        // predicate, so the second sum moves by one.
        //
        // AND THE MESSAGE BELOW IS CORRECTED WHILE IT IS BEING TOUCHED, which is a separate
        // thing from this delta and is called out so the two are not confused. It read '135
        // literal-table sites of the 155 entry points; the other 20 build their table name from
        // a variable'. 155 and 20 were already stale before P01-SEC-14: the four variable-table
        // sites added since (BulkDeleteTenantGuard.php:431, :454, :469 and
        // OrderDocumentAccessGuard.php:319) are all `DB::table($variable)`, so they raised the
        // unattributable count 20 -> 24 and the entry-point total with it, and the prose was
        // never flowed through. Re-measured rather than arithmetic: 160 entry-point lines, 133
        // literal-table lines, 27 entry-point lines that are not a literal `DB::table('x')`.
        //
        // 24 -> 25 with P1-INV-14: ModuleOrderMutationGuard.php's read is
        // `DB::table($target['table'])`, the table resolved per uri from the guard's TARGETS map,
        // so it is a fifth variable-table site and the PER-TABLE breakdown below is correctly
        // unchanged by it -- LIMIT 2 in this class's docblock. Its own controls are asserted in
        // tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest.
        //
        // The 27 are DashboardController.php:3302, :3374, :3468, :3564, :3665,
        // StoreFinanceController.php:302, BulkDeleteTenantGuard.php:431, :454, :469,
        // OrderDocumentAccessGuard.php:319, ComparesRowOwnership.php x3 (readRowStore plus the
        // two limbs of the set predicate), Customer.php:149,
        // Product.php:97,
        // ProductAttribute.php:48, Tag.php:47, User.php:160, Utility.php:3171, :3173, :3196,
        // :3198, :3212, :3214, OrderFinancialService.php:289, :402 and
        // packages/workdo/Marketing/src/app/Models/Storefield.php:36.
        // 133 -> 136 and 65 -> 68 with P1-INV-20: the `stores` slug lookup and the TWO
        // `email_templates` sites, all naming their table literally. The guard's fourth new site,
        // `$ownedByCaller`, uses `DB::table(self::TABLE)` and so falls into the variable-table
        // population -- 27 -> 28 -- which is why these counts move by three and the entry-point
        // total above moves by four.
        // 136 -> 138 and 68 -> 69 with P1-INV-19: both new sites name their table literally, and
        // only one of the two counts as unscoped -- see the `products` note above for why that
        // asymmetry is an artifact of the heuristic and not a difference in the code.
        $this->assertSame(138, array_sum(array_map(fn ($r) => $r[0], $actual)),
            '138 literal-table sites of the 166 entry points; the other 28 build their table name '
            . 'from a variable and so cannot be attributed (see LIMIT 2 in this class docblock).');
        $this->assertSame(69, array_sum(array_map(fn ($r) => $r[1], $actual)));
    }

    /**
     * WHICH OF THOSE TABLES REALLY ARE TENANT-DISCRIMINATED, read from the LIVE MIGRATED SCHEMA
     * rather than from a name or a model's $fillable.
     *
     * This is what separates a finding from a number. `users` has FIVE statements with no
     * `store_id` and that is correct -- `users` has no such column; membership is
     * `users.current_store` plus `users.created_by`. `migrations` and `personal_access_tokens`
     * are platform tables. Counting those as exposure would have inflated the finding by eight
     * sites, which is the kind of error this project has already made in both directions.
     */
    public function test_only_these_tables_are_both_tenant_discriminated_and_rawly_unscoped(): void
    {
        $discriminated = [];
        $notDiscriminated = [];
        $noSuchTable = [];

        foreach ($this->literalTableSites() as $table => $info) {
            if ($info['unscoped'] === []) {
                continue;
            }

            if (! Schema::hasTable($table)) {
                // ABSENT IS NOT THE SAME AS "no store_id", and must not be reported as either
                // safe or exposed. These are the undeclared-schema families: GATE F's six money
                // tables and the Zid tables.
                //
                // The LandingPage module's owner_menu* tables were in this set until
                // NEW-ADDON-SCHEMA-02 was fixed in app/Providers/PackageServiceProvider.php.
                // They are now declared and classified below, which is exactly the transition
                // this branch's assertion message asked a reader to make.
                $noSuchTable[] = $table;

                continue;
            }

            if (Schema::hasColumn($table, 'store_id')) {
                $discriminated[$table] = count($info['unscoped']);
            } else {
                $notDiscriminated[] = $table;
            }
        }

        ksort($discriminated);
        sort($notDiscriminated);
        sort($noSuchTable);

        $this->assertSame([
            // TENANT-DISCRIMINATED TABLES REACHED BY RAW SQL WITH NO store_id IN THE STATEMENT,
            // by table and statement count. Alphabetical, because the list is ksort()ed.
            //
            // READ THE PER-TABLE NOTES BEFORE TREATING ANY OF THESE AS A LIVE EXPOSURE. One of
            // the three is not, and the exact sites are named so nobody has to re-derive it.
            // THERE WERE FOUR ENTRIES AND TWO SUCH CAVEATS until P01-SEC-14 Work Package C; the
            // fourth, `notify_users`, has left the list and its note is kept below rather than
            // deleted, because the reason it was a false positive is the same reason the handler
            // that produced it could not be made correct in place:
            //
            // `customers` -- NOT a tenancy finding, and this note is a CORRECTION of what was
            // first written here. The first version of this comment called it "the one that
            // matters most" on the reasoning that Customer is now an allowlisted Eloquent model
            // whose raw reads the scope cannot touch. The reasoning is sound; the attribution
            // was not. Measured, all three statements are in ONE one-off backfill migration:
            //     packages/workdo/ProductAffiliate/src/database/migrations/
            //         2024_09_18_033433_add_affiliate_code_to_customers_table.php:24, :29, :30
            // which iterates every customer on the platform to mint affiliate codes. A
            // data-backfill migration is SUPPOSED to be cross-tenant, so there is nothing to fix.
            // And the two raw `customers` reads that ARE live --
            // CustomerLoginController.php:68 and :686, the storefront login and registration
            // validators -- both carry `->where('store_id', $storeId)` and are correctly counted
            // as aware, not exposed.
            'customers' => 3,
            // `notify_users` => 1 WAS HERE AND IS NOT ANY MORE, and it is not reclassified: it
            // has left the population this list describes, because P01-SEC-14 Work Package C
            // deleted both of its raw statements outright. The note is preserved because it was
            // the right reading of the wrong question.
            //
            // WHAT IT SAID, AND WHY IT WAS CORRECT AS A TENANCY READING. `notify_users` IS an
            // allowlisted Eloquent model (NotifyUser), so the same caveat applied to it as to
            // `customers`: the scope governs its Eloquent reads and cannot touch a raw
            // statement. The one statement counted was the INSERT at
            // Api/ApiController.php:6524, `DB::table('notify_users')->insert($values)`.
            // `$values['store_id']` was assigned six lines earlier at :6518, outside the
            // statement this inventory reads, so the write really did carry the discriminator --
            // which is why LIMIT 1 in this class docblock cuts both ways and is worth reading
            // before acting on any number here. The paired READ at :6522 did filter on
            // `store_id` and was correctly counted as aware. It was kept in the list rather
            // than special-cased, because an inventory with hand-carved exceptions stops being
            // reproducible.
            //
            // WHAT THE TENANCY READING COULD NOT SEE. The discriminator was present and the
            // SUBSCRIBER was not: `$values['user_id']` came from `$request->user_id`, so a
            // storefront shopper chose whose back-in-stock push to enlist, and
            // `notify_users.user_id` is a foreign key to `users`, which makes a customer's own
            // id unstorable there. A store predicate on that insert would have been a correct
            // predicate on an incorrect row. The handler now refuses; the feature waits on the
            // S22 schema decision in docs/security/P01-SECURITY-WORK-QUEUE.md. A per-table
            // count can only ever be a lower bound on the exposure -- this is what that limit
            // looks like in practice.
            // `settings` IS THE REAL ONE, and it is the widest surface in the codebase: read on
            // every page render through getAdminAllSetting() (app/Helper/helper.php:350-382).
            // It is also why Setting is NOT allowlisted. The nine sites:
            //     app/Models/Utility.php:830                      (Utility::cookies(), filtered
            //                                                      on created_by, not store_id --
            //                                                      the cookie-consent bleed
            //                                                      already detailed in
            //                                                      ResidualTenancyExposureTest)
            //     app/Providers/StoreRedirectServiceProvider.php:30, :31
            //     app/Providers/ViewServiceProvider.php:89, :90   (subdomain/domain -> store
            //                                                      resolution; keyed on the host,
            //                                                      so arguably correct)
            //     app/Http/Controllers/AITemplateController.php:52
            //     packages/workdo/Donation/.../DonationController.php:187
            //     packages/workdo/PartialPayments/.../PartialPaymentsController.php:459, :470
            'settings' => 9,
            // `store_devices` -- app/Services/StoreNotificationService.php:195. Push-notification
            // device tokens, read with no store predicate. One statement, genuinely unscoped.
            'store_devices' => 1,
        ], $discriminated, 'The set of tenant-discriminated tables reached by raw SQL without a '
            . '`store_id` predicate changed. Each statement here is an unconstrained cross-tenant '
            . 'query that NO global scope can reach; the remedy is an explicit predicate at the '
            . 'call site, in files this workstream does not own.');

        $this->assertSame([
            // MEASURED, not assumed from the name. `currency` has a migration in
            // database/migrations and does not declare `store_id`, so its unscoped statements
            // are not tenancy findings -- exactly the distinction that stops this inventory
            // inflating the exposure. `currency` is a platform rate table.
            //
            // `order_billing_details` USED TO BE LISTED HERE and is not any more. It is not
            // reclassified: it has simply left the population this list describes, because it
            // no longer has ANY unscoped raw statement. Its only one was deleted with
            // ChatwootOrderNotificationService in 1bd56358 (see the per-table breakdown above).
            // It still has no `store_id` column, so if a new unscoped statement on it ever
            // appears it belongs back here -- and the per-table ratchet will say so first.
            'currency',
            // NEW WITH P1-INV-20, AND NOT A TENANCY FINDING -- measured, not inferred from the
            // name. `email_templates` genuinely has no `store_id` column: it discriminates on
            // `created_by`, which is the OWNER, the same shape as the `owner_menu*` tables below.
            // So no global scope was ever expected to reach it and nothing about its being
            // unscoped here is a cross-tenant read surface.
            //
            // Its one unscoped statement is LotteryMailTemplatePrerequisite's insert, which sets
            // `created_by` from `auth()->user()->id` -- never from request input -- so the row
            // lands under the authenticated merchant and nowhere else. That derivation is the real
            // control and it is pinned per-site in
            // tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest.
            //
            // WORTH RECORDING AGAINST THIS ENTRY: the module's own read of this table IS a
            // cross-tenant one. LotteryPrizesController::sendOwnerEmailTemplate:715 is
            // `EmailTemplate::where('name', 'LIKE', $emailTemplate)->first()` with no
            // `created_by`, so on a populated install the mail body and the store slug inside it
            // can come from another tenant's template. It is Eloquent, so it is not in THIS
            // inventory's population at all -- and it has no host-side seam (a static call from
            // inside vendored controllers; the only lever, a global scope on the shared
            // App\Models\EmailTemplate, would reach every module's template reads and the
            // operator's own management screens). Recorded as an upstream item on #67 rather than
            // left for the next reader to rediscover from this row.
            'email_templates',         // owner-discriminated, like owner_menus below
            'migrations',              // platform
            // THE LANDINGPAGE owner_menu* TABLES, MOVED HERE FROM THE UNDECLARED-SCHEMA LIST
            // BELOW because they now EXIST. They were absent only because of
            // NEW-ADDON-SCHEMA-02, and app/Providers/PackageServiceProvider.php now registers
            // every addon's on-disk migrations from the loader, so the six stranded migrations
            // run and these three tables are declared. This list is where the previous
            // assertion message said they must land, and the classification was re-read rather
            // than assumed:
            //
            // NOT A TENANCY FINDING, and measured rather than inferred from the names. These
            // are the PLATFORM OWNER's landing-page navigation, not store data. `owner_menus`
            // and `owner_menu_settings` discriminate on `created_by`; `owner_menu_items` hangs
            // off `owner_menus` by a `menu_id` foreign key and carries no tenant column by
            // design. None declares `store_id`, so no global scope was ever expected to reach
            // them.
            //
            // Every unscoped raw statement on all three is an INSERT in one seeder --
            // packages/workdo/LandingPage/src/database/seeders/OwnerMenusTableSeeder.php:35,
            // :92 and :115 -- and a seeder is supposed to be cross-tenant, the same reasoning
            // this file already applies to the ProductAffiliate backfill on `customers`. The
            // one LIVE read is Eloquent and owner-pinned:
            // resources/views/layouts/guest.blade.php:96 does
            // `OwnerMenuSetting::where('created_by', 1)->first()` for the platform's own guest
            // layout, of which there is one, not one per store.
            //
            // So making these tables exist did not make a cross-tenant read surface visible;
            // it removed six stranded migrations and an HTTP 500 on /login. If a store-scoped
            // raw statement on any of them ever appears, the per-table ratchet above sees it
            // first.
            'owner_menu_items',
            'owner_menu_settings',
            'owner_menus',
            'personal_access_tokens',  // platform; tokens belong to a user, not a store
            'stores',                  // the tenant REGISTRY itself -- ownership, not scoping
            'users',                   // no store_id column at all; see the DO-NOT-SCOPE list
        ], $notDiscriminated, 'A table moved between "has store_id" and "does not". That changes '
            . 'what the 65 unscoped statements mean, so re-read the classification above.');

        $this->assertSame([
            // `coupon_requests` is declared only by a PACKAGE module
            // (packages/workdo/PDFVouchers/src/database/migrations/..._create_coupon_requests_table.php),
            // and PackageServiceProvider loads a module's migrations only when it is listed in
            // `add_on_managers`, which is empty in a fresh database (NEW-ADDON-SCHEMA-03). So the
            // table is absent HERE while existing in production -- its three unscoped statements
            // are unclassified rather than cleared.
            // NEW WITH P1-INV-19. This table DOES carry a `store_id` column
            // (2024_09_18_070113_create_auction_product_bids_table.php:20, nullable + indexed) and
            // the one raw statement on it has no `store_id` predicate -- so it belongs in this
            // population, and listing it here is the accurate answer rather than an exemption.
            //
            // IT IS NOT A TENANCY FINDING, and the reason is the module's design rather than a
            // judgement call. AuctionBidIdentityGuard:143 reads `max('bid_amount')` keyed on
            // `product_id` to enforce "higher than the current highest bid", and it must ask the
            // same question the page asks (AuctionProductController:1568, :1635 use the identical
            // predicate) or it would refuse a bid the page invited. `product_id` is globally unique
            // because `Product` carries no effective scope at all, so the key does identify one
            // store's product; adding `store_id` would narrow the maximum to one store's rows while
            // the page still showed the global one, and the guard and the page would disagree.
            //
            // What makes the WRITE safe is not a predicate on this read: it is that the bid's
            // `store_id` and `customer_id` are derived from the product row and the authenticated
            // principal, pinned in OwnedPathRawQueryInventoryTest.
            'auction_product_bids',
            'coupon_requests',
            // `owner_menu_items`, `owner_menu_settings` and `owner_menus` USED TO BE LISTED
            // HERE. They are not absent any more: PackageServiceProvider registers each addon's
            // real on-disk migration directory, which fixes NEW-ADDON-SCHEMA-02, so the six
            // LandingPage migrations run and these three tables are declared. They are
            // reclassified, not dropped -- see the owner_menu* note in the list above for the
            // re-read classification and why it is not a tenancy finding.
            'store_order_financials',  // GATE F
            'zid_events',
            'zid_orders',
            'zid_stores',
        ], $noSuchTable, 'A table appeared or disappeared from the undeclared-schema set. If one '
            . 'now EXISTS, re-run this suite: its unscoped statements must move into one of the '
            . 'two classified lists above and may be a newly visible tenancy finding.');
    }

    // -------------------------------------------------------------------------------------
    //  Measurement
    // -------------------------------------------------------------------------------------

    private const RAW_ENTRY_POINT_PATTERN = '/\\\\?DB::(table|select|insert|update|delete|statement|unprepared)\s*\(/';

    private const LITERAL_TABLE_PATTERN = '/\\\\?DB::table\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/';

    /** @return array<int, string> */
    private function rawEntryPoints(string $dir): array
    {
        $sites = [];

        foreach ($this->phpFiles($dir) as $file) {
            foreach ($this->codeLines($file) as $number => $line) {
                if (preg_match(self::RAW_ENTRY_POINT_PATTERN, $line)) {
                    $sites[] = $file . ':' . $number;
                }
            }
        }

        return $sites;
    }

    /**
     * @return array<string, array{all: array<int, string>, unscoped: array<int, string>}>
     */
    private function literalTableSites(): array
    {
        $byTable = [];

        foreach (['app', 'packages'] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                $lines = file(base_path($file)) ?: [];

                foreach ($this->codeLines($file) as $number => $line) {
                    if (! preg_match(self::LITERAL_TABLE_PATTERN, $line, $match)) {
                        continue;
                    }

                    $table = $match[1];
                    $site = $file . ':' . $number;

                    $byTable[$table]['all'][] = $site;

                    if (! str_contains($this->statementAt($lines, $number - 1), 'store_id')) {
                        $byTable[$table]['unscoped'][] = $site;
                    }
                }
            }
        }

        foreach ($byTable as $table => $info) {
            $byTable[$table]['unscoped'] = $info['unscoped'] ?? [];
        }

        ksort($byTable);

        return $byTable;
    }

    /**
     * The whole statement starting at $index: that line plus following lines up to the first `;`.
     * Capped at 25 lines so a file without a semicolon cannot read to the end of itself.
     *
     * @param  array<int, string>  $lines
     */
    private function statementAt(array $lines, int $index): string
    {
        $statement = $lines[$index] ?? '';

        for ($i = $index + 1; $i < min($index + 25, count($lines)); $i++) {
            if (str_contains($statement, ';')) {
                break;
            }

            $statement .= $lines[$i];
        }

        return $statement;
    }

    /**
     * Executable lines of a file, keyed by 1-based line number. Comment and docblock lines are
     * skipped so that a docblock mentioning `DB::table()` -- including this very class's -- is
     * documentation rather than a counted query.
     *
     * @return array<int, string>
     */
    private function codeLines(string $relative): array
    {
        $lines = file(base_path($relative)) ?: [];
        $code = [];

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $code[$index + 1] = $line;
        }

        return $code;
    }

    /** @return array<int, string> relative paths */
    private function phpFiles(string $dir): array
    {
        $base = base_path($dir);

        if (! is_dir($base)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            }
        }

        sort($files);

        return $files;
    }
}
