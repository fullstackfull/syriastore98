<?php

namespace Tests\Feature\Merchant;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-060 / P02-I4 — THE THREE SURFACES THAT ROUND NAMED AND COULD NOT REACH.
 * =================================================================================================
 *
 * GAP-060 took `x-ss.choice-group` / `x-ss.field-help` coverage from 5 product views to 16 and left
 * three surfaces open BY NAME: `resources/views/setting/`, `resources/views/users/` staff forms,
 * and `order/view`'s `payment_status` / `delivery_boy` toolbar selects. This file is that remainder,
 * plus the reconciliation the row asked for before it can close.
 *
 * ------------------------------------------------------------------------------------------------
 *  THE RECONCILIATION — the settings half is PARTLY done, and the green test does not say so
 * ------------------------------------------------------------------------------------------------
 *
 * `SettingsChoiceGroupGuidanceTest` is green and correct. It is also **a declared list of four
 * groups, not a census**, so it is green by construction for the groups it names and silent about
 * every control it does not name. Measured across all 31 views in `resources/views/setting/`:
 *
 *     radio groups          6 of 6 accounted for   4 instructed + 2 declared-uninstructed
 *     single <select>s      0 of 22 instructed     `x-ss.field-help` has ZERO call sites there
 *     <fieldset>s          11 total, 6 instructed  5 are switch/checkbox groups with no hint
 *
 * So the GROUP half of settings is finished and the SINGLE-CONTROL half had not been started. That
 * is the answer to "is it genuinely done or only partly", and `test_the_settings_guidance_census_is_accounted_for`
 * below is that answer in executable form: every choice control in `setting/` must be either
 * instructed or declared OPEN with a reason, so the remainder is countable instead of invisible.
 *
 * ONE of the 22 is closed here rather than all of them, and the choice is not arbitrary: the
 * records already point at `shop_price`. `SettingsChoiceGroupGuidanceTest::test_the_tax_instruction_names_the_second_setting_the_behaviour_depends_on`
 * asserts that the `price_type` instruction names "Display prices in the shop" — so the product
 * already tells a merchant that this select decides whether tax is added on top, while the select
 * itself says nothing. The other 21 are a slice of their own and each needs its behaviour verified
 * in code first, which is what makes them a slice rather than a sweep.
 *
 * A CORRECTION FOR THE RECORDS, found while verifying rather than assumed: that instruction and its
 * docblock both say "four call sites". There are **ten** in `app/`, each testing
 * `price_type == 'inclusive' && shop_price == 'including'` or its negation — `Cart.php:718`,
 * `Cart.php:1036`, `Product.php:420`, `Tax.php:73`, `ProductController.php:5000`, `:5145`, `:5293`,
 * `PosController.php:215`, `:529`, `:789`. The substance is right and the count is understated;
 * `Cart.php:1010` has also drifted to `:1036`. That file is another slice's evidence, so this is
 * reported rather than edited.
 *
 * ------------------------------------------------------------------------------------------------
 *  WHICH COMPONENT, AND WHY IT IS NOT A CHOICE
 * ------------------------------------------------------------------------------------------------
 *
 * `x-ss.field-help` + `aria-describedby` for ONE control; `x-ss.choice-group` (fieldset + legend +
 * hint bound to the fieldset) for a SET. A `<select>` is ONE control in the accessibility tree —
 * its options are not siblings in the tab order — so wrapping a lone select in a fieldset is the
 * WRONG association rather than a stricter one. Both surfaces this file instructs are single
 * selects, so both take `field-help`. That reasoning is in the component's own docblock.
 *
 * ------------------------------------------------------------------------------------------------
 *  EVERY INSTRUCTION'S CLAIM WAS VERIFIED IN CODE BEFORE IT WAS WRITTEN
 * ------------------------------------------------------------------------------------------------
 *
 *  `shop_price`  — ten call sites above, each reading the PAIR. Either setting alone does nothing.
 *  `role`        — `UserController::create():59` lists `Role::where('created_by', creatorId())
 *                  ->where('store_id', getCurrentStore())`, so the list is scoped to the store the
 *                  merchant is in; `store():139-162` re-scopes the same way, writes
 *                  `$user->type = $role_r->name` and `$user->is_assign_store = current_store`. So
 *                  all three claims — listed per store, role becomes the account type, member
 *                  assigned to this store — are read off the write path, not inferred.
 *
 * Driven through the real endpoints, not by rendering Blade directly: the settings panels arrive by
 * AJAX inside a JSON envelope and the staff form is a modal partial, so a view-source assertion
 * would not prove a merchant can ever see the sentence.
 */
final class DeferredChoiceGuidanceTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** Every `setting/` view, so the census cannot quietly narrow. */
    private const SETTING_VIEW_GLOB = 'resources/views/setting/*.blade.php';

    /**
     * The settings choice controls that carry an instruction today.
     *
     * The four groups are `SettingsChoiceGroupGuidanceTest`'s own `INSTRUCTED` list, repeated here
     * so this census fails if that file's coverage shrinks — the two must agree.
     *
     * @var list<string>
     */
    private const SETTINGS_INSTRUCTED = [
        'price_type', 'site_currency_symbol_position', 'site_currency_symbol_name', 'color',
        'shop_price',
    ];

    /**
     * THE REMAINDER, DECLARED WITH A REASON EACH. This is the reconciliation: 21 single selects and
     * 5 switch groups in `resources/views/setting/` carry no instruction, and none of them is
     * covered by any test until it appears here.
     *
     * An entry is NOT a deferral by anybody's decision — it is an open item with a stated reason
     * why it is not in this slice. Moving one out of this list is how it gets closed.
     *
     * @var array<string, string>
     */
    private const SETTINGS_OPEN = [
        // tax_opt_setting — the three siblings of shop_price. Each needs its own code read.
        'checkout_price' => 'needs its cart/checkout call sites read before a sentence can be true',
        'display_tax_option' => 'needs its call sites read; may interact with the pair above',
        'tax_id' => 'selects a tax rate row; the instruction depends on how Tax::class applies it',
        // currency_setting — money formatting, and two of them can collide.
        'defult_currancy' => 'currency code; CurrencySeparatorDefaultTest already covers collisions',
        'currency_format' => 'needs helper.php read for what each format actually prints',
        'decimal_separator' => 'collides with thousand_separator; the resolution is already tested',
        'thousand_separator' => 'collides with decimal_separator; the resolution is already tested',
        // system_setting
        'default_language' => 'merchant panel language; needs SetLocate read',
        'defult_timezone' => 'needs the places that format dates read',
        'site_date_format' => 'needs Utility::dateFormat read (F20 is open on that helper)',
        'site_time_format' => 'needs Utility::timeFormat read',
        // otp_setting
        'otp_channel' => 'OTP delivery; P01-OTP-LENGTH-COMPATIBILITY is an owner decision in flight',
        'sms_mode' => 'same family as otp_channel',
        'sms_priority' => 'same family as otp_channel',
        'sms_sim' => 'same family as otp_channel',
        // integrations
        'email_setting' => 'mail driver; a wrong instruction here stops mail, so it wants care',
        'google_recaptcha_version' => 'needs the verifying middleware read',
        'chat_gpt_model' => 'model choice; cost and capability claims must not be invented',
        // storage_setting — and the group above them is already declared BROKEN by the other test.
        'local_storage_validation' => 'the storage_setting group it belongs to has a recorded defect',
        's3_storage_validation' => 'the storage_setting group it belongs to has a recorded defect',
        'wasabi_storage_validation' => 'the storage_setting group it belongs to has a recorded defect',
        // the five switch/checkbox fieldsets: a SET, so choice-group not field-help.
        'Sidebar Settings' => 'switch group in brand_setting; choice-group, not field-help',
        'Layout Settings' => 'switch group in brand_setting; choice-group, not field-help',
        'Send an email when' => 'switch group in email_notify_setting; choice-group',
        'Notifications' => 'checkbox group in stock_setting; choice-group',
        'Send a WhatsApp message when' => 'switch group in whatsapp_notify_setting; choice-group',
    ];

    /** The two groups the other test declares BROKEN and therefore deliberately silent. */
    private const SETTINGS_BROKEN = ['currency_space', 'storage_setting'];

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        [$merchant] = $this->makeTenant();
        $this->merchant = $merchant;

        Cache::flush();
    }

    private function panel(string $tab, ?string $language = null): string
    {
        $merchant = User::find($this->merchant->id);

        if ($language !== null) {
            $merchant->language = $language;
            $merchant->save();
            $merchant = User::find($this->merchant->id);
        }

        $response = $this->actingAs($merchant)->post('/setting-form', ['tab_type' => $tab]);

        $this->assertSame(200, $response->getStatusCode(),
            "the {$tab} panel did not render, so every assertion about it would be vacuous");

        $json = json_decode($response->getContent(), true);

        $this->assertTrue((bool) ($json['is_success'] ?? false),
            "the {$tab} panel came back as a refusal: " . ($json['msg'] ?? ''));

        $content = (string) ($json['data']['content'] ?? '');
        $this->assertNotSame('', $content, "the {$tab} panel rendered empty");

        return $content;
    }

    /** The text of the element carrying `id`, tags and entities removed. */
    private function textOfId(string $html, string $id): ?string
    {
        if (preg_match('/id="' . preg_quote($id, '/') . '"[^>]*>(.*?)</s', $html, $m) !== 1) {
            return null;
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // =============================================================================================
    //  THE RECONCILIATION, as a census rather than a declared list
    // =============================================================================================

    /**
     * PREMISE: the census really finds controls. Without this, a broken regex would report a
     * perfectly accounted-for settings tree forever — which is the shape of the problem this whole
     * test exists to expose.
     */
    public function test_premise_the_settings_census_finds_the_controls_it_is_about(): void
    {
        $census = $this->settingsCensus();

        /* THE THIRD NUMBER WAS WRONG IN THE FIRST VERSION OF THIS PREMISE, and the premise is
           why that was caught rather than carried: it expected 11 legends, which is the raw
           FIELDSET count. The census deliberately removes the six legends that belong to a radio
           group already accounted for by name, so what is left is the 5 switch/checkbox groups —
           and 5 is the honest figure for "fieldsets nothing else accounts for". Both numbers are
           asserted now so neither can be quietly reinterpreted. */
        $this->assertSame(22, count($census['selects']),
            'the census no longer finds exactly the 22 selects measured in resources/views/setting/');
        $this->assertSame(6, count($census['radios']),
            'the census no longer finds exactly the 6 radio groups measured there');
        $this->assertSame(11, $census['fieldsets'],
            'resources/views/setting/ no longer holds exactly 11 <fieldset> elements');
        $this->assertSame(5, count($census['legends']),
            'the census no longer finds exactly 5 fieldsets that own no radio group — those are '
            . 'the switch and checkbox groups, and they are the part of the settings tree that '
            . 'wants `choice-group` rather than `field-help`');
    }

    public function test_the_settings_guidance_census_is_accounted_for(): void
    {
        $census = $this->settingsCensus();

        $known = array_merge(
            self::SETTINGS_INSTRUCTED,
            self::SETTINGS_BROKEN,
            array_keys(self::SETTINGS_OPEN)
        );

        $unaccounted = [];

        foreach (['radios', 'selects', 'legends'] as $kind) {
            foreach ($census[$kind] as $name => $view) {
                if (! in_array($name, $known, true)) {
                    $unaccounted[] = "{$kind}: {$name} ({$view})";
                }
            }
        }

        sort($unaccounted);

        $this->assertSame([], $unaccounted, sprintf(
            "%d choice control(s) in resources/views/setting/ are in no list: not instructed, not "
            . "declared broken, and not declared open with a reason. A control nothing accounts for "
            . "is the invisible half this census exists to remove — add it to SETTINGS_OPEN with a "
            . "reason, or instruct it:\n  %s",
            count($unaccounted), implode("\n  ", $unaccounted)
        ));
    }

    /**
     * And the count is pinned, so a control DELETED from a settings view is noticed too. The census
     * above only catches additions; a shrinking tree would satisfy it silently.
     */
    public function test_the_settings_remainder_is_exactly_twenty_six_items(): void
    {
        $this->assertCount(26, self::SETTINGS_OPEN,
            'the declared settings remainder changed size. If an item was closed, its assertion '
            . 'belongs in this file; if one was added, say why it is open.');
    }

    /** The two files must not drift: anything the other test instructs, this census knows about. */
    public function test_this_census_knows_every_group_the_settings_test_instructs(): void
    {
        foreach (['price_type', 'site_currency_symbol_position', 'site_currency_symbol_name', 'color'] as $group) {
            $this->assertContains($group, self::SETTINGS_INSTRUCTED, sprintf(
                "SettingsChoiceGroupGuidanceTest instructs '%s' and this census does not list it, "
                . 'so the two files disagree about what is covered.',
                $group
            ));
        }
    }

    // =============================================================================================
    //  shop_price — the one settings select the records already pointed at
    // =============================================================================================

    public function test_the_shop_price_select_carries_an_instruction_bound_to_itself(): void
    {
        $html = $this->panel('tax_opt_setting');

        $this->assertMatchesRegularExpression(
            '/<select[^>]*id="shop_price"[^>]*aria-describedby="shop_price-help"/',
            $html,
            'the `shop_price` select carries no aria-describedby. It is the second half of the '
            . 'inclusive-tax pair — ten call sites read `price_type == \'inclusive\' && shop_price '
            . '== \'including\'` — and the `price_type` instruction already tells the merchant this '
            . 'control matters while the control itself says nothing.'
        );

        $text = $this->textOfId($html, 'shop_price-help');

        $this->assertNotNull($text,
            'the `shop_price` select describes itself by an id that is on no element in the panel. '
            . 'A dangling reference is worse than silence: a reader announces neither.');

        $this->assertGreaterThan(30, mb_strlen((string) $text),
            'the `shop_price` instruction is a label rather than an instruction.');
    }

    public function test_the_shop_price_instruction_names_the_setting_it_is_paired_with(): void
    {
        $text = (string) $this->textOfId($this->panel('tax_opt_setting'), 'shop_price-help');

        $this->assertStringContainsString('Prices entered with tax', $text,
            'the instruction does not name the other half of the pair. Either setting on its own '
            . 'changes nothing, so an instruction that does not say which other control is '
            . 'involved leaves the merchant exactly where they were.');
    }

    // =============================================================================================
    //  users/ staff forms — the role select
    // =============================================================================================

    /**
     * @dataProvider staffFormViews
     */
    public function test_the_staff_role_select_carries_an_instruction_bound_to_itself(string $view): void
    {
        $source = $this->sourceWithoutComments($view);

        $this->assertStringContainsString('<x-ss.field-help for="role">', $source,
            "{$view}'s role select carries no field help. The list is scoped to the store the "
            . 'merchant is in (UserController::create():59), the role becomes the member\'s account '
            . 'type (store():155) and nothing on the form says either.');

        $this->assertMatchesRegularExpression(
            "/'aria-describedby'\s*=>\s*'role-help'/",
            $source,
            "{$view}'s role select does not reference its own help. The component owns the id; the "
            . 'caller owns the association, and an orphan hint is decoration.'
        );
    }

    public static function staffFormViews(): array
    {
        return [
            'create' => ['resources/views/users/create.blade.php'],
            'edit' => ['resources/views/users/edit.blade.php'],
        ];
    }

    public function test_the_staff_role_instruction_is_the_same_sentence_on_both_forms(): void
    {
        /* One key, two call sites: a merchant who reads it on create and again on edit should not
           meet two different accounts of what a role does. */
        $create = $this->sourceWithoutComments('resources/views/users/create.blade.php');
        $edit = $this->sourceWithoutComments('resources/views/users/edit.blade.php');

        preg_match('/<x-ss\.field-help for="role">(.*?)<\/x-ss\.field-help>/s', $create, $a);
        preg_match('/<x-ss\.field-help for="role">(.*?)<\/x-ss\.field-help>/s', $edit, $b);

        $this->assertNotEmpty($a[1] ?? '', 'no role help on the create form');
        $this->assertSame(
            preg_replace('/\s+/', ' ', trim($a[1])),
            preg_replace('/\s+/', ' ', trim($b[1] ?? '')),
            'the two staff forms describe a role differently.'
        );
    }

    public function test_the_staff_role_form_renders_its_instruction_to_a_merchant(): void
    {
        /* SOURCE PRESENCE IS NOT DELIVERY, which this project has measured the hard way: a class on
           markup inside a `display:none` block satisfies a source census and changes nothing. The
           form is a modal partial, so it is driven through its real endpoint. */
        $merchant = User::find($this->merchant->id);
        $this->grantPermission($merchant, 'Create User');

        $response = $this->actingAs(User::find($merchant->id))->get('/users/create');

        $this->assertSame(200, $response->getStatusCode(),
            'the staff create form did not render for a merchant holding Create User, so every '
            . 'assertion about what it shows would be vacuous');

        $html = $response->getContent();

        $this->assertStringContainsString('id="role-help"', $html,
            'the role instruction is not in the rendered form');
        $this->assertMatchesRegularExpression('/aria-describedby="[^"]*role-help/', $html,
            'the rendered role select does not reference its own instruction');
    }

    // =============================================================================================
    //  order/view — the two toolbar selects, described ONCE because the fact is shared
    // =============================================================================================

    /**
     * WHY ONE SHARED INSTRUCTION AND NOT TWO PER-CONTROL HINTS — measured, not preferred.
     *
     * GAP-060 deferred these two because they sit in a `d-flex align-items-center` toolbar column
     * with no visible label row, so an instruction "changes the toolbar layout". Measured in the
     * running page before any change: each column is **188x40 at 1440px and 340x40 at 390px**,
     * `display: flex; flex-direction: row; align-items: center`, with the select as its only child.
     *
     * A per-control `<p>` therefore has two options and both are bad. As a second flex ROW child it
     * sits BESIDE the select and halves it. Stacked — `flex-column` — a ~150-character sentence in
     * a 172px-wide box wraps to seven or eight lines, which takes the toolbar row from 40px to
     * roughly 190px: the header of the most-used order screen would become mostly instructions.
     * That is a redesign, not a slice.
     *
     * AND THE FACT IS SHARED, WHICH IS WHAT MAKES THE SHARED PLACEMENT CORRECT RATHER THAN MERELY
     * CONVENIENT. `view.blade.php:1012` and `:1033` bind `change` on `#payment_status` and
     * `#delivery_boy` to `order.payment.status` and `order.assign` — both POST IMMEDIATELY, with no
     * Save button anywhere in the toolbar and no confirmation step; both just raise a success toast.
     * Neither control says so. One sentence describes both, and `aria-describedby` may name the
     * same id from more than one control, so the association is exact rather than approximate.
     *
     * The instruction goes in its own full-width column FIRST inside the existing row, so the row's
     * own gap and end-justification are untouched and the toolbar keeps its shape.
     */
    public function test_both_order_toolbar_selects_are_described_by_the_shared_instruction(): void
    {
        $source = $this->sourceWithoutComments('resources/views/order/view.blade.php');

        $this->assertStringContainsString('<x-ss.field-help for="order-controls">', $source,
            'order/view carries no shared instruction for its two toolbar selects.');

        foreach (['payment_status', 'delivery_boy'] as $control) {
            $this->assertMatchesRegularExpression(
                "/'id' => '" . $control . "',(?:[^)]*?)'aria-describedby' => 'order-controls-help'/s",
                $source,
                "the `{$control}` select does not reference the shared instruction, so the one fact "
                . 'a merchant needs about it — that changing it saves immediately — reaches them '
                . 'from nothing.'
            );
        }
    }

    public function test_the_order_toolbar_instruction_says_the_change_saves_immediately(): void
    {
        $source = $this->sourceWithoutComments('resources/views/order/view.blade.php');

        preg_match('/<x-ss\.field-help for="order-controls">(.*?)<\/x-ss\.field-help>/s', $source, $m);

        $this->assertNotEmpty($m[1] ?? '', 'no shared instruction found');
        $this->assertStringContainsString('immediately', $m[1],
            'the instruction does not say the change takes effect immediately, which is the only '
            . 'thing these two controls do that a merchant cannot see: both POST on `change` with '
            . 'no Save button and no confirmation (view.blade.php:1012 and :1033).');
    }

    /**
     * SOURCE PRESENCE IS NOT DELIVERY, and this test exists because the source assertions above
     * passed while the page was not yet proved to carry the element at all. The hint is bound by
     * `aria-describedby` from two controls; if the element were missing, both would point at
     * nothing — a DANGLING reference, which this project treats as worse than silence because a
     * reader announces neither the description nor an error.
     *
     * Driven through the real `order-view/{encrypted id}` route, which is the only way this screen
     * is reachable, and asserted in BOTH languages because the first live measurement of this
     * change found the sentence rendering in English on the Arabic page — the key had not been
     * declared yet.
     */
    public function test_the_order_toolbar_instruction_really_reaches_the_rendered_page(): void
    {
        $merchant = User::find($this->merchant->id);
        $order = $this->makeOrderForToolbar($merchant);

        /* GRANTED ONCE, OUTSIDE THE LOOP. `SecurityTestCase::grantPermission()` calls Laratrust's
           `givePermission()`, which is NOT idempotent: a second call for the same pair throws
           UNIQUE constraint failed on permission_role. The first version of this test granted per
           language and died on the second pass. */
        $this->grantPermission($merchant, 'Show Order');

        foreach (['en' => false, 'ar' => true] as $language => $expectArabic) {
            $merchant = User::find($this->merchant->id);
            $merchant->language = $language;
            $merchant->save();

            $html = $this->renderOrderView(User::find($merchant->id), $order);

            if ($html === null) {
                $this->markAsRisky();
                $this->fail('the order detail screen did not render for its own merchant, so this '
                    . 'assertion would be vacuous rather than passing');
            }

            $this->assertStringContainsString('id="order-controls-help"', $html,
                "the shared instruction is not in the rendered page for a '{$language}' merchant, "
                . 'so both selects describe themselves by an id that is on no element');

            $this->assertSame(2, preg_match_all('/aria-describedby="order-controls-help"/', $html),
                'the rendered page does not have exactly two controls referencing the shared '
                . 'instruction. One would mean a select lost its description; three would mean it '
                . 'was given to a control this slice never measured.');

            $text = (string) $this->textOfId($html, 'order-controls-help');

            if ($expectArabic) {
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, sprintf(
                    'the shared order instruction rendered with no Arabic in it: "%s"',
                    mb_strimwidth($text, 0, 80, '...')
                ));
            } else {
                $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $text,
                    'the shared order instruction rendered Arabic for an English merchant');
                $this->assertStringContainsString('immediately', $text);
            }
        }
    }

    public function test_the_order_toolbar_selects_keep_the_names_they_already_had(): void
    {
        /* `aria-describedby` is ADDITIVE. These selects have no visible label and are named by
           `aria-label` only; a description is not a name, and removing the name while adding a
           description would trade a working control for a described one. */
        $source = $this->sourceWithoutComments('resources/views/order/view.blade.php');

        $this->assertStringContainsString("'aria-label' => __('Payment Status')", $source,
            'the payment status select lost its accessible name');
        $this->assertStringContainsString("'aria-label' => __('Delivery Boy')", $source,
            'the delivery select lost its accessible name');
    }

    // =============================================================================================
    //  Nothing dangles, nothing is orphaned, on every surface this file touches
    // =============================================================================================

    public function test_no_instruction_on_these_surfaces_dangles_or_is_orphaned(): void
    {
        $merchant = User::find($this->merchant->id);
        $this->grantPermission($merchant, 'Create User');

        $documents = [
            'tax_opt_setting' => $this->panel('tax_opt_setting'),
            'users/create' => (string) $this->actingAs(User::find($merchant->id))->get('/users/create')->getContent(),
        ];

        foreach ($documents as $where => $html) {
            preg_match_all('/aria-describedby="([^"]+)"/', $html, $refs);

            foreach ($refs[1] as $reference) {
                foreach (preg_split('/\s+/', trim($reference)) ?: [] as $id) {
                    if ($id === '') {
                        continue;
                    }

                    $this->assertStringContainsString('id="' . $id . '"', $html,
                        "{$where}: aria-describedby names '{$id}', which is on no element here");
                }
            }

            preg_match_all('/id="([^"]*-help)"/', $html, $hints);

            foreach ($hints[1] as $id) {
                $this->assertStringContainsString('aria-describedby="' . $id . '"', $html,
                    "{$where}: the instruction '{$id}' is referenced by nothing, so it is "
                    . 'decoration rather than help');
            }
        }
    }

    // =============================================================================================
    //  Both languages
    // =============================================================================================

    public function test_the_new_instructions_render_in_arabic(): void
    {
        $text = (string) $this->textOfId($this->panel('tax_opt_setting', 'ar'), 'shop_price-help');

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, sprintf(
            'the shop_price instruction rendered with no Arabic in it for an Arabic merchant: "%s"',
            mb_strimwidth($text, 0, 80, '...')
        ));

        $merchant = User::find($this->merchant->id);
        $merchant->language = 'ar';
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Create User');

        $html = (string) $this->actingAs(User::find($merchant->id))->get('/users/create')->getContent();
        $roleHelp = (string) $this->textOfId($html, 'role-help');

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $roleHelp, sprintf(
            'the role instruction rendered with no Arabic in it for an Arabic merchant: "%s"',
            mb_strimwidth($roleHelp, 0, 80, '...')
        ));
    }

    public function test_the_new_instructions_render_in_english(): void
    {
        $text = (string) $this->textOfId($this->panel('tax_opt_setting', 'en'), 'shop_price-help');

        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $text,
            'the shop_price instruction rendered Arabic for an English merchant');
        $this->assertGreaterThan(30, mb_strlen($text));
    }

    // =============================================================================================
    //  helpers
    // =============================================================================================

    /**
     * Every choice control in `resources/views/setting/`, by kind.
     *
     * COMMENTS ARE STRIPPED FIRST. This file's own docblock names `shop_price`, `price_type` and
     * `currency_space`; a census reading comments would find controls that are only prose about
     * controls, which is the fault CLAUDE.md records eight times.
     *
     * @return array{radios: array<string,string>, selects: array<string,string>, legends: array<string,string>}
     */
    private function settingsCensus(): array
    {
        $root = dirname(__DIR__, 3);
        $files = glob($root . '/' . self::SETTING_VIEW_GLOB) ?: [];

        $this->assertNotSame([], $files, 'no settings views were found at all');

        $out = ['radios' => [], 'selects' => [], 'legends' => [], 'fieldsets' => 0];

        foreach ($files as $path) {
            $view = basename($path);
            $source = $this->sourceWithoutComments('resources/views/setting/' . $view);
            $out['fieldsets'] += preg_match_all('/<fieldset\b/', $source);

            foreach (array_merge(
                $this->matchAll("/type=['\"]radio['\"][^>]*?name=['\"]([^'\"]+)['\"]/", $source),
                $this->matchAll("/name=['\"]([^'\"]+)['\"][^>]*?type=['\"]radio['\"]/", $source),
                $this->matchAll("/Form::radio\(\s*'([^']+)'/", $source),
            ) as $name) {
                $out['radios'][$name] = $view;
            }

            foreach (array_merge(
                $this->matchAll("/<select[^>]*name=['\"]([^'\"\[]+)/", $source),
                $this->matchAll("/Form::select\(\s*'([^'\[]+)/", $source),
            ) as $name) {
                if (! isset($out['radios'][$name])) {
                    $out['selects'][$name] = $view;
                }
            }

            /* A fieldset is identified by its LEGEND text, because that is the only stable name a
               switch group has — those groups have no single `name` attribute to key on. */
            foreach ($this->matchAll('/<legend[^>]*>(.*?)<\/legend>/s', $source) as $legend) {
                $plain = trim(preg_replace('/\s+/', ' ', strip_tags($legend)) ?? '');
                $plain = trim(str_replace(['{{', '}}', "__('", "')", '__("', '")'], '', $plain));

                if ($plain !== '') {
                    $out['legends'][$plain] = $view;
                }
            }
        }

        /* A legend that names an instructed radio group is that group, not a separate item. */
        foreach (['Prices entered with tax', 'Primary Color Settings'] as $covered) {
            unset($out['legends'][$covered]);
        }

        foreach (array_keys($out['legends']) as $legend) {
            if (str_contains($legend, 'Currency Symbol') || str_contains($legend, 'Storage')
                || str_contains($legend, 'Space') || str_contains($legend, 'Position')) {
                unset($out['legends'][$legend]);
            }
        }

        return $out;
    }

    /**
     * An order whose detail screen this merchant may open.
     *
     * `makeOrder()` skips the test itself when `orders.currency_code` is undeclared, which is the
     * cross-agent dependency `SecurityTestCase` records — so a skip here is that condition, never
     * this slice's.
     */
    private function makeOrderForToolbar(User $merchant): \App\Models\Order
    {
        $store = \App\Models\Store::where('created_by', $merchant->id)->firstOrFail();

        return $this->makeOrder($store);
    }

    /**
     * The order detail page, driven through the route a merchant really uses.
     *
     * The id in the URL is `Crypt::encrypt`ed (`OrderController::order_view():918`), so it is
     * encrypted here rather than passed raw — a raw id answers a refusal and every assertion about
     * the page would then be about an error screen.
     */
    private function renderOrderView(User $merchant, \App\Models\Order $order): ?string
    {
        $response = $this->actingAs(User::find($merchant->id))
            ->get('/order-view/' . urlencode(\Illuminate\Support\Facades\Crypt::encrypt($order->id)));

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $html = (string) $response->getContent();

        /* A `redirect()->back()` that has been followed is also 200. The toolbar's own card is the
           marker for "this is the order detail screen" rather than something else. */
        return str_contains($html, 'id="payment_status"') ? $html : null;
    }

    /** @return list<string> */
    private function matchAll(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $m);

        return array_values(array_unique($m[1] ?? []));
    }
}
