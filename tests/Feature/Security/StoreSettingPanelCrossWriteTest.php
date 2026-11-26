<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\AppSettingController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * GAP-289 -- SAVING ONE PANEL OF THE STORE SETTING SCREEN SILENTLY SWITCHES OFF ANOTHER PANEL'S
 * FEATURE FLAGS.
 * =================================================================================================
 *
 * THE MIRROR IMAGE OF WHAT THIS PROJECT WATCHES FOR. The usual defect here is a READ crossing a
 * boundary -- a query without a store predicate. This is a WRITE doing it, and the boundary is not
 * the tenant: it is the PANEL. One store, one authorised user, one entirely ordinary save, and data
 * belonging to a different tab is rewritten.
 *
 * THE MECHANISM, MEASURED FROM THE RENDERED PAGE RATHER THAN INFERRED. `GET /app-setting` renders
 * FIVE forms, and three of them post to the SAME handler, `AppSettingController::ThemeSettings`:
 *
 *   form @136-280   app_setting_tab=pills-home-tab           -> /theme-settings
 *   form @308-419   app_setting_tab=pills-seo-tab             -> /seo-setting
 *   form @443-691   app_setting_tab=pills-custom-tab          -> /theme-settings   (storecss, storejs)
 *   form @711-747   app_setting_tab=pills-checkout-tab        -> /theme-settings   (additional_notes, ...)
 *   form @761-828   app_setting_tab=pills-shippinglabel-tab   -> /shipping-label-setting
 *
 * `ThemeSettings` guards most of its ~38 names with `if ($request->x)`, so an absent field leaves
 * the stored value alone. FIVE DO NOT -- each carries an `else` that writes `'off'`:
 *
 *     if ($request->cart_quantity_control_enable) { ... } else { $post['cart_quantity_control_enable'] = 'off'; }
 *     ... enable_product_compare, enable_product_enquiry, enable_product_barcode, enable_spin_to_win
 *
 * And the checkboxes for those five are rendered by `@stack('appSettingEnableBtn')` at LINE 197 --
 * inside the `pills-home-tab` form, and nowhere else. Five vendored modules push into that one
 * stack (`CartQuantityControl`, `ProductBarCode`, `ProductCompare`, `SpinToWinReward`,
 * `ProductEnquiry`, each from its own `ViewComposer`). So the Style tab and the Checkout tab post to
 * a handler that unconditionally writes `'off'` to five flags whose controls live on a tab they
 * cannot see.
 *
 * AN UNCHECKED HTML CHECKBOX POSTS NOTHING, which is why the `else` exists and why this is not
 * simply dead code: for the HOME tab it is how "the merchant unticked the box" is recorded. The
 * request genuinely cannot distinguish "unticked on the owning form" from "a different form was
 * saved" -- the information is absent. What CAN distinguish them is already in every one of these
 * forms: the hidden `app_setting_tab` marker.
 *
 * THE HANDLER ALREADY KNOWS THIS HAZARD AND ALREADY FIXED ONE FAMILY OF IT. Its own docblock says
 * the domain radio's token handling has to be neutralised because a save "includes the Home and
 * Checkout tabs, whose forms post no routing field at all". `StoreSettingsAccess::DOMAIN_ROUTING`
 * exists for that. These five flags are the same hazard, left standing.
 *
 * WHY A RESPONSE-LEVEL TEST IS BLIND TO THIS, decided before the file was written rather than after:
 *
 *   - every one of these writers answers `redirect()->back()` with a success flash whatever it
 *     wrote, so the response is byte-identical in both directions; and
 *   - the five fields are on a DIFFERENT tab, so the rendered HTML of the panel being saved does
 *     not change either -- and with no `add_on_managers` row the checkboxes are absent from the
 *     whole page, so even a full-page assertion sees nothing.
 *
 * So the instrument is a BEFORE/AFTER READ OF THE OTHER PANEL'S STORED ROWS, read raw. `Setting` is
 * `Cachable` and these handlers write through Eloquent, so the raw table is the honest source.
 *
 * MODULE ACTIVATION IS NOT A PRECONDITION, and that is asserted rather than assumed. The `else`
 * branches are in the controller, not in a module, so they fire whether or not any of the five
 * modules is enabled. CLAUDE.md records that with `add_on_managers` empty a module's views do not
 * resolve at all -- which makes the defect WORSE, not untestable: the controls are then invisible
 * everywhere while the flags keep being switched off.
 */
class StoreSettingPanelCrossWriteTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The flags and the owning panel, READ FROM THE CONTROLLER rather than repeated here -- a copy
     * would go on passing after the controller's list changed, which is the whole failure mode this
     * file exists to catch.
     *
     * @var list<string>
     */
    private const HOME_TAB_FLAGS = AppSettingController::PANEL_OWNED_FEATURE_FLAGS;

    private const OWNING_PANEL = AppSettingController::FEATURE_FLAG_PANEL;

    private User $merchant;
    private \App\Models\Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchant, $this->store] = $this->makeTenant();

        $this->merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'gap289-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->merchant->save();

        /* `Manage Store Setting` opens the screen (`AppSettingController::index`), `Manage Setting`
           keeps `refusesRestrictedPost()` from refusing the save. The OWNER gets no bypass here:
           this application decides by `isAbleTo()`, so without the grant the screen 302s to `/`. */
        foreach (['Manage Store Setting', 'Edit Store Setting', 'Manage Setting'] as $permission) {
            $this->grantPermission($this->merchant, $permission);
        }

        (new User)->flushCache();
    }

    /** Raw, because `Setting` is `Cachable` and the writers go through Eloquent. */
    private function storedFlag(string $name): ?string
    {
        return DB::table('settings')
            ->where('store_id', $this->store->id)->where('name', $name)
            ->orderBy('id')->value('value');
    }

    private function seedHomeTabFlagsOn(): void
    {
        foreach (self::HOME_TAB_FLAGS as $name) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => 'on',
                'created_by' => $this->merchant->id, 'store_id' => $this->store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (self::HOME_TAB_FLAGS as $name) {
            $this->assertSame('on', $this->storedFlag($name), "premise: {$name} starts on");
        }
    }

    /** The input names a given panel's form really carries, read off the rendered page. */
    private function fieldsOfPanel(string $tabMarker): array
    {
        $response = $this->actingAs(User::find($this->merchant->id))->get('/app-setting');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'premise: the Store Setting screen renders. It is gated on `Manage Store Setting` via '
            . 'isAbleTo(), so a 302 here means the grant in setUp() stopped working, not that the '
            . 'screen is gone.'
        );

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($response->getContent());
        libxml_clear_errors();

        foreach ($doc->getElementsByTagName('form') as $form) {
            $names = [];

            foreach (['input', 'select', 'textarea'] as $tag) {
                foreach ($form->getElementsByTagName($tag) as $element) {
                    $name = $element->getAttribute('name');

                    if ($name !== '' && $element->getAttribute('type') !== 'submit') {
                        $names[$name] = $element->getAttribute('value');
                    }
                }
            }

            if (($names['app_setting_tab'] ?? null) === $tabMarker) {
                return $names;
            }
        }

        $this->fail("no form on /app-setting carries app_setting_tab={$tabMarker}");
    }

    /**
     * The two panels that post to `ThemeSettings` without owning the five flags.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function foreignPanels(): array
    {
        return [
            'style tab (storecss/storejs)' => ['pills-custom-tab', [
                'storecss' => '.gap289 { color: red; }',
                'storejs' => '/* gap289 */',
            ]],
            'checkout tab (additional notes)' => ['pills-checkout-tab', [
                'additional_notes' => 'on',
                'is_checkout_login_required' => 'on',
            ]],
        ];
    }

    /**
     * THE DEFECT. Saving a panel that does not own the five flags must not switch them off.
     */
    #[Test]
    #[DataProvider('foreignPanels')]
    public function test_saving_a_panel_does_not_switch_off_another_panels_feature_flags(
        string $tabMarker,
        array $ownFields
    ): void {
        $this->seedHomeTabFlagsOn();

        /* THE CROSS-PANEL PREMISE, measured off the rendered page rather than asserted in prose:
           this panel really does not carry these fields, so anything that happens to them is a
           write across a panel boundary and not this form saving its own state. */
        $rendered = $this->fieldsOfPanel($tabMarker);

        foreach (self::HOME_TAB_FLAGS as $name) {
            $this->assertArrayNotHasKey(
                $name,
                $rendered,
                "premise: the {$tabMarker} form does not render {$name}, so saving it cannot be the "
                . 'merchant changing that flag'
            );
        }

        $this->actingAs(User::find($this->merchant->id))
            ->post('/theme-settings', $ownFields + ['app_setting_tab' => $tabMarker]);

        $switchedOff = [];

        foreach (self::HOME_TAB_FLAGS as $name) {
            if ($this->storedFlag($name) !== 'on') {
                $switchedOff[] = $name . '=' . json_encode($this->storedFlag($name));
            }
        }

        $this->assertSame(
            [],
            $switchedOff,
            "Saving the {$tabMarker} panel switched off feature flags belonging to the "
            . 'pills-home-tab panel, whose checkboxes are rendered by @stack(\'appSettingEnableBtn\') '
            . 'and appear on no other form. The merchant saw a success message and no indication '
            . "anything else changed. Switched off: " . implode(', ', $switchedOff)
        );
    }

    /**
     * THE CONTROL THAT STOPS THE FIX BEING A REGRESSION: the owning panel must still be able to
     * switch a flag OFF.
     *
     * An unchecked checkbox posts nothing, so "the merchant unticked it" and "this field is not on
     * the form I saved" arrive as the same request. Any fix has to tell them apart rather than
     * simply ignore an absent field -- if it ignores it, this case fails and says so.
     */
    #[Test]
    public function test_control_the_owning_panel_can_still_switch_a_feature_flag_off(): void
    {
        $this->seedHomeTabFlagsOn();

        $home = $this->fieldsOfPanel(self::OWNING_PANEL);

        /* Post the home tab's OWN fields, with no checkbox among them -- which is exactly what the
           browser sends when every box on that tab is unticked. */
        $payload = [];

        foreach ($home as $name => $value) {
            if (! in_array($name, self::HOME_TAB_FLAGS, true) && $name !== '_token') {
                $payload[$name] = $value === '' ? 'gap289' : $value;
            }
        }

        $payload['app_setting_tab'] = self::OWNING_PANEL;
        unset($payload['theme_image'], $payload['invoice_logo'], $payload['_method']);

        $this->actingAs(User::find($this->merchant->id))->post('/theme-settings', $payload);

        $stillOn = array_values(array_filter(
            self::HOME_TAB_FLAGS,
            fn (string $name): bool => $this->storedFlag($name) === 'on'
        ));

        $this->assertSame(
            [],
            $stillOn,
            'The owning panel can no longer switch these flags off. An unchecked checkbox posts '
            . 'nothing, so this is the ONLY way a merchant turns one of them off -- a fix for '
            . 'GAP-289 that merely ignores an absent field removes the off switch. Still on: '
            . implode(', ', $stillOn)
        );
    }

    /**
     * AND ON. A flag the request carries as `on` must be stored as `on`, from the owning panel.
     */
    #[Test]
    public function test_control_the_owning_panel_can_still_switch_a_feature_flag_on(): void
    {
        $this->actingAs(User::find($this->merchant->id))->post('/theme-settings', [
            'app_setting_tab' => self::OWNING_PANEL,
            'enable_product_compare' => 'on',
        ]);

        $this->assertSame(
            'on',
            $this->storedFlag('enable_product_compare'),
            'a flag posted as `on` from its own panel was not stored'
        );
    }

    /**
     * THE PREMISE THE FIX RESTS ON, PINNED AGAINST THE VIEW ITSELF.
     *
     * `FEATURE_FLAG_PANEL` is a string shared between a controller and a Blade file, and CLAUDE.md
     * records what happens to those: they drift silently. If the stack moves to another tab, or the
     * hidden marker is renamed, the controller would go on scoping its `else` defaults to a panel
     * that no longer owns the checkboxes -- which restores GAP-289 with every test still green.
     *
     * So this reads the view, finds the form that CONTAINS `@stack('appSettingEnableBtn')`, and
     * asserts that form's marker is the one the controller names. Blade comments are stripped first,
     * because a census that reads comments can be satisfied -- or broken -- by prose about the thing
     * it measures.
     */
    #[Test]
    public function test_premise_the_feature_flag_checkboxes_render_inside_the_panel_the_controller_names(): void
    {
        $view = base_path('resources/views/AppSetting/index.blade.php');

        $this->assertFileExists($view, 'the Store Setting screen has moved; re-point this premise');

        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($view));

        $stackAt = strpos($source, "@stack('appSettingEnableBtn')");

        $this->assertNotFalse(
            $stackAt,
            'the stack that renders the feature-flag checkboxes is gone from this screen. The five '
            . 'modules still push into it (their ViewComposers), so either it moved to another view '
            . '-- re-point this premise and AppSettingController::FEATURE_FLAG_PANEL -- or the '
            . 'checkboxes no longer render anywhere.'
        );

        /* The enclosing form: the last opening before the stack, and its own marker. */
        $openAt = strrpos(substr($source, 0, $stackAt), 'Form::model(');

        $this->assertNotFalse($openAt, 'the stack is not inside a form at all');

        $closeAt = strpos($source, 'Form::close()', $openAt);

        $this->assertTrue(
            $closeAt !== false && $closeAt > $stackAt,
            'the stack is not enclosed by the form that opens before it'
        );

        $enclosing = substr($source, $openAt, $closeAt - $openAt);

        $this->assertSame(
            1,
            preg_match('/name="app_setting_tab"\s+value="([^"]+)"/', $enclosing, $marker),
            'the form holding the feature-flag checkboxes posts no app_setting_tab marker, so the '
            . 'controller has nothing to scope its `off` defaults by and GAP-289 is open again'
        );

        $this->assertSame(
            self::OWNING_PANEL,
            $marker[1],
            'the form that renders the feature-flag checkboxes posts a different panel marker than '
            . 'AppSettingController::FEATURE_FLAG_PANEL. The controller would scope its `off` '
            . 'defaults to a panel that does not own them, which is GAP-289 restored: saving the '
            . 'owning tab would stop switching flags off, and saving the named tab would switch '
            . 'them off from a panel with no controls on it.'
        );
    }

    /**
     * A SAVE THAT NAMES NO PANEL LEAVES THEM ALONE -- a deliberate choice, pinned so it is not
     * mistaken for an oversight.
     *
     * Silent data loss is the failure being fixed, so the absent-marker case fails towards keeping
     * what is stored. Nothing in the product posts to this handler without a marker; every one of
     * the five forms on the screen carries one.
     */
    #[Test]
    public function test_a_save_that_names_no_panel_does_not_switch_the_flags_off(): void
    {
        $this->seedHomeTabFlagsOn();

        $this->actingAs(User::find($this->merchant->id))
            ->post('/theme-settings', ['storecss' => '.x{}']);

        foreach (self::HOME_TAB_FLAGS as $name) {
            $this->assertSame(
                'on',
                $this->storedFlag($name),
                "a POST carrying no app_setting_tab switched {$name} off; an unidentified save must "
                . 'fail towards preserving stored state'
            );
        }
    }
}
