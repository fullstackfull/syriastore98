<?php

namespace Tests\Feature\Lynomia;

use App\Models\AddOnManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\Feature\Security\SecurityTestCase;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * GAP-179 — THE LynomiaChat REPAIRS, ASSERTED ON THE RENDERED PAGE IN BOTH LANGUAGES.
 * ================================================================================================
 *
 * THE GAP THIS FILLS IS NOT A MISSING REPAIR, IT IS A MISSING KIND OF EVIDENCE. Both LynomiaChat
 * surfaces are pinned by SOURCE tests (`LynomiaIntegrationLegibilityTest`,
 * `LynomiaMerchantAutomationLegibilityTest`) and were verified once by a live browser harness that
 * nothing re-runs. This project has already recorded what that combination misses:
 *
 *     "A SOURCE-ONLY ASSERTION WAS INSUFFICIENT: the order-toolbar tests passed while the shared
 *      sentence rendered in ENGLISH on the Arabic page. Found only by adding a test that drives
 *      the real route and asserts both languages."
 *
 * and, on the other side, `setting/index.blade.php:283` — a conversion whose captures came back
 * BYTE-IDENTICAL because the element sits inside a `display:none` block. A source census cannot
 * tell a rendered repair from an unrendered one. So this file drives both real routes, as the real
 * principals, in `en` and `ar`, and counts the repaired shapes in the HTML that came back.
 *
 * ------------------------------------------------------------------------------------------------
 *  THE MODULE PREMISE, AND `CLAUDE.md` IS HALF WRONG ABOUT THIS MODULE IN A WAY THAT MATTERS
 * ------------------------------------------------------------------------------------------------
 *
 * `CLAUDE.md` says: "No WorkDo module loads in any test run unless the test inserts an
 * `add_on_managers` row ... a test that 'passes' against a module surface may never have reached
 * it." For LynomiaChat that is FALSE in its first half and TRUE in its second, and both halves
 * are asserted below rather than believed:
 *
 *   LOADS WITHOUT THE ROW.      `PackageServiceProvider::register()` lists LynomiaChat in
 *                               `$staticAddons` beside LandingPage, with the reasoning written at
 *                               the line: a first-party integration whose routes do not exist in
 *                               CI cannot have its gating asserted at all. So the provider,
 *                               routes and views are registered with `add_on_managers` EMPTY --
 *                               `test_premise_the_module_loads_without_an_add_on_managers_row`.
 *
 *   UNREACHABLE WITHOUT IT.     `ActivatedModule()` intersects the owner's activations with
 *                               `Module::getByStatus(1)`, which IS
 *                               `AddOnManager::where('is_enable', $status)->pluck('module')`
 *                               (`app/Classes/Module.php:117-120`). With the table empty that
 *                               intersection is empty, so a MERCHANT's entitlement resolves to
 *                               nothing. Measured on the dev fixture: all four combinations of
 *                               `/lynomia/automation/commerce` answered HTTP 200 having landed on
 *                               `/dashboard`.
 *
 * So "the module is active" is two different questions, and a premise that asserts only the first
 * would let a merchant-surface test pass against a redirect. Every merchant case below asserts the
 * landed route, because `EnsureStoreOwnsLink::refuse()` answers `redirect()->route('dashboard')`
 * and a status-code check records that as a pass.
 *
 * ------------------------------------------------------------------------------------------------
 *  WHAT IS DELIBERATELY NOT HERE
 * ------------------------------------------------------------------------------------------------
 *
 * CONTRAST IS NOT MEASURED HERE AND MUST NOT BE CLAIMED FROM HERE. A rendered-HTML assertion can
 * prove the repaired CLASS is on the element the browser will paint; only a browser with the
 * cascade can say what ratio results, which is why the measurement lives in
 * `tests/Support/bin/measure-lynomia-merchant-live.cjs` and
 * `measure-superadmin-premium-live.cjs`. Treating a class assertion as a contrast result is
 * exactly how an inert repair reads as a finished one.
 *
 * The `D-25` sidebar finding (`span.dash-mtext`, 2.58:1 dark, on every merchant screen and all 70
 * modules) is the owner's scope decision and is untouched; `LynomiaMerchantAutomationLegibilityTest`
 * asserts that exclusion as a negative.
 *
 * ------------------------------------------------------------------------------------------------
 *  WHY THIS EXTENDS `SecurityTestCase` AND NOT `TestCase`
 * ------------------------------------------------------------------------------------------------
 *
 * BECAUSE THE OPERATOR CANNOT REACH ANY `xss`-GUARDED ROUTE IN A TEST RUN, and the first version
 * of this file was redirected to `http://localhost/update` in both languages before it reached a
 * single assertion. `XSS::handle()` carries an UPDATER GATE that fires for `type == 'super admin'`
 * ONLY: it globs every migration filename on disk — `database/migrations` plus, for each of the ~70
 * packages, `src/Database/Migrations` and `src/database/migrations` — diffs that against the
 * `migrations` table and redirects to `LaravelUpdater::welcome` if anything is outstanding. Under
 * `RefreshDatabase` only the ACTIVE modules' migrations run while the glob reads all of them, so
 * the operator is redirected on every request. MEASURED here: 98 pending names.
 *
 * `SecurityTestCase::satisfyUpdaterGate()` already solves this, is already documented with the
 * reason, and already mirrors the middleware's own globbing so the two cannot drift. Re-deriving it
 * here would be a second copy of exactly the thing its comment warns about. Its docblock also
 * names the trap this file would otherwise have fallen into: a request redirected before it reaches
 * the code under test makes a green assertion prove nothing.
 */
class LynomiaRenderedLegibilityTest extends SecurityTestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const OPERATOR_URI = '/super-admin/lynomia-integration';
    private const MERCHANT_URI = '/lynomia/automation/commerce';

    /** The module's own view files, so a fallback elsewhere cannot satisfy these tests. */
    private const OPERATOR_VIEW = 'lynomia-chat::superadmin.integration';
    private const MERCHANT_VIEW = 'lynomia-chat::merchant.commerce-automation';

    /**
     * Enable the module AND DROP THE REGISTRY CACHE, which is not optional here.
     *
     * `App\Classes\Module::find()` is a `Cache::rememberForever($name, ...)`, so the first read
     * of a module's row is memoised under the module's own name for the rest of the process --
     * and under `CACHE_STORE=array` that is the rest of the TEST. `SecurityTestCase::setUp()`
     * calls `satisfyUpdaterGate()`, which calls `ModuleFacade::allModules()` and therefore
     * `find()` for all ~70 packages, BEFORE any test body runs. So the registry is read while
     * `add_on_managers` is still empty, LynomiaChat is cached as not-enabled, and
     * `bootLynomiaHarness()` inserting the row afterwards changes nothing that anything reads.
     *
     * MEASURED, and the symptom pointed somewhere else entirely: every merchant case answered
     * HTTP 302 to `/dashboard` with `isAbleTo('Manage Lynomia Chat') === true` and
     * `ActivatedModule()` containing `LynomiaChat` -- so permission and entitlement both looked
     * right while `PlanModuleCheck` refused, because `module_is_active()` asks a THIRD question
     * (`Module::find()->isEnabled()`) off the stale cache. The flashed `error` named it:
     * "Permission denied ", from `PlanModuleCheck:66`.
     *
     * This is CLAUDE.md's `Cachable`-under-`array` trap in a different costume, and the reason it
     * is handled here rather than inside `bootLynomiaHarness()` is blast radius: that trait is
     * used by ~40 test classes, and changing shared fixture behaviour is not this batch's scope.
     */
    private function enableModule(): void
    {
        $this->bootLynomiaHarness();
        \App\Facades\ModuleFacade::moduleCacheForget('LynomiaChat');
    }

    // =============================================================================================
    //  PREMISES
    // =============================================================================================

    public function test_premise_the_module_loads_without_an_add_on_managers_row(): void
    {
        /*
         * DELIBERATELY DOES NOT CALL bootLynomiaHarness(). The point is the state of the world
         * with the registry EMPTY: if LynomiaChat were a dynamically-loaded add-on, these routes
         * would not exist here and every other test in this file would be asserting against a 404.
         */
        $this->assertSame(0, AddOnManager::count(), 'this premise is about an EMPTY registry');

        $this->assertTrue(Route::has('lynomia.admin.index'), 'the operator route is not registered');
        $this->assertTrue(Route::has('lynomia.automation.commerce'), 'the merchant route is not registered');

        $this->assertTrue(
            View::exists(self::OPERATOR_VIEW),
            self::OPERATOR_VIEW . ' does not resolve, so the module\'s view namespace is not registered'
        );
        $this->assertTrue(View::exists(self::MERCHANT_VIEW), self::MERCHANT_VIEW . ' does not resolve');
    }

    public function test_premise_the_views_that_resolve_are_the_modules_own_files(): void
    {
        /*
         * A namespace that resolves is not proof that the MODULE's file is what renders: a
         * same-named view elsewhere on the finder's paths would satisfy View::exists() and every
         * markup assertion below would be measuring somebody else's file.
         */
        foreach ([self::OPERATOR_VIEW, self::MERCHANT_VIEW] as $view) {
            $raw = View::getFinder()->find($view);

            /*
             * `realpath()` BECAUSE THE FINDER DOES NOT CANONICALISE. The module registers its
             * view path relative to its provider, so the finder really answers
             * `.../src/app/Providers/../../resources/views/superadmin/integration.blade.php`. A
             * substring assertion against the tidy path fails on the untidy one -- which is a
             * test defect, not a missing view, and it cost a red run to see.
             */
            $path = realpath($raw) ?: $raw;

            $this->assertStringContainsString(
                'packages/workdo/LynomiaChat/src/resources/views/',
                $path,
                $view . ' resolves to ' . $raw . ', which is not the module\'s own file.'
            );
        }
    }

    public function test_premise_a_merchant_needs_the_add_on_managers_row_to_resolve_the_module(): void
    {
        /*
         * THE OTHER HALF, and the one that makes a merchant-surface test honest. Asserted in BOTH
         * directions on the same fixture, because an entitlement that is merely hoped for is how
         * a redirect gets photographed.
         */
        $owner = $this->merchant();
        $this->storeFor($owner);

        /*
         * THE OWNER'S OWN ACTIVATION IS HELD CONSTANT so the only thing varying is the registry
         * row. `ActivatedModule()` INTERSECTS the two, and a test that changed both at once could
         * not say which side refused -- which is the whole point of this premise.
         */
        \App\Models\userActiveModule::updateOrCreate(
            ['user_id' => $owner->id, 'module' => 'LynomiaChat'],
            []
        );

        AddOnManager::query()->delete();
        $owner->flushCache();
        $this->assertNotContains('LynomiaChat', ActivatedModule($owner->id),
            'with an empty add_on_managers the module must NOT resolve for a merchant, even with '
            . 'the owner\'s own activation row present: Module::getByStatus(1) IS that table.');

        $this->enableModule();
        $owner->flushCache();
        $this->assertContains('LynomiaChat', ActivatedModule($owner->id),
            'with the registry row present the module must resolve for a merchant');
    }

    // =============================================================================================
    //  THE OPERATOR PAGE, RENDERED
    // =============================================================================================

    /** @return array<string, array{0: string}> */
    public static function languages(): array
    {
        return ['english' => ['en'], 'arabic' => ['ar']];
    }

    /**
     * @dataProvider languages
     */
    public function test_the_operator_page_renders_every_tab_label_wrapped_and_no_host_warning_chip(string $lang): void
    {
        $this->enableModule();
        Http::fake();

        $operator = $this->operator();
        $operator->language = $lang;
        $operator->save();

        $owner = $this->merchant();
        $store = $this->storeFor($owner);

        LynomiaStoreLink::create([
            'store_id' => $store->id,
            'owner_user_id' => $owner->id,
            'lynomia_account_id' => 'acct-1',
            'lynomia_account_name' => 'Workspace One',
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => 'provisioned',
        ]);

        $response = $this->actingAs($operator)
            ->withSession(['LANGUAGE' => $lang])
            ->get(self::OPERATOR_URI);

        $response->assertOk();
        $response->assertViewIs(self::OPERATOR_VIEW);
        $html = $response->getContent();

        /*
         * FIVE TAB RAIL ANCHORS AND FIVE WRAPPED LABELS, as an exact count in the RENDERED output.
         * `data-bs-toggle="tab"` moves `.active` client-side, so a repair applied to the
         * initially-active tab alone is correct until the first click -- and a presence check
         * cannot tell five wrapped labels from one.
         */
        $this->assertSame(
            5,
            preg_match_all('/class="nav-link[^"]*"[^>]*>\s*<span class="ss-text-strong">/s', $html),
            'the rendered operator page does not carry five tab labels wrapped in the design '
            . 'system span. The host declares .nav-link { color: ... !important } on a (0,1,0) '
            . 'selector, so a tone class on the anchor would be inert.'
        );

        /*
         * `bg-warning` is REMOVED rather than joined, because it carries `!important`: a design
         * system class beside it would change nothing on screen while reading as a repair.
         * Measured at 1.68:1 light before the repair.
         */
        $this->assertSame(
            0,
            substr_count($html, 'bg-warning'),
            'the host warning utility is back in the rendered operator page. It measured 1.68:1 '
            . 'in light and carries !important, so it cannot be out-specified.'
        );

        $this->assertStringContainsString(
            'ss-badge ss-badge--warning',
            $html,
            'the pending-count chip no longer renders the design system warning badge. '
            . '(pendingCount is 1 in this fixture, so the chip must be on the page.)'
        );
    }

    /**
     * @dataProvider languages
     */
    public function test_the_operator_page_renders_its_tab_labels_in_the_requested_language(string $lang): void
    {
        /*
         * THE HALF A SOURCE TEST CANNOT SEE. `__('Agent Seats')` with no entry renders the KEY --
         * perfect English on an Arabic RTL page, with no error anywhere. Four of these five labels
         * have NO entry in the application's own `resources/lang/ar.json`; they resolve from the
         * MODULE's dictionary (`packages/workdo/LynomiaChat/src/resources/lang/ar.json`), which
         * only reaches the translator because the module's provider is loaded. So this assertion
         * is also a second, independent check on the premise above.
         */
        $this->enableModule();
        Http::fake();

        $operator = $this->operator();
        $operator->language = $lang;
        $operator->save();

        $html = $this->actingAs($operator)
            ->withSession(['LANGUAGE' => $lang])
            ->get(self::OPERATOR_URI)
            ->getContent();

        $labels = [
            'Overview' => 'نظرة عامة',
            'Agent Seats' => 'مقاعد الوكلاء',
            'Automation' => 'الأتمتة',
            'Merchant notices' => 'إشعارات التجار',
            'Merchant Accounts' => 'حسابات التجار',
        ];

        foreach ($labels as $english => $arabic) {
            $expected = $lang === 'ar' ? $arabic : $english;

            $this->assertStringContainsString(
                '<span class="ss-text-strong">' . $expected . '</span>',
                $html,
                'the ' . $lang . ' operator page does not render the tab label "' . $expected . '".'
                . ($lang === 'ar' ? ' An untranslated label renders the English key instead, which '
                    . 'is invisible to every English reader and to every test that asserts on '
                    . 'English output.' : '')
            );
        }
    }

    // =============================================================================================
    //  THE MERCHANT PAGE, RENDERED
    // =============================================================================================

    /**
     * @dataProvider languages
     */
    public function test_the_merchant_automation_page_renders_wrapped_tabs_and_design_system_buttons(string $lang): void
    {
        $this->enableModule();
        Http::fake();

        $owner = $this->merchant(['language' => $lang]);
        $store = $this->storeFor($owner);
        $this->entitle($owner);

        LynomiaStoreLink::create([
            'store_id' => $store->id,
            'owner_user_id' => $owner->id,
            'lynomia_account_id' => 'acct-merchant',
            'lynomia_account_name' => 'Workspace One',
            'state' => LinkState::ACTIVE,
            'link_mode' => 'provisioned',
        ]);

        $owner->flushCache();

        /*
         * A PER-TEST PREMISE, because the failure it guards is a REDIRECT and not an error.
         * `PlanModuleCheck` reads `module_is_active()`, which asks a different question from
         * `ActivatedModule()` -- `Module::find()->isEnabled()`, off a `rememberForever` cache.
         * Without this line a stale registry cache sends the request to `/dashboard` with HTTP
         * 302 and the assertions below would be describing the wrong page.
         */
        $M = \App\Facades\ModuleFacade::class;
        fwrite(STDERR, "\nPROBE has=".var_export($M::has('LynomiaChat'),true)
            ." isEnabled(arg)=".var_export($M::isEnabled('LynomiaChat'),true)
            ." rows=".\App\Models\AddOnManager::count()
            ." enabledList=".implode('|', $M::getByStatus(1))
            ." find->isEnabled=".var_export($M::find('LynomiaChat')->isEnabled(),true)
            ." authType=".(string) auth()->user()?->type."\n");
        /*
         * THE OWNER ID IS PASSED EXPLICITLY, and the first version of this premise got it wrong in
         * a way worth recording: `module_is_active($name)` resolves its principal from
         * `Auth::check()` first and falls back to `$user_id` only when nobody is signed in. Called
         * from a test BODY -- outside the request -- nobody is, so the bare call returned false
         * while every part of it measured true (`has`, `isEnabled`, `getByStatus`, the row count).
         * A premise that fails for its own reason is worse than no premise: it blames the product
         * for the harness.
         */
        $this->assertTrue(
            module_is_active('LynomiaChat', $owner->id),
            'module_is_active(LynomiaChat) is false for this owner, so PlanModuleCheck will '
            . 'redirect the request to /dashboard before it reaches the view.'
        );

        $response = $this->actingAs($owner)
            ->withSession(['LANGUAGE' => $lang])
            ->get(self::MERCHANT_URI);

        /*
         * THE LANDED ROUTE, NOT ONLY THE STATUS. Every gate on this route answers
         * `redirect()->route('dashboard')`, which is HTTP 200 with a fully rendered page -- the
         * dashboard. assertViewIs is what makes "200" mean "this page".
         */
        fwrite(STDERR, "\nMERCHANT STATUS: ".$response->getStatusCode()." LOCATION: ".(string) $response->headers->get('Location')." ABLE: ".var_export($owner->fresh()->isAbleTo('Manage Lynomia Chat'), true)." MODULES: ".implode('|', ActivatedModule($owner->id))." ERROR: ".var_export(session('error'), true)." STORE: ".var_export(getCurrentStore(), true)."\n");
        $response->assertOk();
        $response->assertViewIs(self::MERCHANT_VIEW);
        $html = $response->getContent();

        $this->assertSame(
            2,
            preg_match_all('/class="nav-link[^"]*"[^>]*>\s*<span class="ss-text-strong">/s', $html),
            'the rendered merchant automation page does not carry BOTH tab labels wrapped. The '
            . 'second tab is behind @if ($canManageConsent) and becomes the .active one -- and so '
            . 'the 2.41:1 one -- as soon as the merchant clicks it.'
        );

        preg_match_all('/<[^>]*class="[^"]*\bbtn-primary\b[^"]*"[^>]*>/', $html, $bp);
        fwrite(STDERR, "\nBTN-PRIMARY TAGS: ".implode(' || ', $bp[0])."\n");
        /*
         * PER-ELEMENT, NOT A COUNT OVER THE DOCUMENT -- and the first version of this assertion
         * was the census trap in its clearest form. `assertSame(0, preg_match_all('btn-primary'))`
         * over the whole page FAILED at 1 after a correct repair, because the SHARED ADMIN CHROME
         * builds `<a class="btn btn-sm btn-primary add_attribute btn-badge" ...>` inside a
         * JavaScript template string on every admin page. An assertion over a whole document
         * answers "does this string exist anywhere", which is not the question.
         *
         * THAT FINDING IS REAL AND IS NOT MINE: it is the same 2.58:1 host fill, in shared chrome
         * (`D-25` territory), and the live harness cannot see it because it only exists inside a
         * JS string until something renders it. Reported, not repaired.
         *
         * So each of the module's own three buttons is checked by its own id. An exact per-element
         * assertion is also what slice 2 learned to use after three mutations survived by matching
         * the repaired shape somewhere in a file while a reverted element sat beside it.
         */
        foreach (['lync-new', 'lync-optin-save', 'lync-rule-save'] as $id) {
            $this->assertSame(
                1,
                preg_match('/<[^>]*id="' . $id . '"[^>]*>/', $html, $tag),
                'the rendered merchant page has no element with id="' . $id . '".'
            );

            $this->assertStringContainsString(
                'ss-btn--primary',
                $tag[0],
                $id . ' does not carry the design system primary class: ' . $tag[0]
            );

            $this->assertStringNotContainsString(
                'btn-primary',
                $tag[0],
                $id . ' still carries the host primary fill, which measured 2.58:1 '
                . '(rgb(4,34,42) on rgb(51,93,168)) in the dark admin theme: ' . $tag[0]
            );
        }
    }

    /**
     * @dataProvider languages
     */
    public function test_the_merchant_automation_tabs_render_in_the_requested_language(string $lang): void
    {
        $this->enableModule();
        Http::fake();

        $owner = $this->merchant(['language' => $lang]);
        $store = $this->storeFor($owner);
        $this->entitle($owner);

        LynomiaStoreLink::create([
            'store_id' => $store->id,
            'owner_user_id' => $owner->id,
            'lynomia_account_id' => 'acct-merchant',
            'lynomia_account_name' => 'Workspace One',
            'state' => LinkState::ACTIVE,
            'link_mode' => 'provisioned',
        ]);

        $owner->flushCache();

        $this->assertTrue(module_is_active('LynomiaChat', $owner->id), 'see the premise note above');

        $html = $this->actingAs($owner)
            ->withSession(['LANGUAGE' => $lang])
            ->get(self::MERCHANT_URI)
            ->getContent();

        foreach (['Rules' => 'القواعد', 'Consent' => 'الموافقات'] as $english => $arabic) {
            $expected = $lang === 'ar' ? $arabic : $english;

            $this->assertStringContainsString(
                '<span class="ss-text-strong">' . $expected . '</span>',
                $html,
                'the ' . $lang . ' merchant automation page does not render the tab label "'
                . $expected . '" inside the design system span.'
            );
        }
    }
}
