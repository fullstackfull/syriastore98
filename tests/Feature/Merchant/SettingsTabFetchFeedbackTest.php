<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5 settings-no-error-or-loading-state -- the settings rail failed silently, both ways.
 *
 * WHAT WAS MEASURED BEFORE THE REPAIR. `getSettingForm()` in
 * resources/views/setting/index.blade.php was a `$.ajax` with `success` ONLY -- no `error`, no
 * `beforeSend`, no `complete` -- writing `result.data.content` unconditionally. The endpoint
 * (SettingController@settingForm, routes/web.php:562) has TWO documented failure answers and
 * they travel on DIFFERENT transports, which is why one probe cannot test both:
 *
 *   (a) an explicit 403 -- `response()->json([... 'data' => ['content' => null]], 403)` at the
 *       StoreSettingsAccess::permits() refusal -- routes to jQuery's `error` callback, and
 *       there was no `error` callback registered, anywhere: public/js/custom.js:1-5 is an
 *       $.ajaxSetup that sets the X-CSRF-TOKEN header only, and no first-party JS registers
 *       $(document).ajaxError. So NOTHING AT ALL happened: the merchant's selected tab moved,
 *       the panel kept the PREVIOUS tab's form, and they could then edit and save fields
 *       believing they were on the tab they clicked.
 *
 *   (b) the `catch (\Exception $e)` return, which passes NO status argument -- so it is
 *       HTTP 200 with `data.content === null`. That one reaches `success`, and
 *       `.html(null)` blanks the panel. `error:` alone would never have caught it; the guard
 *       has to be in the success callback, which is why the repair has both.
 *
 * And there was no loading state: `#loader` (layouts/app.blade.php) is display:none and
 * getSettingForm never showed it.
 *
 * WHY THIS IS A VIEW-ONLY REPAIR. The 403 is a shipped contract (the Flutter admin app forces
 * a logout on 401, and WebSettingsSecretsTest + ChatgptKeyDisclosureTest pin the 403), and the
 * catch's HTTP 200 is pinned by SettingsTabUnresolvedStoreTest. Giving the catch a 500 so that
 * `error:` would see it is the over-reaching "fix" this file exists to forbid. Nothing in
 * app/ or public/css changes.
 *
 * WHY aria-live IS ABSENT ON PURPOSE. #pills-tabContent is the container whose ENTIRE contents
 * are replaced on every tab switch; brand_setting is 25 fields and storage_setting 22, so a
 * polite live region there would read the whole settings form aloud on each of 26 tabs. The
 * failure is already announced assertively, by show_toastr -> ss_announce_transient ->
 * #ss-live-region-assertive (public/js/custom.js), which this repair deliberately reuses rather
 * than building new ARIA plumbing. No existing test pinned that, so one is added below.
 */
class SettingsTabFetchFeedbackTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const VIEW = 'resources/views/setting/index.blade.php';

    private User $merchant;

    private User $staff;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = User::find($this->merchant->id);

        $this->staff = $this->makeStaff($this->merchant, ['current_store' => $this->store->id]);
        $this->staff = User::find($this->staff->id);
    }

    /** Blade comments stripped before any count or match: this repair's own comments describe
     *  the very handlers being counted, and counting them would inflate every figure. The same
     *  trap is recorded in ValidationFeedbackVisibilityTest::source(). */
    private function source(): string
    {
        return (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(base_path(self::VIEW))
        );
    }

    /** Just the body of getSettingForm, so a handler belonging to one of the EIGHT other
     *  $.ajax blocks in this file cannot be mistaken for this one's. */
    private function getSettingFormBody(): string
    {
        $src = $this->source();

        $start = strpos($src, 'function getSettingForm(');
        $this->assertNotFalse($start, 'getSettingForm() has been renamed or removed.');

        // Up to the next top-level `function ` declaration after it.
        $next = strpos($src, "\n function ", $start + 10);
        if ($next === false) {
            $next = strpos($src, "\nfunction ", $start + 10);
        }

        return substr($src, $start, $next === false ? 2000 : $next - $start);
    }

    // ---------------------------------------------------------------- the repair itself

    public function test_the_settings_tab_fetch_registers_an_error_callback(): void
    {
        $this->assertStringContainsString(
            'error:',
            $this->getSettingFormBody(),
            'getSettingForm() must register an `error` callback. Without one the endpoint\'s '
            . 'explicit 403 produces NO DOM CHANGE AT ALL -- the tab rail moves to the new tab '
            . 'while the panel still holds the previous tab\'s form, so a merchant can edit and '
            . 'save fields believing they are on a tab they were refused.'
        );
    }

    public function test_the_settings_tab_fetch_guards_the_success_callback_against_null_content(): void
    {
        $body = $this->getSettingFormBody();

        $this->assertStringContainsString(
            'result.data.content == null',
            $body,
            'The success callback must guard on `== null`, not on falsiness. The catch branch '
            . 'of SettingController@settingForm passes NO status argument, so it arrives as '
            . 'HTTP 200 with data.content === null and reaches `success`, not `error`. '
            . '`== null` rather than `!content` so a legitimately empty panel string would '
            . 'still render -- the inverse defect of blanking a working tab.'
        );

        $this->assertStringContainsString(
            'is_success === false',
            $body,
            'The success callback must also honour the is_success flag the endpoint sets.'
        );
    }

    public function test_the_settings_tab_fetch_shows_and_clears_a_loading_state(): void
    {
        $body = $this->getSettingFormBody();

        $this->assertStringContainsString(
            'beforeSend:',
            $body,
            'There was no loading state on this path at all: #loader is display:none and '
            . 'getSettingForm never showed it.'
        );

        $this->assertStringContainsString(
            'ss-skeleton',
            $body,
            'The loading state must reuse the design system token .ss-skeleton / '
            . '.ss-skeleton--line (public/css/store-syria-ds.css), which already reverses under '
            . '[dir="rtl"] and already has a dark-mode background -- which is precisely why '
            . 'NEITHER public/css/custom.css NOR public/css/rtl-custom.css needs to change. An '
            . 'invented .settings-loading class in custom.css would reach only LTR merchants.'
        );

        $this->assertStringContainsString(
            'complete:',
            $body,
            'aria-busy must be cleared in `complete`, which always fires -- otherwise the '
            . 'non-AJAX delivery_syria_setting branch or a failed fetch leaves the panel stuck '
            . 'at aria-busy="true".'
        );
    }

    public function test_the_panel_declares_aria_busy_in_its_markup(): void
    {
        $src = $this->source();

        $start = strpos($src, 'id="pills-tabContent"');
        $this->assertNotFalse($start, 'The settings panel id has changed.');
        $tag = substr($src, (int) strrpos(substr($src, 0, $start), '<div'), 260);

        $this->assertStringContainsString(
            'aria-busy="false"',
            $tag,
            'The panel must declare aria-busy="false" at rest, so the true/false toggle in '
            . 'beforeSend/complete is a state change rather than an attribute appearing from '
            . 'nowhere.'
        );
    }

    /**
     * THE NEGATIVE CONTROL THE PROPOSAL OMITTED, and the only guard against the next pass
     * re-adding aria-live from the reports/index.blade.php precedent. I searched: nothing in
     * tests/ pins the set of live regions in resources/views, so the regression would ship
     * green.
     */
    public function test_the_settings_panel_carries_no_aria_live_region(): void
    {
        $src = $this->source();

        $start = strpos($src, 'id="pills-tabContent"');
        $this->assertNotFalse($start);
        $tag = substr($src, (int) strrpos(substr($src, 0, $start), '<div'), 260);

        $this->assertStringNotContainsString(
            'aria-live',
            $tag,
            'DO NOT add aria-live to #pills-tabContent. Its ENTIRE contents are replaced on '
            . 'every tab switch, and the panes are 22-25 form fields, so a polite live region '
            . 'would read the whole settings form aloud on each of 26 tabs -- a regression on '
            . 'the COMMON path, bought for nothing. The failure is already announced '
            . 'assertively by show_toastr -> ss_announce_transient -> '
            . '#ss-live-region-assertive (public/js/custom.js). The panel also already carries '
            . 'role="tabpanel" + aria-labelledby, which is the standards mechanism for '
            . 'conveying a tab change. reports/index.blade.php is the outlier precedent: a '
            . 'read-only figures region with no tabpanel role and no form controls.'
        );
    }

    /**
     * The repair depends on show_toastr routing through the assertive live region. If an
     * implementer swaps it for a bare DOM write the failure goes silent to assistive tech
     * again, and TransientFeedbackContractTest would not catch it because it reads custom.js
     * and not this view.
     */
    public function test_the_failure_is_announced_through_the_shared_transient_channel(): void
    {
        $src = $this->source();

        // Scoped to settingFormFailed, NOT the whole file: show_toastr appears elsewhere in
        // this view, so a whole-file match would have passed with the repair reverted -- the
        // bite test proved exactly that, and this is the tightened assertion.
        $at = strpos($src, 'function settingFormFailed(');
        $this->assertNotFalse(
            $at,
            'settingFormFailed() is the single failure renderer for this fetch; without it the '
            . 'failure has nowhere to go.'
        );
        $body = substr($src, $at, 600);

        $this->assertStringContainsString(
            'show_toastr(',
            $body,
            'The failure must be announced through show_toastr, which public/js/custom.js '
            . 'routes to ss_announce_transient and the assertive live region. A bare DOM write '
            . 'would be visible and silent.'
        );
    }

    // ---------------------------------------------------------------- negative controls

    /**
     * THE SHIPPED AJAX CONTRACT MUST NOT MOVE -- (a). Driven as STAFF WITHOUT the permission,
     * because StoreSettingsAccess::permits() limb 2 (creatorId() === id) admits every owner, so
     * a merchant token can never reach this branch at all and a test driven only through one
     * would prove nothing.
     */
    public function test_staff_without_the_permission_still_get_a_403_and_null_content(): void
    {
        foreach (['payment_setting', 'email_setting', 'system_setting'] as $tab) {
            $response = $this->actingAs($this->staff)->post('/setting-form', ['tab_type' => $tab]);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                "{$tab}: the endpoint's refusal status changed. The repair is view-only; a "
                . 'controller change here would break WebSettingsSecretsTest and '
                . 'ChatgptKeyDisclosureTest, and 401 would force a logout in the shipped '
                . 'Flutter admin app.'
            );

            $json = json_decode($response->getContent(), true);
            $this->assertFalse((bool) ($json['is_success'] ?? true));
            // array_key_exists, not ??: the key IS present and its value IS null, which is
            // exactly the shape under test, and `??` would read that as "missing".
            $this->assertArrayHasKey('content', $json['data']);
            $this->assertNull($json['data']['content']);
        }
    }

    /** THE HAPPY PATH MUST STILL RENDER -- the inverse defect. A guard written as
     *  `if (!result.data.content)` would blank a working tab. */
    public function test_a_permitted_principal_still_gets_a_real_panel(): void
    {
        $this->grantPermission($this->staff, 'Manage Setting');

        $response = $this->actingAs(User::find($this->staff->id))
            ->post('/setting-form', ['tab_type' => 'payment_setting']);

        $response->assertOk();

        $json = json_decode($response->getContent(), true);
        $this->assertTrue(
            (bool) ($json['is_success'] ?? false),
            'Premise failed: a permitted principal was not served the tab, so the refusal test '
            . 'above is not evidence that the permission is what refuses.'
        );
        $this->assertNotNull($json['data']['content'] ?? null);
        $this->assertStringContainsString('name="stripe_secret_key"', (string) $json['data']['content']);
    }

    /**
     * NO TENANT OR PERMISSION PREDICATE MAY MOVE. Asserted structurally rather than by byte
     * comparison of a line number, because line numbers move; what must not move is the
     * operator-first ordering, since isAbleTo() alone REFUSES the platform operator
     * (Laratrust, permissions_as_gates => false, no super-admin bypass).
     */
    public function test_the_endpoints_permission_predicate_is_untouched(): void
    {
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/SettingController.php'));

        $this->assertStringContainsString(
            "return response()->json(['is_success' => false, 'msg' => __('Permission denied.'), 'data' => ['content' => null]], 403);",
            $controller,
            'The settingForm refusal must stay byte-identical: same shape, same explicit 403.'
        );

        $access = (string) file_get_contents(base_path('app/Services/StoreSettingsAccess.php'));

        $operatorAt = strpos($access, 'super admin');
        $isAbleToAt = strpos($access, 'isAbleTo');

        $this->assertNotFalse($operatorAt, 'The operator string comparison has gone from StoreSettingsAccess.');
        $this->assertNotFalse($isAbleToAt, 'The isAbleTo limb has gone from StoreSettingsAccess.');
        $this->assertLessThan(
            $isAbleToAt,
            $operatorAt,
            'THE OPERATOR MUST BE TESTED FIRST. isAbleTo() has no super-admin bypass in this '
            . 'codebase, so an ordering flip locks the platform operator out of every settings '
            . 'tab.'
        );
    }

    /**
     * NO TRANSLATION KEY MAY BE INTRODUCED WITHOUT ITS ARABIC. Re-asserted rather than
     * assumed: the ratchet's own docblock records four English-only strings shipping under
     * this rule.
     */
    public function test_every_literal_the_repair_renders_exists_in_both_languages(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);
        $en = json_decode((string) file_get_contents(base_path('resources/lang/en.json')), true);

        foreach ([
            'Error',
            'Your session has expired. Please reload the page.',
            'Something went wrong. Please try again.',
        ] as $literal) {
            $this->assertArrayHasKey($literal, $ar, "Arabic is missing: {$literal}");
            $this->assertArrayHasKey($literal, $en, "English is missing: {$literal}");
            $this->assertNotSame('', trim((string) $ar[$literal]));
        }
    }

    /**
     * NEITHER STYLESHEET IS TOUCHED BY THIS REPAIR, and that is an assertion, not a hope: the
     * two files are mirrored duplicates serving LTR and Arabic respectively, so a one-sided
     * edit reaches half the merchants. The loading state reuses .ss-skeleton from
     * store-syria-ds.css precisely so that neither needs an edit.
     */
    public function test_the_repair_adds_no_selector_to_either_mirrored_stylesheet(): void
    {
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $css = (string) file_get_contents(base_path($sheet));

            $this->assertStringNotContainsString(
                'settings-loading',
                $css,
                "{$sheet}: the settings loading state must not invent its own class. "
                . 'custom.css serves LTR and rtl-custom.css serves Arabic, so a class added to '
                . 'one reaches only half the merchants.'
            );
            $this->assertStringNotContainsString('pills-tabContent', $css, $sheet);
        }
    }

    /**
     * THE NON-AJAX TAB MUST STILL WORK. delivery_syria_setting returns BEFORE getSettingForm is
     * ever called, so it must leave no skeleton painted and no stuck aria-busy -- which is
     * guaranteed only because the early return happens before beforeSend, and because
     * `complete` always fires on the paths that do reach the fetch.
     */
    public function test_the_non_ajax_delivery_tab_still_returns_before_the_fetch(): void
    {
        $src = $this->source();

        $branch = substr(
            $src,
            (int) strpos($src, "newTab === 'delivery_syria_setting'"),
            400
        );

        $this->assertStringContainsString('#delivery-syria-content', $branch);
        $this->assertStringContainsString('return;', $branch);
        $this->assertStringNotContainsString(
            'getSettingForm(',
            $branch,
            'The delivery_syria_setting branch must still return before any AJAX call, or it '
            . 'would POST with an undefined url and paint a skeleton over a panel it is '
            . 'hiding.'
        );
    }

    /** The tab rail state moves BEFORE the fetch and must not be "helpfully" rolled back on
     *  failure: that would fight Bootstrap's own data-bs-toggle="pill" handling and
     *  desynchronise the rail from the non-AJAX branch. */
    public function test_the_tab_rail_state_still_moves_before_the_fetch(): void
    {
        $src = $this->source();

        $this->assertStringContainsString("attr('aria-selected', 'true')", $src);
        $this->assertStringContainsString("attr('aria-labelledby', this.id)", $src);
        $this->assertLessThan(
            (int) strpos($src, 'function getSettingForm('),
            (int) strpos($src, "attr('aria-selected', 'true')"),
            'The rail state must still move before the fetch.'
        );
    }
}
