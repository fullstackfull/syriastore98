<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\EmailTemplate;
use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-23 / #69 -- email-template tenancy. TWO POPULATIONS, OPPOSITE ANSWERS, AND A SECOND
 * DEFECT ONE LINE AWAY.
 *
 * WHAT #69 CLAIMED. That `EmailTemplate::where('name', 'LIKE', $name)->first()`, repeated in six
 * vendored modules and once in the host, is an unscoped cross-tenant read, so tenant B's customers
 * receive tenant A's template content.
 *
 * THAT IS RIGHT FOR THE NAMES THE MODULE COPIES RESOLVE AND WRONG FOR THE HOST'S, and the
 * difference is the whole story. `email_templates` has two writers with two different match keys:
 *
 *   MODULE NAMES are seeded PER TENANT. All seven module writers are
 *   `EmailTemplate::where('name', $eTemp)->where('created_by', Auth::user()->id)->count()` then
 *   `create([... 'created_by' => Auth::user()->id])`, so 'Lottery Win', 'Pre Order Created',
 *   'Product Enquiry', 'Lucky Winner', 'Review Reminder', 'Payment Reminder' and 'Product Catelog'
 *   get ONE ROW PER TENANT. Under the unscoped resolve THE LOWEST-ID TENANT'S ROW WINS FOR
 *   EVERYONE -- measured below. #69's premise holds here.
 *
 *   THE SIX HOST NAMES go the other way, and this is the half that matters for the fix.
 *
 * I FIRST MEASURED ONLY THE HOST NAMES AND GENERALISED FROM THEM, writing that "the filed premise
 * does not hold". That was too broad by exactly the population the module copies resolve. It is
 * corrected here, and the correction is the fourth revision of this analysis -- every one of them
 * forced by measurement rather than reasoning.
 *
 * THE HOST NAMES, measured:
 *
 *   FOR THE SIX HOST NAMES `email_templates` IS A PLATFORM-GLOBAL CATALOGUE.
 *   `Utility::defaultEmail()` seeds it with `EmailTemplate::firstOrCreate(['name' => $name], ...)`
 *   -- the match key is `name` ALONE. Driven as three different users in one run: 6 rows after the
 *   first call, 6 after the second, 6 after the third, every one `created_by = <the super admin>`.
 *   A merchant never gets rows of their own, and `email_template_langs` hangs off those same six
 *   ids, so there is ONE body per template for the whole platform. There is nothing per-tenant to
 *   leak, and resolving by name is CORRECT.
 *
 *   The per-tenant state is `user_email_templates` (template_id, user_id, is_active), which
 *   `Utility::userDefaultData()` seeds for every user against every template at `is_active = 0`,
 *   and which a merchant turns on through Settings -> Email Notification:
 *   `SettingController:1681-1688` lists the SUPER ADMIN's rows (not `Auth::id()`'s), and
 *   `EmailTemplateController::updateEmailNotificationStatus` writes for `type` in
 *   `['super admin', 'admin']`, which a merchant is. So the merchant-facing path works.
 *
 * So `created_by` on `email_templates` is vestigial, and `SettingController:1686`'s
 * `whereNot('created_by', Auth::user()->id)` -- which the earlier census flagged as a deliberate
 * cross-tenant read -- is a fallback for a platform with no super admin, not an anomaly.
 *
 * THE REAL DEFECT IS THE NEXT LINE. The six module copies do, UNCONDITIONALLY and BEFORE the
 * is_active check:
 *
 *   $slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;
 *       PartialPayments/…/PartialPaymentsController.php:313
 *       ProductCatelog/…/ProductCatelogController.php:303
 *       SpinToWinReward/…/SpinToWinRewardController.php:421
 *       ReviewReminder/…/ReviewReminderController.php:221
 *       ProductEnquiry/…/ProductEnquiryController.php:282
 *       LotteryPrizes/…/LotteryPrizesController.php:722
 *
 * `$template->created_by` is ALWAYS the platform admin. So the storefront slug baked into every
 * tenant's customer-facing email is derived from the platform admin rather than from the `$store`
 * the function was handed -- and `$store` is already a parameter of all six.
 *
 * MEASURED, by executing LotteryPrizesController::sendOwnerEmailTemplate for a real merchant with
 * a real activated template:
 *
 *   platform admin has NO store (the normal case)
 *       -> ErrorException: Attempt to read property "slug" on null, at LotteryPrizesController:722
 *          Laravel's handler turns the warning into a throw (error_reporting() === -1), and
 *          ErrorException extends Exception, so each caller's catch(\Exception) swallows it. Every
 *          module mail -- lottery win/fail/reminder, product enquiry, spin-to-win, review
 *          reminder, product catalogue, partial-payment reminder -- silently never sends, for
 *          every tenant.
 *   platform admin HAS a store
 *       -> the slug resolves to THAT store ('PLATFORM-ADMIN-STORE' in the probe), so every
 *          tenant's email links point at the wrong storefront. This is the cross-tenant exposure
 *          #69 was reaching for, in the slug line rather than the template resolve.
 *
 * I WAS WRONG ABOUT THIS THREE TIMES AND MEASUREMENT CORRECTED EACH ONE: first a content leak,
 * then a null-`UserEmailTemplate` ErrorException, then a silent outage for every tenant but the
 * lowest-id owner. All three assumed `email_templates` was per-tenant. It is not. The one-line fix
 * I was about to apply to the host copy -- adding `->where('created_by', $owner->id)` -- would
 * have made `$template` null for every merchant and stopped ALL order-confirmation mail platform
 * wide. It is not applied, and this file exists so the next reader does not reach for it either.
 *
 * WHY THERE IS NO SHARED HOST-SIDE FIX, and what to do instead:
 * docs/security/P01-EMAIL-TEMPLATE-TENANCY-PROPOSAL.md.
 */
class EmailTemplateOwnerResolutionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SLUG_LINE = "Store::where('created_by', \$template->created_by)->select('slug')->first()->slug";

    /** The six vendored copies, by file and by the line the slug derivation sits on. */
    private const COPIES = [
        'packages/workdo/PartialPayments/src/app/Http/Controllers/PartialPaymentsController.php' => 313,
        'packages/workdo/ProductCatelog/src/app/Http/Controllers/ProductCatelogController.php' => 303,
        'packages/workdo/SpinToWinReward/src/app/Http/Controllers/SpinToWinRewardController.php' => 421,
        'packages/workdo/ReviewReminder/src/app/Http/Controllers/ReviewReminderController.php' => 221,
        'packages/workdo/ProductEnquiry/src/app/Http/Controllers/ProductEnquiryController.php' => 282,
        'packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryPrizesController.php' => 722,
    ];

    /**
     * A SEVENTH COPY OF THE UNSCOPED RESOLVE, which #69's "six modules" misses:
     * `packages/workdo/PreOrder/src/app/Models/PreOrderHistory.php:95`. Found by an independent
     * investigation, verified here. It is reached from HOST code -- `PaymentController.php` --
     * and from `PreOrder/src/app/Console/PreOrder.php:114`, another no-ambient-tenant caller.
     *
     * It shares the resolve defect and NOT the slug defect: it is the `sendEmailTemplate` shape,
     * like the host's, and carries no `Store::where('created_by', $template->created_by)` line.
     * That distinction is asserted rather than assumed, because lumping it in with the six would
     * overstate the slug finding by one site.
     */
    private const SEVENTH_RESOLVE = 'packages/workdo/PreOrder/src/app/Models/PreOrderHistory.php';

    /* ---------------------------------------------------------------------------------------- *
     *  THE ARCHITECTURE -- i.e. why #69's filed premise is void
     * ---------------------------------------------------------------------------------------- */

    /**
     * THE HOST POPULATION. `Utility::defaultEmail()`'s `firstOrCreate` matches on `name` alone, so
     * these six rows are created once for the whole platform by whoever triggers it first. Contrast
     * `test_every_module_seeds_its_template_names_per_tenant` below, which is the other population
     * and the one the six module copies actually resolve.
     */
    public function test_the_six_host_template_names_are_a_platform_global_catalogue(): void
    {
        $admin = $this->makePlatformAdmin();
        [$merchantOne] = $this->makeTenant();
        [$merchantTwo] = $this->makeTenant();

        $counts = [];
        foreach ([$admin, $merchantOne, $merchantTwo] as $user) {
            $this->actingAs($user);
            Cache::flush();
            (new EmailTemplate)->flushCache();
            Utility::defaultEmail();
            $counts[] = (int) DB::table('email_templates')->count();
        }

        $this->assertSame([6, 6, 6], $counts,
            'defaultEmail() created rows for the second or third caller. If the catalogue has '
            . 'become per-tenant, every conclusion in this file needs re-measuring.');

        $this->assertSame([$admin->id],
            DB::table('email_templates')->distinct()->pluck('created_by')->map('intval')->all(),
            'Every catalogue row must belong to the platform admin.');

        $this->assertStringContainsString("EmailTemplate::firstOrCreate(\n                    ['name' => \$eTemp],",
            file_get_contents(base_path('app/Models/Utility.php')),
            'The firstOrCreate match key is no longer `name` alone -- the catalogue may now be '
            . 'per-tenant after all.');

        // One body per template for the whole platform: no per-tenant content exists to leak.
        $parents = DB::table('email_template_langs')->distinct()->pluck('parent_id')->map('intval')->sort()->values()->all();
        $this->assertSame(DB::table('email_templates')->orderBy('id')->pluck('id')->map('intval')->all(), $parents);
    }

    /**
     * `email_templates` has no `store_id` at all, and `EmailTemplate` carries no scope -- which is
     * consistent with a global catalogue and is the reason an ambient global scope is the wrong
     * instrument here.
     */
    public function test_the_model_is_unscoped_and_the_table_has_no_tenant_column(): void
    {
        $this->assertSame([], array_keys((new EmailTemplate)->getGlobalScopes()));
        $this->assertNotContains(EmailTemplate::class, config('tenancy.scoped_models', []));
        $this->assertFalse(Schema::hasColumn('email_templates', 'store_id'));
        $this->assertTrue(Schema::hasColumn('email_templates', 'created_by'));
    }

    /** The per-tenant state, and that it is seeded for every user against every template. */
    public function test_the_per_tenant_state_is_user_email_templates_seeded_for_every_pair(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin);
        Utility::defaultEmail();

        [$merchant] = $this->makeTenant();
        Utility::userDefaultData($merchant->id);

        $this->assertSame(6, (int) DB::table('user_email_templates')->where('user_id', $merchant->id)->count(),
            'The merchant should hold one row per catalogue template.');
        $this->assertSame([0],
            DB::table('user_email_templates')->where('user_id', $merchant->id)
                ->distinct()->pluck('is_active')->map('intval')->all(),
            'Rows must seed OFF -- that is why the merchant-facing toggle below matters.');
    }

    /**
     * THE MERCHANT CAN TURN THEIR MAIL ON. This is what makes #69's "silent outage for every
     * tenant" framing wrong: the screen lists the SUPER ADMIN's rows, not `Auth::id()`'s, and the
     * writer accepts `type = 'admin'`.
     */
    public function test_a_merchant_can_activate_a_template_through_the_settings_screen(): void
    {
        $setting = file_get_contents(base_path('app/Http/Controllers/SettingController.php'));
        $this->assertStringContainsString("EmailTemplate::where('created_by', \$s_admin->id)->get()", $setting,
            'The email-notification tab no longer lists the platform admin\'s catalogue, so a '
            . 'merchant may no longer see any template to toggle.');

        $controller = file_get_contents(base_path('app/Http/Controllers/EmailTemplateController.php'));
        $this->assertStringContainsString(
            "if(\$user->type == 'super admin' || \$user->type == 'admin')", $controller,
            'updateEmailNotificationStatus no longer admits a merchant.');

        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin);
        Utility::defaultEmail();

        [$merchant] = $this->makeTenant();
        Utility::userDefaultData($merchant->id);

        $this->assertSame('admin', $merchant->type,
            'A merchant must be type `admin`, or the writer above would refuse them.');

        $ids = DB::table('email_templates')->pluck('id')->all();
        $this->actingAs($merchant);
        $this->post(route('update.email.statue'),
            array_fill_keys(array_map('strval', $ids), 1));

        $this->assertSame(6, (int) DB::table('user_email_templates')
            ->where('user_id', $merchant->id)->where('is_active', 1)->count(),
            'A merchant posting the settings form must be able to activate every template. If '
            . 'this is 0, the "merchant cannot enable mail" reading would be correct after all.');
    }

    /* ---------------------------------------------------------------------------------------- *
     *  THE TWO POPULATIONS -- and the correction that cost me a fourth revision of #69
     *
     *  `email_templates` holds TWO KINDS OF ROW, seeded by two different writers with two
     *  different match keys, and they behave oppositely under the same unscoped resolve:
     *
     *    THE SIX HOST NAMES ('Order Created', 'Status Change', 'Order Created For Owner',
     *    'Stock Status', 'Abandon Cart', 'Abandon Wishlist') come from `Utility::defaultEmail()`,
     *    whose `firstOrCreate(['name' => $name], ...)` matches on NAME ALONE. One row per name for
     *    the whole platform, owned by the operator. The unscoped resolve is CORRECT, and the
     *    `created_by` predicate #69 implies would return null for every merchant -- that is the
     *    platform-wide outage recorded above.
     *
     *    EVERY MODULE NAME ('Lottery Win', 'Pre Order Created', 'Product Enquiry', 'Lucky Winner',
     *    'Review Reminder', 'Payment Reminder', 'Product Catelog') comes from the module's OWN
     *    settings screen, and all seven of those writers are
     *    `EmailTemplate::where('name', $eTemp)->where('created_by', Auth::user()->id)->count()`
     *    then `create([... 'created_by' => Auth::user()->id])`. ONE ROW PER TENANT PER NAME. Under
     *    the same unscoped resolve the LOWEST-ID TENANT'S ROW WINS FOR EVERYONE.
     *
     *  I MEASURED ONLY THE FIRST POPULATION AND GENERALISED FROM IT, and that made my "the filed
     *  premise does not hold" too broad by exactly the population the six module copies actually
     *  resolve. The premise holds for module names. It does not hold for the host names. Both
     *  halves matter: the first is the defect, the second is why the obvious fix must not be
     *  applied to `Utility::sendEmailTemplate`.
     * ---------------------------------------------------------------------------------------- */

    /**
     * EVERY MODULE WRITER IS PER-TENANT. Asserted across all seven so the two-population claim
     * cannot drift, and so a reader does not have to take the distinction on trust.
     */
    public function test_every_module_seeds_its_template_names_per_tenant(): void
    {
        $writers = [
            'LotteryPrizes/src/app/Http/Controllers/LotteryPrizesController.php' => 191,
            'PartialPayments/src/app/Http/Controllers/PartialPaymentsController.php' => 145,
            'ProductEnquiry/src/app/Http/Controllers/ProductEnquiryController.php' => 413,
            'ReviewReminder/src/app/Http/Controllers/ReviewReminderController.php' => 51,
            'SpinToWinReward/src/app/Http/Controllers/SpinToWinRewardController.php' => 177,
            'PreOrder/src/app/Http/Controllers/PreOrderController.php' => 386,
        ];

        foreach ($writers as $path => $line) {
            $lines = file(base_path('packages/workdo/' . $path));

            $this->assertStringContainsString(
                "EmailTemplate::where('name', \$eTemp)->where('created_by', Auth::user()->id)->count()",
                $lines[$line - 1],
                "packages/workdo/{$path}:{$line} is no longer the per-tenant existence check. If a "
                . 'module has moved to a platform-wide match key, its template names join the host '
                . 'population and the resolve becomes correct for them.'
            );
        }

        // And the host writer, for contrast: matched on name ALONE.
        $this->assertStringContainsString(
            "EmailTemplate::firstOrCreate(
                    ['name' => \$eTemp],",
            file_get_contents(base_path('app/Models/Utility.php')),
            'Utility::defaultEmail no longer matches on name alone.'
        );
    }

    /**
     * THE DEFECT #69 FILED, MEASURED -- on a MODULE name, which is the population the six module
     * copies resolve.
     *
     * MEASURED: two tenants each create their own 'Lottery Win' through the module settings screen
     * and each activate their own. The unscoped resolve returns the lower id for BOTH. Tenant A's
     * activation row for it is 1 and its body is tenant A's. Tenant B's activation row for THAT
     * template is 0 -- so tenant B's lottery mail is silently skipped, and the body it would
     * otherwise have used is tenant A's, not its own.
     *
     * SO THE LEAK IS CONDITIONAL AND THE OUTAGE IS UNCONDITIONAL. The leak needs
     * `(A's template, B)` to be `is_active = 1`, which no module screen can produce -- each lists
     * only `where('created_by', Auth::user()->id)`. The outage needs nothing.
     */
    public function test_a_module_template_name_resolves_the_lowest_id_tenants_row_for_everyone(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin);
        Utility::defaultEmail();

        $tenants = [];
        foreach (['a', 'b'] as $key) {
            [$owner, $store] = $this->makeTenant();
            $this->actingAs($owner);

            // Exactly what every module's settings screen does -- see the test above.
            $existing = EmailTemplate::where('name', 'Lottery Win')->where('created_by', $owner->id)->count();
            $this->assertSame(0, $existing);

            $template = EmailTemplate::create([
                'name' => 'Lottery Win', 'from' => 'x', 'created_by' => $owner->id,
            ]);
            DB::table('email_template_langs')->insert([
                'parent_id' => $template->id, 'language' => 'en', 'subject' => 's',
                'content' => 'BODY-OF-TENANT-' . strtoupper($key),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            Utility::userDefaultData($owner->id);
            DB::table('user_email_templates')
                ->where('user_id', $owner->id)->where('template_id', $template->id)
                ->update(['is_active' => 1]);

            $tenants[$key] = ['owner' => $owner, 'store' => $store, 'template' => (int) $template->id];
        }

        Cache::flush();
        (new EmailTemplate)->flushCache();

        $this->assertNotSame($tenants['a']['template'], $tenants['b']['template'],
            'The two tenants must hold DIFFERENT rows, or there is one population after all.');
        $this->assertSame(2,
            (int) DB::table('email_templates')->where('name', 'Lottery Win')->count(),
            'A module name must have one row per tenant.');

        $resolved = EmailTemplate::where('name', 'LIKE', 'Lottery Win')->first();

        $this->assertSame($tenants['a']['template'], (int) $resolved->id,
            "The unscoped resolve must return the LOWER id -- tenant A's -- for every caller.");

        // Tenant A: correct by accident of registration order.
        $rowA = DB::table('user_email_templates')
            ->where('template_id', $resolved->id)->where('user_id', $tenants['a']['owner']->id)->first();
        $this->assertSame(1, (int) $rowA->is_active);

        // Tenant B: the row exists (userDefaultData seeds every pair) but is OFF, and no module
        // screen can reach it.
        $rowB = DB::table('user_email_templates')
            ->where('template_id', $resolved->id)->where('user_id', $tenants['b']['owner']->id)->first();
        $this->assertNotNull($rowB,
            'userDefaultData seeds a row for every (template, user) pair, including other '
            . "tenants' templates -- that is what makes this a silent skip rather than a throw.");
        $this->assertSame(0, (int) $rowB->is_active,
            "Tenant B's activation row for the RESOLVED template is off, so its lottery mail is "
            . 'silently skipped. PINNING A DEFECT: when this changes, the resolve was fixed.');

        // And the body tenant B would have used is not its own.
        $body = DB::table('email_template_langs')->where('parent_id', $resolved->id)->value('content');
        $this->assertSame('BODY-OF-TENANT-A', $body,
            "If tenant B's activation row for A's template were ever 1 -- which no module screen "
            . "can produce, but a seed or a manual row can -- tenant B's customers would receive "
            . "tenant A's body. That is the conditional leak.");
    }

    /* ---------------------------------------------------------------------------------------- *
     *  THE REAL DEFECT
     * ---------------------------------------------------------------------------------------- */

    /** All six copies still carry the line, at the lines this file and the proposal cite. */
    public function test_all_six_module_copies_derive_the_slug_from_the_template_owner(): void
    {
        foreach (self::COPIES as $path => $line) {
            $lines = file(base_path($path));

            $this->assertArrayHasKey($line - 1, $lines, "{$path} is shorter than line {$line}.");
            $this->assertStringContainsString(self::SLUG_LINE, $lines[$line - 1],
                "{$path}:{$line} no longer holds the slug derivation. In this project the citation "
                . 'is the evidence -- re-measure the line number before changing it here.');
        }
    }

    /**
     * The correct source is already in scope at all six sites, which is what makes this a
     * one-token upstream fix rather than a design change.
     */
    public function test_every_copy_already_receives_the_store_it_should_have_used(): void
    {
        foreach (array_keys(self::COPIES) as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertMatchesRegularExpression(
                '/function sendOwnerEmailTemplate\([^)]*\$store\b/s',
                $source,
                "{$path}: sendOwnerEmailTemplate no longer takes \$store, so the fix would need to "
                . 'derive it instead of using what it is handed.'
            );
        }
    }

    /**
     * EXECUTED. A real merchant, a real activated template, the real vendored function.
     */
    public function test_a_module_mail_throws_because_the_platform_admin_has_no_store(): void
    {
        [$merchant, $store, $template] = $this->lotteryMailFixture();

        $this->assertNull(Store::where('created_by', $template->created_by)->first(),
            'The platform admin has acquired a store, so this case is now the other one.');

        try {
            $this->sendLotteryOwnerMail($merchant, $store);
            $this->fail('sendOwnerEmailTemplate did not throw. If the slug derivation has been '
                . 'fixed upstream, this whole file should be retired rather than relaxed.');
        } catch (\ErrorException $e) {
            $this->assertSame('Attempt to read property "slug" on null', $e->getMessage());
            $this->assertSame(722, $e->getLine(),
                'The throw moved off line 722; re-measure every citation.');
            $this->assertInstanceOf(\Exception::class, $e,
                'ErrorException must remain an Exception -- that is why every caller\'s '
                . 'catch(\Exception) swallows this and the failure is invisible.');
        }
    }

    /**
     * EXECUTED. The other half of the same defect: with a platform-admin store present, the slug
     * resolves to it, so the merchant's customers get links to the wrong storefront.
     */
    public function test_with_a_platform_admin_store_the_wrong_slug_is_used(): void
    {
        [$merchant, $store, $template] = $this->lotteryMailFixture();

        $admin = User::find($template->created_by);
        $adminStore = $this->makeStore($admin, ['slug' => 'platform-admin-store']);
        Cache::flush();
        (new EmailTemplate)->flushCache();

        $derived = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;

        $this->assertSame($adminStore->slug, $derived);
        $this->assertNotSame($store->slug, $derived,
            "The slug baked into the merchant's customer email is the platform admin's store, not "
            . 'the store the function was handed.');
    }

    /**
     * BOUNDING THE BLAST RADIUS. The host's own `Utility::sendEmailTemplate` -- the
     * order-confirmation path, 54 call sites -- has NO slug line, so it is NOT affected by this
     * defect. Asserted so the proposal's scope claim cannot drift.
     */
    public function test_the_host_order_mail_path_has_no_slug_derivation(): void
    {
        $source = file_get_contents(base_path('app/Models/Utility.php'));
        $start = strpos($source, 'public static function sendEmailTemplate(');
        $end = strpos($source, 'public static function SendMsgs(');

        $this->assertIsInt($start);
        $this->assertIsInt($end);

        $body = substr($source, $start, $end - $start);

        $this->assertStringNotContainsString(self::SLUG_LINE, $body,
            'The host order-mail path has acquired the slug derivation, which would put the '
            . 'checkout path in scope.');
        $this->assertStringContainsString("EmailTemplate::where('name', 'LIKE', \$emailTemplate)->first()", $body,
            'The host copy no longer resolves by name; re-read the architecture tests above.');
    }

    /**
     * THE ONE HOST-SIDE ROBUSTNESS GAP, executed: a user with no `user_email_templates` row -- a
     * merchant who has never loaded their dashboard, since `HomeController::index` is what seeds
     * them -- makes `$is_actives->is_active` a null read in `Utility::sendEmailTemplate` --
     * measured at app/Models/Utility.php:2350.
     */
    public function test_the_host_path_null_reads_for_a_user_with_no_seeded_rows(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin);
        Utility::defaultEmail();

        [$merchant, $store] = $this->makeTenant();   // deliberately NOT seeded
        Cache::flush();
        (new EmailTemplate)->flushCache();

        $this->assertSame(0, (int) DB::table('user_email_templates')->where('user_id', $merchant->id)->count());

        // `Utility.php:2350` has no try/catch around it, so the ErrorException escapes the
        // function entirely and is caught by the CALLER -- e.g. PaymentService:2951, which turns
        // it into 'E-Mail has been not sent due to SMTP configuration'. Driven directly here so
        // the throw itself is the assertion.
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Attempt to read property "is_active" on null');

        Utility::sendEmailTemplate('Order Created', 'c@x.test', ['order_id' => 1],
            $merchant, $store, \Crypt::encrypt(1));
    }

    /**
     * THE SEVENTH COPY. Asserted so #69's "six modules" scope is corrected in code, not only in
     * prose, and so the slug finding is not overstated by one site.
     */
    public function test_a_seventh_copy_of_the_resolve_exists_in_preorder_without_the_slug_line(): void
    {
        $source = file_get_contents(base_path(self::SEVENTH_RESOLVE));

        $this->assertStringContainsString(
            "EmailTemplate::where('name', 'LIKE', \$emailTemplate)->first()", $source,
            self::SEVENTH_RESOLVE . ' no longer carries the unscoped resolve.');
        $this->assertStringContainsString('public static function sendEmailTemplate(', $source,
            'It is the sendEmailTemplate shape, like the host copy -- not sendOwnerEmailTemplate.');
        $this->assertStringNotContainsString(self::SLUG_LINE, $source,
            'PreOrder has acquired the slug derivation too, which would make the slug finding '
            . 'seven sites rather than six.');

        // Its template names are per-tenant, so the resolve defect applies to it.
        $this->assertStringContainsString(
            "EmailTemplate::where('name', \$eTemp)->where('created_by', Auth::user()->id)->count()",
            file_get_contents(base_path('packages/workdo/PreOrder/src/app/Http/Controllers/PreOrderController.php')),
            "PreOrder's own writer is no longer per-tenant.");
    }

    /* ---------------------------------------------------------------------------------------- *
     *  FIXTURES
     * ---------------------------------------------------------------------------------------- */

    private function enableLotteryModule(): void
    {
        AddOnManager::create([
            'module' => 'LotteryPrizes', 'name' => 'Lottery Prizes',
            'monthly_price' => 0, 'yearly_price' => 0, 'is_enable' => 1,
            'package_name' => 'lottery-prizes',
        ]);
        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['LotteryPrizes']);
    }

    /** @return array{0: User, 1: Store, 2: EmailTemplate} */
    private function lotteryMailFixture(): array
    {
        $this->enableLotteryModule();

        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin);
        Utility::defaultEmail();

        DB::table('email_templates')->insert([
            'name' => 'Lottery Win', 'from' => 'noreply@platform.test',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$merchant, $store] = $this->makeTenant();
        Utility::userDefaultData($merchant->id);

        Cache::flush();
        (new EmailTemplate)->flushCache();

        $template = EmailTemplate::where('name', 'LIKE', 'Lottery Win')->first();

        DB::table('user_email_templates')
            ->where('user_id', $merchant->id)->where('template_id', $template->id)
            ->update(['is_active' => 1]);

        Cache::flush();
        (new EmailTemplate)->flushCache();

        return [$merchant, $store, $template];
    }

    private function sendLotteryOwnerMail(User $merchant, Store $store): mixed
    {
        $fqcn = 'Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController';

        $this->assertTrue(class_exists($fqcn), 'The LotteryPrizes module did not load.');

        return $fqcn::sendOwnerEmailTemplate('Lottery Win', 'winner@x.test', 'Winner',
            ['ticket' => 'T1'], $merchant, $store);
    }
}
