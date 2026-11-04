<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5 area-wide-no-withinput-witherrors -- CLASS A ONLY, the merchant's own profile screen.
 *
 * WHAT WAS MEASURED BEFORE THE REPAIR. resources/views/layouts/app.blade.php contains the
 * word "errors" ZERO times (grep -c = 0), so the layout renders no error bag anywhere; the
 * only channel is the 4-second `show_toastr` toast fed by `->with('error', $messages->first())`.
 * UserController@editprofile and @password_update both returned exactly that and nothing
 * else, so the six `@error` blocks already written into resources/views/users/profile.blade.php
 * (name, email, mobile, old_password, new_password, new_password_confirmation) were DEAD
 * MARKUP: no bag ever reached the view, and the merchant's typed input was discarded.
 *
 * WHY THIS IS CLASS A AND THE REST OF THE AREA IS NOT. /profile is a full page served by a
 * GET, so `back()` lands on the very view that renders the form and Laravel's one-request
 * flash survives to it. The rest of the area (users/create|edit, roles/create|edit,
 * store/edit, all 29 setting/* panels) is fetched by a LATER $.ajax GET/POST
 * (public/js/custom.js:35-64, resources/views/setting/index.blade.php getSettingForm), so the
 * list/shell render consumes the flash before the partial is ever requested -- the same
 * mechanism this project already measured and recorded in
 * tests/Feature/Merchant/ModalFormRefusalFeedbackTest.php §3. Class B is deliberately NOT
 * repaired here.
 *
 * THE COMPANION IS NOT COSMETIC -- it is why this file has four tests and not two.
 * The form package is konekt/html (composer.json:28), and
 * vendor/konekt/html/src/FormBuilder.php:1299-1311 returns NULL from getValueAttribute()
 * whenever ConvertEmptyStringsToNull is in the stack AND old() is null AND the explicit
 * $value is null AND the shared errors bag is non-empty. profile.blade.php carries TWO forms
 * on one continuously scrolling page sharing ONE default bag, and the Change Password form
 * posts no name/email/mobile. So the moment password_update flashes an error bag, the
 * Personal Info card's `Form::text('name', null)` would have rendered BLANK -- and since
 * editprofile only requires name and email (mobile has no rule), a merchant retyping from the
 * blanked card would post mobile='' and UserController.php:443-449 would save it and call
 * MerchantContactVerification::forgetIfChanged, destroying the number AND its verification.
 * Adding withErrors() without the explicit model values would have SHIPPED NEW DATA LOSS on
 * the one screen the plan called "provably complete". That is what
 * test_a_failed_password_change_does_not_blank_the_personal_info_card pins.
 */
class ProfileValidationFeedbackTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * NOT type 'super admin': app/Http/Middleware/XSS.php redirects a super admin to the
     * updater whenever an on-disk migration filename is missing from the migrations table,
     * which would make every assertion below pass without the controller ever running.
     * Same choice, same reason, as tests/Feature/Platform/ProductProfileSavePathTest.php:47.
     *
     * A STORE IS REQUIRED, not optional scaffolding: resources/views/partision/sidebar.blade.php
     * reads a property off the resolved store, so a merchant with no current_store cannot
     * render ANY admin page and every assertion against the body would error rather than fail.
     */
    private function merchant(): User
    {
        $merchant = $this->makeMerchant([
            'name' => 'Measured Merchant',
            'email' => 'measured@example.test',
            'mobile' => '96599887766',
        ]);

        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        return User::find($merchant->id);
    }

    /**
     * (b) from the amended repair. A refused Personal Info save must reach the page as a
     * real error bag, which the shared error-summary component then renders, and the
     * merchant's typed email must survive.
     */
    public function test_a_refused_personal_info_save_renders_the_error_summary_and_keeps_the_typed_input(): void
    {
        $user = $this->merchant();

        $post = $this
            ->actingAs($user)
            ->from(route('profile'))
            ->post(route('update.account'), [
                'name' => '',
                'email' => 'typed-by-the-merchant@example.test',
                'mobile' => '96511223344',
            ]);

        $post->assertRedirect(route('profile'));

        // The toast MUST be kept, not replaced: five shipped security tests read this exact
        // flash on paths this change touches (WebSettingsSecretsTest.php:269/:287,
        // HostClaimsTest.php:184, PaymentGatewaySecretPersistenceTest.php:197,
        // PaymentGatewaySecretRemovalTest.php:230, TenantSwitchTest.php:107).
        $post->assertSessionHas('error');

        // The repair itself: an error bag and old input now exist.
        $post->assertSessionHasErrors('name');

        $body = $this->actingAs($user)->get(route('profile'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'ss-errorsummary',
            $body,
            'A refused profile save must render the shared validation summary on the page '
            . 'the redirect actually lands on. layouts/app.blade.php renders $errors nowhere '
            . '(0 occurrences of "errors"), so without the summary the only feedback is a '
            . '4-second toast and the six @error blocks in profile.blade.php stay dead.'
        );

        $this->assertStringContainsString(
            'value="typed-by-the-merchant@example.test"',
            $body,
            'old() must repopulate the email the merchant typed; konekt/html getValueAttribute '
            . 'reads old() before the model value.'
        );
    }

    /**
     * (a) from the amended repair -- THE REGRESSION GUARD FOR THE COMPANION. This fails if
     * withErrors() is added to password_update without giving the three Personal Info fields
     * explicit model values, because of the konekt blanking branch documented in the class
     * docblock.
     */
    public function test_a_failed_password_change_does_not_blank_the_personal_info_card(): void
    {
        $user = $this->merchant();

        $put = $this
            ->actingAs($user)
            ->from(route('profile'))
            ->put(route('user.password.update'), [
                'type' => 'admin',
                'old_password' => 'whatever-they-typed',
                'new_password' => 'a-new-password',
                'new_password_confirmation' => 'mistyped-confirmation',
            ]);

        $put->assertRedirect(route('profile'));
        $put->assertSessionHas('error');
        $put->assertSessionHasErrors('new_password');

        $body = $this->actingAs($user)->get(route('profile'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'value="Measured Merchant"',
            $body,
            'The Personal Info name field must still carry the stored value after a FAILED '
            . 'PASSWORD CHANGE. konekt/html FormBuilder.php:1299-1311 blanks a '
            . "Form::text(name, null) whenever the shared errors bag is non-empty, and the two "
            . 'cards share one default bag -- so an explicit model value is mandatory, not tidy.'
        );

        $this->assertStringContainsString(
            'value="96599887766"',
            $body,
            'The mobile field must still carry the stored number. If it blanks, a merchant '
            . 'who retypes only name and email (the only two fields editprofile requires) '
            . 'posts mobile="" and UserController.php:443-449 saves it and calls '
            . 'MerchantContactVerification::forgetIfChanged -- destroying the number and its '
            . 'verification. That is new data loss, not a repair.'
        );

        // The number itself must be untouched by the refusal.
        $this->assertSame('96599887766', (string) $user->refresh()->mobile);
    }

    /**
     * (c) from the amended repair, and the NEGATIVE CONTROL that keeps the summary inside the
     * component's own `@if ($errors->any())` guard
     * (resources/views/components/ss/error-summary.blade.php:12). An unconditional wrapper or
     * a hand-rolled copy fails this, exactly as it fails
     * tests/Feature/Merchant/ValidationFeedbackVisibilityTest.php:323 on order.create.
     */
    public function test_an_unsubmitted_profile_page_renders_no_error_summary(): void
    {
        $user = $this->merchant();

        $body = $this->actingAs($user)->get(route('profile'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'ss-errorsummary',
            $body,
            'A fresh form must render no summary. The component guards itself with '
            . '@if ($errors->any()); an unconditional wrapper would shout at a merchant who '
            . 'has submitted nothing.'
        );

        // CONTROL that the page really did render the form, so the assertion above is not
        // passing because the response was empty or a redirect.
        $this->assertStringContainsString('value="Measured Merchant"', $body);
        $this->assertStringContainsString('name="mobile"', $body);
    }

    /**
     * NEGATIVE CONTROL: the success branch must be untouched. This is the local copy of
     * tests/Feature/Platform/ProductProfileSavePathTest.php:94 -- it goes red if the
     * withErrors()/withInput() append is placed OUTSIDE the `if ($validator->fails())` block.
     */
    public function test_a_valid_profile_save_still_flashes_no_errors_at_all(): void
    {
        $user = $this->merchant();

        $post = $this
            ->actingAs($user)
            ->from(route('profile'))
            ->post(route('update.account'), [
                'name' => 'Renamed Merchant',
                'email' => 'renamed@example.test',
                'mobile' => '96500000000',
            ]);

        $post->assertRedirect(route('profile'));
        $post->assertSessionHas('success');
        $post->assertSessionHasNoErrors();

        $this->assertSame('Renamed Merchant', $user->refresh()->name);
    }

    /**
     * NEGATIVE CONTROL for the credential census at
     * tests/Feature/Security/CredentialSinksTest.php:260 -- an exact-equality census over
     * every .php file in app/ and packages/ matching /->withInput\(\s*\)/, whose allowed set
     * is exactly five files, none of them UserController. A bare ->withInput() here would
     * flash $request->input() WHOLESALE into the session payload
     * (vendor/laravel/framework/src/Illuminate/Http/RedirectResponse.php:74-81) with
     * SESSION_DRIVER defaulting to database and encryption off. So: no password value may
     * appear in the flashed old input, and the construct must carry an argument.
     */
    public function test_no_password_value_is_ever_flashed_into_the_old_input(): void
    {
        $user = $this->merchant();

        $this
            ->actingAs($user)
            ->from(route('profile'))
            ->put(route('user.password.update'), [
                'type' => 'admin',
                'old_password' => 'SECRET-OLD-VALUE',
                'new_password' => 'SECRET-NEW-VALUE',
                'new_password_confirmation' => 'SECRET-MISMATCH',
            ]);

        $old = session('_old_input', []);

        $this->assertIsArray($old);
        $this->assertArrayNotHasKey('old_password', $old);
        $this->assertArrayNotHasKey('new_password', $old);
        $this->assertArrayNotHasKey('new_password_confirmation', $old);
        $this->assertArrayNotHasKey('password', $old);

        $this->assertStringNotContainsString(
            'SECRET-OLD-VALUE',
            json_encode($old),
            'A credential typed into the change-password card must not be written to the '
            . 'session payload. Use ->withInput($request->except([...])), never bare '
            . '->withInput() -- CredentialSinksTest.php:260 is an exact-equality census of '
            . 'the bare construct and its allowed set names five files, none of them this one.'
        );

        // `type` must be excluded too: profile.blade.php:193 is
        // Form::hidden('type', Auth::guard()->user()->type) and konekt's getValueAttribute()
        // reads old() BEFORE the model value, so a flashed `type` would override the
        // account's real type in the re-rendered hidden field.
        $this->assertArrayNotHasKey(
            'type',
            $old,
            'The hidden `type` field must not be repopulated from old input, or a merchant '
            . 'who failed a password change re-renders someone else\'s type in the form.'
        );
    }

    /**
     * THE SAME EXCLUSION, ON THE OTHER HALF OF THE SAME REPAIR. `type` was excluded from
     * password_update's flashed input and NOT from editprofile's, although both redirect back
     * to profile.blade.php:209's Form::hidden('type', Auth::guard()->user()->type) and konekt's
     * getValueAttribute() reads old() BEFORE the model value either way.
     *
     * THIS IS A LATENT TRAP, NOT A LIVE ESCALATION, and the negative control below is what
     * says so: editprofile persists only name/email/mobile/profile_image by explicit
     * assignment, with no fill() anywhere in it, so a submitted `type` is never written. It is
     * pinned here precisely because the next person to add a fill() to this method would not
     * otherwise find out.
     */
    public function test_no_type_value_is_flashed_by_a_refused_personal_info_save(): void
    {
        $user = $this->merchant();

        $realType = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $user->id)->value('type');

        $this
            ->actingAs($user)
            ->from(route('profile'))
            ->post(route('update.account'), [
                'type' => 'super admin',
                'name' => 'Kept Name',
                'email' => '',
            ]);

        $old = session('_old_input', []);

        $this->assertIsArray($old);

        // PREMISE: the refusal really did flash old input. Without this the assertion below
        // would pass just as well for a controller that flashed nothing at all.
        $this->assertArrayHasKey('name', $old,
            'premise: a refused personal-info save flashes old input');
        $this->assertSame('Kept Name', $old['name']);

        $this->assertArrayNotHasKey('type', $old,
            'a flashed `type` re-renders in profile.blade.php:209\'s hidden field, because '
            . 'konekt\'s getValueAttribute() reads old() before the model value');

        // NEGATIVE CONTROL, read straight from the table so no Cachable read can answer for it:
        // the account's real type never moved.
        $this->assertSame($realType, \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $user->id)->value('type'),
            'editprofile persisted a request-supplied `type`');
    }

    /**
     * SECOND SLICE OF THE SAME CLASS A REPAIR: the Store Settings page. Only the TWO
     * AppSettingController validator sites that genuinely consult fails() are touched --
     * :581 (store_slug, inside ThemeSettings) and :1290 (SiteSetting, date_format).
     *
     * THE FOUR OTHERS ARE DELIBERATELY LEFT ALONE and the premise test below pins that.
     * AppSettingController.php:266 (seoSettings), :336 (shippingLabelSettings), :603 and
     * :611 (ThemeSettings domains/subdomain) each BUILD a validator and never consult it --
     * verified: grep of Validator::make returns 266, 336, 568, 603, 611, 1284 and
     * validator->fails() returns only 581 and 1290. Giving any of them a fails() guard is a
     * BUSINESS-RULE CHANGE wearing a repair's clothes: it would start refusing SEO saves with
     * an empty Facebook pixel and shipping-label saves missing store_state, and it would
     * redden tests/Feature/Security/HostClaimsTest.php:490 and :510, which POST
     * /theme-settings with domains='' and assert assertSessionMissing('error') precisely
     * because an EMPTY domains must still reach the hosting-panel cleanup. Reviving it would
     * leave a merchant unable to clear their own custom domain.
     */
    public function test_a_refused_store_slug_reaches_the_settings_page_as_a_real_error_bag(): void
    {
        $user = $this->merchant();

        $post = $this
            ->actingAs($user)
            ->from(route('app-setting.index'))
            ->post(route('theme.settings'), [
                'app_setting_tab' => 'pills-home-tab',
                'store_slug' => 'Not A Valid Slug!',
            ]);

        $post->assertSessionHas('error');
        $post->assertSessionHasErrors('store_slug');

        // AppSettingController::index() gates on isAbleTo('Manage Store Setting') -- Laratrust,
        // no super-admin bypass -- so without the grant the GET below 302s and the body
        // assertion would error instead of measuring anything.
        $this->grantPermission(User::find($user->id), 'Manage Store Setting');

        $body = $this->actingAs(User::find($user->id))
            ->get(route('app-setting.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'ss-errorsummary',
            $body,
            'AppSetting/index.blade.php already carries @error("store_slug") at :161 and more '
            . 'than a dozen sibling blocks, all dead markup until a bag arrives. The page-level '
            . 'summary is what makes the refusal persistent instead of a 4-second toast; the '
            . 'existing app_setting_tab hidden field already restores the failing pane.'
        );
    }

    public function test_a_refused_site_setting_reaches_the_settings_page_as_a_real_error_bag(): void
    {
        $user = $this->merchant();

        $post = $this
            ->actingAs($user)
            ->from(route('app-setting.index'))
            ->post(route('site.setting'), [
                'app_setting_tab' => 'pills-home-tab',
            ]);

        $post->assertSessionHas('error');
        $post->assertSessionHasErrors('date_format');
    }

    /**
     * PREMISE TEST / NEGATIVE CONTROL. A later sweep must not quietly revive the four dead
     * validators, so this asserts they are still dead BY STRUCTURE: the statement that
     * follows each Validator::make block is not a fails() guard. Asserted over the source,
     * because no HTTP probe can tell "validator never consulted" from "payload happened to
     * pass" -- which is exactly the false comfort
     * tests/Feature/Security/SettingsCreatedByOwnershipTest.php:115/:172 gives, its fixtures
     * supplying every required field.
     */
    /**
     * THE NEW FLASH SITES ARE COVERED BY THE SCRUBBER -- MEASURED, AND IT REFUTES THE REVIEW
     * FINDING THAT PROMPTED THIS TEST.
     *
     * An authorization review of this batch read the two failure paths this repair added, saw
     * `$request->except(['_token', 'password', 'password_confirmation'])` -- a denylist of three
     * names on handlers that write EVERY posted name into `settings`, whose restricted space is
     * far wider (StoreSettingsAccess::RESTRICTED_PREFIX alone covers mail_, s3_, wasabi_,
     * local_storage_, shopify_, woocommerce_, twilio_, whatsapp_, firebase_ and fcm_) -- and
     * concluded that a post carrying mail_password which then failed the store_slug regex would
     * write that plaintext into _old_input.
     *
     * IT DOES NOT, AND THE REASON IS NOT THE except() LIST. Driven with the permission that
     * matters, `mail_password` is still in $request->all() after
     * StoreSettingsAccess::refusesRestrictedPost() -- that method only strips restricted names
     * for a caller who LACKS the Settings permission -- and it is still in what the controller
     * hands to withInput(). It is absent from _old_input anyway, because
     * App\Http\Middleware\FlashedCredentialScrubber, appended to the `web` group at
     * bootstrap/app.php:54, removes restricted names from the flashed input after the fact.
     *
     * So no controller change was made: there was no defect to repair, and widening the three
     * names would have been a redundant second control dressed as a fix. What was missing was
     * COVER -- nothing asserted that the scrubber reaches these two new sites. That is this
     * test, and the bite is removing the scrubber from the middleware group.
     */
    public function test_a_refused_theme_setting_flashes_no_restricted_name(): void
    {
        // INSTRUMENT CONTROL FIRST: if the predicate did not classify these two names this way,
        // every assertion below would be about something other than what it claims.
        $this->assertTrue(\App\Services\StoreSettingsAccess::isRestrictedName('mail_password'),
            'premise: mail_password is a restricted name');
        $this->assertFalse(\App\Services\StoreSettingsAccess::isRestrictedName('store_slug'),
            'premise: store_slug is NOT restricted, so the filter must leave it alone');

        $user = $this->merchant();

        $post = $this
            ->actingAs($user)
            ->from(route('app-setting.index'))
            ->post(route('theme.settings'), [
                'app_setting_tab' => 'pills-home-tab',
                'store_slug' => 'Not A Valid Slug!',
                'mail_password' => 'SMTP-SECRET-VALUE',
            ]);

        $post->assertSessionHas('error');

        $old = session('_old_input', []);

        $this->assertIsArray($old);

        // PREMISE: the refusal really did flash old input, and kept the benign field. Without
        // this the two assertions below would pass for a handler that flashed nothing at all --
        // which is how this repair could be "fixed" by being reverted.
        $this->assertArrayHasKey('store_slug', $old,
            'premise: a refused theme setting still flashes the old input this repair added');
        $this->assertSame('Not A Valid Slug!', $old['store_slug']);

        // MEASURED: the controller's own except() list does NOT filter this name, and the
        // request still carries it when withInput() is called. Removing
        // FlashedCredentialScrubber from the `web` group makes both assertions below fail, which
        // is how this test was confirmed to be pinning the scrubber rather than the except().
        $this->assertArrayNotHasKey('mail_password', $old,
            'a restricted name reached _old_input. The controller does not filter it -- '
            . 'FlashedCredentialScrubber (bootstrap/app.php:54) is the control, and it no '
            . 'longer covers this flash site.');

        $this->assertStringNotContainsString('SMTP-SECRET-VALUE', json_encode($old),
            'the SMTP password is in the session payload under some other key');
    }

    public function test_the_four_never_consulted_appsetting_validators_are_still_not_consulted(): void
    {
        $lines = explode("\n", (string) file_get_contents(
            base_path('app/Http/Controllers/AppSettingController.php')
        ));

        // Walk the file once and pair each Validator::make with whether a
        // `$validator->fails()` appears before the NEXT Validator::make. Asserted
        // structurally, not by absolute line number, because the repair's own comments move
        // the numbers and a line-pinned census would fail for the wrong reason.
        $consulted = [];
        $open = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'Validator::make')) {
                if ($open !== null) {
                    $consulted[$open] = $consulted[$open] ?? false;
                }
                $open = $i + 1;
                $consulted[$open] = false;
            }
            if ($open !== null && str_contains($line, 'validator->fails()')) {
                $consulted[$open] = true;
            }
        }

        $this->assertCount(
            6,
            $consulted,
            'The Validator::make census in AppSettingController has moved. Re-measure before '
            . 'trusting the consulted/not-consulted split below.'
        );

        $this->assertSame(
            2,
            count(array_filter($consulted)),
            'EXACTLY TWO of the six validators in AppSettingController are consulted, and only '
            . 'those two were given withErrors()/withInput(). If this has grown, somebody '
            . 'activated a dead validator: that refuses posts that succeed today (an empty '
            . 'Facebook pixel, a blank store_state) and removes the merchant\'s ability to '
            . 'blank a custom domain, which HostClaimsTest.php:490/:510 exist to protect.'
        );

        $this->assertSame(
            4,
            count(array_filter($consulted, fn ($c) => $c === false)),
            'Four validators in AppSettingController are built and never consulted. Reviving '
            . 'any of them is a business-rule change needing an owner decision, not part of a '
            . 'presentation repair.'
        );
    }
}
