<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F234 -- A COUPON USE IS BURNED BY A REQUEST THAT CANNOT SUCCEED.
 *
 * THE DEFECT. `CoingateController::coingatePaymentPrepare()` persists a `PlanUserCoupon` row
 * (`:150`) and may deactivate the coupon outright (`:155`) FIFTY LINES before it reads
 * `$admin_payment_setting['coingate_mode']` (`:200`) -- a bare array subscript on the result of
 * `getSuperAdminAllSetting()`, which plucks only the setting rows that EXIST
 * (`app/Helper/helper.php:662`) and supplies no defaults. On an installation that never saved
 * its CoinGate settings that read raises `Undefined array key "coingate_mode"`, which the
 * method's own `catch (\Exception $e)` (`:228`) turns into `redirect()->back()->with('error')`.
 * So the request cannot possibly produce a payment or a PlanOrder -- and the coupon use is
 * already in the database.
 *
 * WHY IT IS WORSE THAN ONE WASTED USE. `PlanCoupon::used_coupon()`
 * (`app/Models/PlanCoupon.php:21-24`) counts EVERY user's rows, so the limit is platform-wide.
 * One merchant retrying an unconfigured gateway drives `used_coupon()` up to `limit` and
 * `:155` then sets `is_active = 0` -- exhausting the coupon FOR EVERYBODY. There is no
 * `DB::transaction()` anywhere in the method, so nothing rolls the row back.
 *
 * THIS NEEDS NO NEW OWNER DECISION. It is a direct violation of a principle the repository
 * already settled: D-28b / F196 OPTION A -- "the refusal must precede persistence", with
 * Option B (persist then paper over it) explicitly REJECTED by the owner.
 *
 * -------------------------------------------------------------------------------------------
 * WHY THESE PARTICULAR ASSERTIONS, AND NOT THE OBVIOUS ONES
 * -------------------------------------------------------------------------------------------
 *
 * THE BUG LIVES UNDERNEATH A PASSING TEST, which is the whole reason it survived.
 * `PlanPaymentFailClosedTest::test_the_coingate_initiator_sends_a_posted_zero_price_down_the_gateway_path()`
 * already drives this exact route down this exact failing branch -- its docblock even names the
 * `Undefined array key "coingate_mode"` death -- but it posts no coupon and asserts only
 * `DB::table('plan_orders')->count()`. Nothing anywhere in the suite read `plan_user_coupons`
 * or `plan_coupons.is_active`. The assertion was satisfied by something ADJACENT to what
 * mattered, which is this codebase's most productive false-pass mode.
 *
 * THE ROWS ARE READ WITH `DB::table()`, NOT ELOQUENT, DELIBERATELY. Per the project CLAUDE.md,
 * 79 of the 95 models are `Cachable`, and under `CACHE_STORE=array` a stale read lives for the
 * rest of the test. An "it was not written" assertion made through Eloquent can pass whether or
 * not the fix works. The raw table is the only witness that cannot be cached.
 *
 * THERE IS A POSITIVE CONTROL, because a refusal test alone is satisfied by a handler that
 * refuses EVERYTHING -- including the legitimate purchase. `test_a_configured_install_still_records_the_coupon_use()`
 * seeds the CoinGate rows and asserts the use IS recorded, so breaking the happy path fails the
 * suite rather than passing it.
 *
 * THE OUTCOME IS READ FROM THE DATABASE AND THE FLASH, NEVER FROM THE HTTP STATUS of the
 * gateway call. No payment provider is reachable from this environment; asserting on the
 * redirect would be asserting on the network, not on the fix.
 *
 * WHAT IS NOT CLAIMED. Nothing here proves money moved, and nothing here enables CoinGate --
 * `PLAN_ACTIVATION_COINGATE` stays false and `PlanPaymentPostureTest` asserts that separately.
 */
class PlanCouponRefusalPersistsNothingTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    /**
     * The CoinGate settings rows whose ABSENCE makes the request unsatisfiable.
     *
     * `:200` and `:202` are the only two bare-subscript reads of these keys in the repository;
     * every other reader goes through `Utility::GetValueByName()`, which yields null for a
     * missing row instead of raising.
     */
    private const COINGATE_SETTINGS = [
        'coingate_mode'       => 'sandbox',
        'coingate_auth_token' => 'test-token-not-a-real-credential',
    ];

    /**
     * THE REFUSAL NAMES NOTHING INTERNAL — and this test replaced an obsolete premise.
     *
     * What used to stand here asserted that an unconfigured install reaches
     * `CoingateController:144` and raises `Undefined array key "coingate_mode"`. That was the
     * right premise for the UNFIXED code — it proved the negative cases below were measuring the
     * gateway branch rather than some unrelated early refusal. **The fix deliberately makes it
     * false**: the request is now refused above the `try` and never reaches `:200`, so the premise
     * had to go rather than be propped up.
     *
     * The negative cases are still protected, by `test_a_configured_install_still_records_the_coupon_use`:
     * if the coupon lookup or the fixture were broken, "no usage persisted" would be true
     * everywhere and that positive control would fail.
     *
     * So this slot now asserts something the fix is actually responsible for: the refusal must not
     * leak which setting is missing. `assertProviderCredentialsPresent()` logs the missing key
     * NAMES for an operator and tells the merchant only that the method is unavailable — the same
     * division `assertPlanActivationEnabled()` already makes.
     */
    public function test_the_credential_refusal_discloses_no_setting_name(): void
    {
        $this->seedSuperAdminCurrency();

        [$merchant] = $this->makeTenant();
        $plan       = $this->makePlan('499');

        $response = $this->actingAs($merchant)->post(route('coingate.prepare.plan'), [
            'plan_id'     => Crypt::encrypt($plan->id),
            'total_price' => 499,
        ]);

        $body = $response->getContent() . ' ' . (string) session('error');

        foreach (['coingate_mode', 'coingate_auth_token', 'getSuperAdminAllSetting'] as $internal) {
            $this->assertStringNotContainsString(
                $internal,
                $body,
                'the refusal names the internal setting `' . $internal . '` to the merchant. The '
                . 'key names belong in the operator log, not in a response.'
            );
        }
    }

    /**
     * F240 — FIXED, AND THIS IS NOW THE REGRESSION GUARD FOR IT.
     *
     * `planPayWithPaytm`'s free-plan branch used to write the `PlanUserCoupon` row and deactivate
     * the coupon, and only then run `$authuser->plan = $plan->id; $authuser->save();` --
     * **a write to a column that does not exist**. The real column is `users.plan_id`, which
     * `assignPlan()` sets on the next line. So the save raised, the enclosing `try` swallowed it,
     * and the committed coupon use was never undone: a 100%-off purchase burned the coupon,
     * activated nothing, and on a limited coupon took a use from every merchant on the platform,
     * because `PlanCoupon::used_coupon()` counts across users.
     *
     * Both halves are now asserted, and the first is what makes the second meaningful:
     *
     *   1. the dead write is gone. Nothing is lost by removing it -- `users.plan` never held
     *      anything -- and the column's continued absence is asserted so that a future reader who
     *      re-adds `$authuser->plan` is told why it was removed.
     *   2. the coupon consumption now comes AFTER `assignPlan()`, inside its success branch. That
     *      is F196 Option A / D-28b: the refusal must precede the persistence.
     *
     * ASKED OF THE SOURCE, because the branch cannot be driven from this suite at all: its coupon
     * lookup is `whereRaw('BINARY `code` = ?')`, a syntax error on SQLite, so a probe through the
     * real route redirects 302 having written nothing -- measured, and the reason
     * `verify-plan-coupon-refusal-ordering-on-mysql.php` exists. Comments are stripped first,
     * because this method's own docblocks now quote both the removed write and the ordering in
     * order to explain them.
     */
    public function test_f240_the_free_plan_coupon_is_consumed_only_after_the_plan_activates(): void
    {
        $this->assertFalse(
            Schema::hasColumn('users', 'plan'),
            'A `users.plan` column now exists. The write that F240 removed was dead BECAUSE it did '
            . 'not -- re-read the finding before restoring anything that writes it.'
        );
        $this->assertTrue(Schema::hasColumn('users', 'plan_id'), 'users.plan_id is the real column');

        $source = file_get_contents(base_path('app/Http/Controllers/PaytmPaymentController.php'));
        $start = strpos($source, 'public function planPayWithPaytm(');
        $this->assertNotFalse($start, 'the initiator has been renamed; re-read F240');
        $method = substr($source, $start);
        $next = strpos($method, 'public function ', 10);
        $method = $next === false ? $method : substr($method, 0, $next);

        $code = implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all('<?php ' . $method)
        ));

        $this->assertStringNotContainsString(
            '$authuser->plan =',
            $code,
            'the write to the non-existent `users.plan` column is back. It raises, the enclosing '
            . 'try swallows it, and anything persisted before it is never undone.'
        );

        $activation = strpos($code, '$authuser->assignPlan(');
        $burn = strpos($code, 'new PlanUserCoupon()');

        $this->assertNotFalse($activation, 'assignPlan has moved out of this method');
        $this->assertNotFalse($burn, 'the coupon consumption has moved out of this method');
        $this->assertLessThan(
            $burn,
            $activation,
            'the coupon is consumed BEFORE the plan activates, so a failure in between burns a '
            . 'use -- platform-wide on a limited coupon -- for a purchase that activated nothing. '
            . 'F196 Option A: the refusal must precede the persistence.'
        );

        /* And it records the order it actually belongs to, not a second unrelated uniqid(). */
        $this->assertStringContainsString(
            '$userCoupon->order     = $orderID;',
            $code,
            'the usage row carries an order id for which no plan order is ever created, so '
            . '`plan_user_coupons.order` resolves to nothing'
        );
    }

    /**
     * F3 — THE USAGE WRITE AND THE ACTIVATION MUST BE GUARDED BY THE SAME CONDITION.
     *
     * They were not: the usage write sat under a `$price < 1` test while the activation-and-return
     * sat under `$price <= 0`. For a price strictly between 0 and 1 — a plan priced 10 with a 95%
     * coupon gives 0.50, and both values are creatable in the admin UI — the initiator wrote a
     * `PlanUserCoupon` row and then **kept going**: it created the intent, sent the buyer to
     * Paytm, and the return wrote a SECOND usage row for the same purchase.
     *
     * `PlanCoupon::used_coupon()` counts every user's rows, so the limit is platform-wide and the
     * deactivation flips `is_active = 0` for everybody. A coupon in that window burned its limit
     * at twice the rate and went dead for the whole platform at half its intended usage.
     *
     * STRUCTURAL, because the end-to-end version cannot live in this suite: the method's coupon
     * lookup is `whereRaw('BINARY `code` = ?')`, a syntax error on SQLite, which the premise case
     * below already measures. A behavioural test would redirect 302 having written nothing and
     * prove only that the dialect differs — the trap this file exists to document.
     *
     * Asked of the METHOD with comments stripped, because the docblocks around both branches now
     * quote the conditions in order to explain them, and a census that cannot tell an explanation
     * from an instance is the mistake this project keeps repeating.
     */
    public function test_f3_the_coupon_usage_write_is_guarded_by_the_activation_condition(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/PaytmPaymentController.php'));

        $start = strpos($source, 'public function planPayWithPaytm(');
        $this->assertNotFalse($start, 'the initiator has been renamed; re-read this assertion');
        $method = substr($source, $start);
        $next = strpos($method, 'public function ', 10);
        $method = $next === false ? $method : substr($method, 0, $next);

        $code = implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all('<?php ' . $method)
        ));

        $this->assertStringNotContainsString(
            'if ($price < 1)',
            $code,
            'the coupon usage write is guarded by `$price < 1` while the activation is guarded by '
            . '`$price <= 0`. For 0 < price < 1 the usage row is written AND the buyer goes to the '
            . 'gateway, so the return writes a second row for one purchase — and the limit is '
            . 'platform-wide, so the coupon dies for everybody at half its intended usage.'
        );

        /*
         * THERE IS NOW EXACTLY ONE PRICE CONDITION IN THIS METHOD, which is the structural reason
         * F3 cannot recur: F240's fix merged the two blocks, so the usage write lives inside the
         * activation's own success branch and cannot be guarded by a different test from it.
         * The ORDERING is asserted by `test_f240_...` above; what is unique to F3 is that no
         * second, wider condition exists to re-open the gap.
         */
        $this->assertSame(
            1,
            preg_match_all('/if\s*\(\s*\$price\s*[<>=]/', $code),
            'this method has more than one price condition again. F3 was two of them disagreeing: '
            . 'the usage write under `< 1` and the activation under `<= 0`, so a price strictly '
            . 'between them wrote a usage row AND sent the buyer to the gateway.'
        );

        $this->assertNotFalse(
            strpos($code, 'new PlanUserCoupon()'),
            'the usage write has moved out of this method; re-read F3'
        );

        /* AND THE LIMIT GATE IS `>=`. With `==`, once `used` exceeds `limit` -- which the
           double-count above is one way to arrange -- the gate passes. */
        $this->assertStringContainsString(
            'if ($coupons->limit <= $usedCoupun)',
            $code,
            'the limit gate is `==`, so it stops testing once `used` has overshot `limit`'
        );
    }

    /**
     * THE DEFECT ITSELF. A request that cannot succeed must not consume a coupon use.
     */
    public function test_a_refused_unconfigured_checkout_persists_no_coupon_use(): void
    {
        $this->seedSuperAdminCurrency();

        [$merchant] = $this->makeTenant();
        $plan       = $this->makePlan('499');
        $coupon     = $this->makeCoupon('F234NEG', 10, 5);

        $this->actingAs($merchant)->post(route('coingate.prepare.plan'), [
            'plan_id'     => Crypt::encrypt($plan->id),
            'total_price' => 499,
            'coupon'      => 'F234NEG',
        ]);

        $this->assertSame(
            0,
            DB::table('plan_user_coupons')->where('coupon_id', $coupon->id)->count(),
            'A CoinGate checkout that CANNOT SUCCEED (no coingate_mode setting, so the handler '
            . 'raises at :144 and flashes an error) still persisted a PlanUserCoupon row. The '
            . 'coupon use is burned on a request that produced no payment and no PlanOrder. '
            . 'F196 Option A: the refusal must precede persistence.'
        );

        $this->assertSame(
            0,
            DB::table('plan_orders')->count(),
            'Premise broken: a plan_orders row exists, so this request was not the '
            . 'cannot-succeed case this test means to measure.'
        );
    }

    /**
     * THE PLATFORM-WIDE HALF. One merchant must not be able to exhaust a coupon for everyone.
     *
     * A single POST cannot show this. `used_coupon()` counts across all users, so the
     * deactivation at `:155` fires once the row count reaches `limit` -- which a merchant
     * reaches by retrying a gateway that was never going to work.
     */
    public function test_repeated_unconfigured_checkouts_cannot_exhaust_the_coupon_for_everybody(): void
    {
        $this->seedSuperAdminCurrency();

        [$merchant] = $this->makeTenant();
        $plan       = $this->makePlan('499');
        $coupon     = $this->makeCoupon('F234EXH', 10, 2);

        for ($attempt = 0; $attempt <= 2; $attempt++) {
            $this->actingAs($merchant)->post(route('coingate.prepare.plan'), [
                'plan_id'     => Crypt::encrypt($plan->id),
                'total_price' => 499,
                'coupon'      => 'F234EXH',
            ]);
        }

        $this->assertSame(
            0,
            DB::table('plan_user_coupons')->where('coupon_id', $coupon->id)->count(),
            'Three refused checkouts against a limit-2 coupon persisted uses.'
        );

        $this->assertSame(
            1,
            (int) DB::table('plan_coupons')->where('id', $coupon->id)->value('is_active'),
            'A coupon with limit 2 was DEACTIVATED by three CoinGate checkouts that could not '
            . 'succeed. `PlanCoupon::used_coupon()` counts every user, so this coupon is now '
            . 'unusable BY EVERY MERCHANT ON THE PLATFORM -- burned by one merchant retrying an '
            . 'unconfigured gateway.'
        );
    }

    /**
     * POSITIVE CONTROL. The fix must not buy its refusal by breaking the legitimate path.
     *
     * With both CoinGate rows present the handler gets past `:200`, so the coupon block is
     * reached on its merits and the use SHOULD be recorded. The gateway HTTP call itself is
     * unreachable from this environment, so the assertion is on the database, not the response.
     */
    public function test_a_configured_install_still_records_the_coupon_use(): void
    {
        $this->seedSuperAdminCurrency('USD', self::COINGATE_SETTINGS);

        [$merchant] = $this->makeTenant();
        $plan       = $this->makePlan('499');
        $coupon     = $this->makeCoupon('F234POS', 10, 5);

        $this->actingAs($merchant)->post(route('coingate.prepare.plan'), [
            'plan_id'     => Crypt::encrypt($plan->id),
            'total_price' => 499,
            'coupon'      => 'F234POS',
        ]);

        $this->assertSame(
            1,
            DB::table('plan_user_coupons')->where('coupon_id', $coupon->id)->count(),
            'A CONFIGURED CoinGate install no longer records a legitimate coupon use. The F234 '
            . 'refusal has been placed too early, or made too broad, and is now refusing real '
            . 'purchases. This is the control that stops the fix passing by refusing everything.'
        );
    }


    // ---------------------------------------------------------------------------------------
    // THE SIBLINGS CANNOT BE DRIVEN HERE AT ALL, and that is the finding of this slice.
    // ---------------------------------------------------------------------------------------

    /**
     * THE THIRD SETTING — found by independent review, and the suite could not have reached it.
     *
     * The first version of the guard required `coingate_mode` and `coingate_auth_token` only. But
     * the branch it protects reads `$admin_payment_setting['CURRENCY_NAME']` as a **bare**
     * subscript at `:211-212` — unlike `:180`, which guards the same key with `!empty()` — inside
     * the `try` and **after** the coupon row is saved and the coupon possibly deactivated. So an
     * installation holding both CoinGate rows but no `CURRENCY_NAME` row reproduced F234 exactly:
     * the same burn, one setting over.
     *
     * **No existing test could reach that state.** `PlanPaymentTestCase::seedSuperAdminCurrency()`
     * inserts `CURRENCY_NAME` in every fixture — including the "unconfigured" cases — so the whole
     * suite is blind to it by construction. This case seeds the CoinGate rows and deliberately
     * omits the currency, which is the only way the state exists.
     */
    public function test_a_missing_currency_setting_is_also_refused_before_any_write(): void
    {
        /* The platform admin, and the CoinGate rows, but NO CURRENCY_NAME. seedSuperAdminCurrency()
           cannot express this, because inserting that row is the one thing it always does. */
        \Illuminate\Support\Facades\Cache::flush();
        $admin = $this->makePlatformAdmin();

        foreach (self::COINGATE_SETTINGS as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $admin->id, 'store_id' => $admin->current_store,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        [$merchant] = $this->makeTenant();
        $plan       = $this->makePlan('499');
        $coupon     = $this->makeCoupon('F234CUR', 10, 5);

        $response = $this->actingAs($merchant)->post(route('coingate.prepare.plan'), [
            'plan_id'     => Crypt::encrypt($plan->id),
            'total_price' => 499,
            'coupon'      => 'F234CUR',
        ]);

        $response->assertForbidden();

        $this->assertSame(
            0,
            DB::table('plan_user_coupons')->where('coupon_id', $coupon->id)->count(),
            'an install with both CoinGate rows but no CURRENCY_NAME still burned a coupon use. '
            . 'CoingateController:211-212 reads that key as a bare subscript, inside the try and '
            . 'after the usage is persisted, so leaving it out of the required-key list reproduces '
            . 'F234 one setting over.'
        );

        $this->assertSame(
            1,
            (int) DB::table('plan_coupons')->where('id', $coupon->id)->value('is_active'),
            'the coupon was deactivated by a request that could not succeed'
        );
    }

    /**
     * THE SUITE IS STRUCTURALLY BLIND TO THE F234 FAMILY, and this pins why.
     *
     * Two sibling tests used to sit here, asserting that Mollie and Midtrans persist no coupon
     * use on an unconfigured install. **Both passed, and both were worthless.** Driving them
     * directly showed what they were really measuring:
     *
     *   Mollie   -> QueryException, `near "`code`": syntax error`, on
     *               `select * from "plan_coupons" where BINARY `code` = ? and "is_active" = 1`
     *   Midtrans -> ErrorException, `Undefined array key "midtrans_secret_key"` at
     *               `MidtransController.php:42`
     *
     * Neither request ever reached its coupon block. "No usage was persisted" was true for a
     * reason that has nothing to do with the defect -- the assertion satisfied by something
     * adjacent to what it meant to measure, which is this repository's documented failure mode
     * and the exact thing F196's own evidence file was built to avoid.
     *
     * **`whereRaw('BINARY `code` = ?')` IS MYSQL-ONLY.** On SQLite it is a syntax error, not a
     * mismatch -- the repository already knows this and says so at
     * `NepalstePaymnetController.php:154`. It appears in **43 files** under
     * `app/Http/Controllers/`, so every initiator whose coupon branch uses it throws on SQLite
     * before writing anything.
     *
     * CoinGate is reachable here for one reason only: F228 routed its coupon lookup through
     * `GuardsPlanActivation::planCouponForRecording()`, which is plain Eloquent plus a PHP-side
     * exact comparison and therefore runs on SQLite. **So the suite can measure F234 on CoinGate
     * and on nothing else**, and a sibling census built from suite results would read "only
     * CoinGate is affected" while saying nothing at all about the other fourteen.
     *
     * The siblings are therefore driven on a real MariaDB instead, where the lookup actually
     * executes:
     * `tests/Support/Security/bin/verify-plan-coupon-refusal-ordering-on-mysql.php`.
     * That is the project's documented home for evidence that cannot live in the suite -- and a
     * conditionally-skipped test here is not an option, because the gate asserts the declared
     * skip set in both directions.
     */
    public function test_premise_the_sqlite_suite_cannot_reach_the_mysql_only_coupon_lookups(): void
    {
        /*
         * NEEDLED ON THE EXECUTABLE FORM, NOT THE LITERAL -- review found the first version
         * desensitised, and the example was this very slice.
         *
         * Searching raw source for ``BINARY `code` `` also matches the explanatory COMMENTS that
         * quote the defect. `CoingateController.php` was being counted purely on the comment this
         * fix added to it, and `NepalstePaymnetController.php` carries both a live call and a
         * comment about it. So the count COULD NOT FALL as handlers were converted: each
         * conversion leaves a comment behind that keeps its file counted, which desensitises the
         * exact signal the ratchet exists to emit.
         *
         * `PlanCoupon::whereRaw('BINARY` is the call itself. A comment quoting the fragment does
         * not match it, and a converted handler drops out of the count as it should.
         */
        $binaryForm = [];

        foreach ((array) glob(base_path('app/Http/Controllers/*.php')) as $file) {
            if (str_contains((string) file_get_contents($file), "PlanCoupon::whereRaw('BINARY")) {
                $binaryForm[] = basename($file);
            }
        }

        $this->assertGreaterThanOrEqual(
            38,
            count($binaryForm),
            'The MySQL-only `BINARY `code`` coupon lookup has largely disappeared from '
            . 'app/Http/Controllers. If it was replaced with a portable form, these initiators '
            . 'became drivable on SQLite and the siblings should get real suite coverage here '
            . 'instead of a MariaDB script.'
        );

        $this->assertContains(
            'MolliePaymentController.php',
            $binaryForm,
            'Mollie no longer uses the MySQL-only lookup, so it can now be driven on SQLite and '
            . 'deserves a real test in this file rather than a MariaDB script.'
        );
    }

    /**
     * THE PLACEMENT TRAP, pinned so it cannot be reintroduced.
     *
     * The entire body of `coingatePaymentPrepare()` sits inside `try { ... } catch (\Exception
     * $e) { return redirect()->back()->with('error', $e->getMessage()); }` (`:122`, `:228-229`).
     * `abort(403)` raises `HttpException`, which EXTENDS `\Exception` -- so a guard written
     * inside that try block is SWALLOWED and silently downgraded into a flash redirect, and the
     * refusal stops being a refusal.
     *
     * So the guard has to sit ABOVE the try, and this test is the only thing that can tell the
     * two placements apart: both leave the database clean, and only the status code differs.
     */
    public function test_the_credential_refusal_is_not_swallowed_by_the_methods_own_catch(): void
    {
        $this->seedSuperAdminCurrency();

        [$merchant] = $this->makeTenant();
        $plan       = $this->makePlan('499');

        $response = $this->actingAs($merchant)->post(route('coingate.prepare.plan'), [
            'plan_id'     => Crypt::encrypt($plan->id),
            'total_price' => 499,
        ]);

        $response->assertForbidden();
    }
}
