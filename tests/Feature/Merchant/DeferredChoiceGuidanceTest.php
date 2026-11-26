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
     * GAP-060 WS5 took this from 5 to 21. Each of the sixteen added here had its behaviour read in
     * `app/` first, and six of them were MEASURED through the real endpoint before the sentence was
     * written, because reading alone would have left the claim at "probably": see
     * `SETTINGS_SELECT_INSTRUCTIONS` and `SETTINGS_GROUP_INSTRUCTIONS` for what each one says and
     * the test that pins it.
     *
     * @var list<string>
     */
    private const SETTINGS_INSTRUCTED = [
        'price_type', 'site_currency_symbol_position', 'site_currency_symbol_name', 'color',
        'shop_price',
        // GAP-060 WS5 — single selects.
        'checkout_price', 'tax_id',
        'currency_format', 'defult_currancy', 'decimal_separator', 'thousand_separator',
        'default_language', 'defult_timezone', 'site_date_format',
        'google_recaptcha_version', 'chat_gpt_model', 'email_setting',
        // GAP-060 WS5 — switch/checkbox groups, by legend, which is the only stable name they have.
        'Sidebar Settings', 'Layout Settings', 'Send an email when', 'Notifications',
    ];

    /**
     * THE REMAINDER, DECLARED WITH A REASON EACH. The reconciliation measured 21 single selects and
     * 5 switch groups uninstructed; sixteen of those twenty-six are instructed now and **TEN
     * REMAIN**, each for a reason that is a property of the CODE or of an owner decision, never of
     * the time available.
     *
     * An entry is NOT a deferral by anybody's decision — it is an open item with a stated reason
     * why it is not in this slice. Moving one out of this list is how it gets closed.
     *
     * THREE REASONS WERE CORRECTED HERE BECAUSE THE MEASUREMENT CONTRADICTED THEM. The old list
     * said `display_tax_option` "needs its call sites read" and `site_time_format` "needs
     * Utility::timeFormat read" — both read as "unfinished homework". Read: `display_tax_option` is
     * written at `TaxOptionController:98` and READ BY NOTHING, and `Utility::timeFormat` DOES NOT
     * EXIST, so neither control has behaviour an instruction could describe truthfully. And
     * `Send a WhatsApp message when` was listed as a plain choice-group job; driven through its own
     * endpoint it answers **HTTP 500** and saves nothing. Those three are not "not yet"; they are
     * "not truthfully possible", which is a different disposition and the tests below say so.
     *
     * @var array<string, string>
     */
    private const SETTINGS_OPEN = [
        // tax_opt_setting — written and read by nothing. MEASURED, not inferred: the only
        // occurrence of `['display_tax_option']` anywhere in `app/` is the assignment at
        // TaxOptionController:98, pinned by test_the_dead_tax_display_setting_is_read_by_nothing.
        'display_tax_option' => 'written at TaxOptionController:98 and read by nothing in app/, so '
            . 'there is no behaviour to describe; an instruction would be a false statement',
        // system_setting — the function the old reason asked to read does not exist.
        'site_time_format' => 'Utility::timeFormat() does not exist and nothing formats a time with '
            . 'this value, so there is no behaviour to describe',
        // otp_setting — four names behind an owner decision that is genuinely in flight.
        'otp_channel' => 'OTP delivery; P01-OTP-LENGTH-COMPATIBILITY is an owner decision in flight',
        'sms_mode' => 'same family as otp_channel',
        'sms_priority' => 'same family as otp_channel',
        'sms_sim' => 'same family as otp_channel',
        // storage_setting — and the group above them is already declared BROKEN by the other test.
        'local_storage_validation' => 'the storage_setting group it belongs to has a recorded defect',
        's3_storage_validation' => 'the storage_setting group it belongs to has a recorded defect',
        'wasabi_storage_validation' => 'the storage_setting group it belongs to has a recorded defect',
        // whatsapp_notify_setting — the switches do not save. MEASURED: see
        // test_the_whatsapp_notification_group_is_silent_because_its_switches_do_not_save.
        'Send a WhatsApp message when' => 'the switches answer HTTP 500 and save nothing; an '
            . 'instruction for a control that does not work is a false statement',
    ];

    /** The two groups the other test declares BROKEN and therefore deliberately silent. */
    private const SETTINGS_BROKEN = ['currency_space', 'storage_setting'];

    /**
     * THE SIXTEEN INSTRUCTIONS GAP-060 WS5 ADDED, and the claim each one is pinned on.
     *
     * `name => [tab, a phrase that must be in the English text]`. The phrase is never a restatement
     * of the label — it is the fact the merchant cannot see from the screen, so if somebody
     * rewrites the sentence into a paraphrase of its own label the assertion fails.
     *
     * WHY `x-ss.field-help` AND NOT `x-ss.choice-group` FOR THESE TWELVE: a `<select>` is ONE
     * control in the accessibility tree, so a fieldset round a lone select is the wrong
     * association rather than a stricter one. The component's own docblock carries that reasoning.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SETTINGS_SELECT_INSTRUCTIONS = [
        // TaxOptionController::taxSettings():101-111 rewrites BOTH shop_price and checkout_price to
        // agree with price_type and replaces the success message. MEASURED: posting
        // inclusive + exclusive + exclusive stored including + including.
        'checkout_price' => ['tax_opt_setting', 'Prices entered with tax'],
        // `tax_options.tax_id` has ZERO readers: every tax calculation uses `products.tax_id`
        // (Tax.php:48 `Tax::find($product_tax_id)`, Product.php:330, ProductController.php:4997).
        'tax_id' => ['tax_opt_setting', 'each individual product'],
        // `number_format($price, $format, …)` — helper.php:1773 and its three siblings. Display only.
        'currency_format' => ['currency_setting', 'decimal places'],
        // StoreCurrencyConversionService::convertStoreCurrentValues():107-152 rewrites products,
        // variants, the store wallet and undelivered COD orders.
        'defult_currancy' => ['currency_setting', 'converts the money'],
        // CurrencySeparators::tokens() moves the THOUSANDS mark on a collision, keeping the decimal.
        'decimal_separator' => ['currency_setting', 'your choice is kept'],
        'thousand_separator' => ['currency_setting', 'the decimal choice wins'],
        // SystemSettings():1208-1224 writes stores.default_language and users.default_language;
        // SetLocate reads users.LANGUAGE. MEASURED: store became 'ar', users.language stayed 'en'.
        'default_language' => ['system_setting', 'does not change the language of this admin panel'],
        // One reader in app/: HomeController:1437-1438, the storefront product page.
        'defult_timezone' => ['system_setting', 'product page'],
        // Utility::dateFormat → formatMomentForLocale → Carbon::translatedFormat.
        'site_date_format' => ['system_setting', 'translated into your own language'],
        // Gated on RECAPTCHA_MODULE; AuthenticatedSessionController:46-57 and its two siblings.
        'google_recaptcha_version' => ['recaptcha_setting', 'Enable reCAPTCHA'],
        // AITemplateController:167 reads it with NO store predicate; :230 splits the endpoint.
        'chat_gpt_model' => ['chat_gpt_setting', 'without a store filter'],
        // index.blade.php:423 rebuilds the fields on `change`; email_fields.blade.php's preset table
        // makes host/port/encryption readonly for every driver but custom and smtp.
        'email_setting' => ['email_setting', 'cannot be edited'],
    ];

    /**
     * The four switch/checkbox groups instructed here: `legend => [tab, hint id, English phrase]`.
     *
     * `x-ss.choice-group`'s id convention (`<name>-group-help`) is kept, but the fieldsets stay
     * INLINE rather than swapping in the component — exactly as `tax_opt_setting` and
     * `currency_setting` already do for their radio groups. That is not a style preference: the
     * census above finds a group by the `<legend>` in the VIEW's own source, so moving the legend
     * into a component would drop `count($census['legends'])` from 5 to 0 and the premise test
     * would report a settings tree with no switch groups in it.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const SETTINGS_GROUP_INSTRUCTIONS = [
        // partision/sidebar.blade.php:64 adds `transprent-bg`; assets/css/style.css:16358 acts on it
        // only inside `@media (min-width: 1025px)`.
        'Sidebar Settings' => ['brand_setting', 'cust_theme_bg-group-help', '1025'],
        // layouts/app.blade.php:86 sets data-ss-theme, :189/:193 swap in the dark stylesheet, and
        // Utility.php:2828 / helper.php:2189 switch the sidebar to logo_light.
        'Layout Settings' => ['brand_setting', 'cust_darklayout-group-help', 'light logo'],
        // EmailTemplateController:169 zeroes EVERY row for the user before re-enabling the posted
        // ones. MEASURED: saving with only A ticked left A at 1 and took B to 0.
        'Send an email when' => ['email_notify_setting', 'email_notify-group-help', 'switches off'],
        // StockSettings():1323 validates `notification` as `required`. MEASURED: posting the two
        // thresholds with no box ticked answered "The notification field is required." and stored
        // neither threshold.
        'Notifications' => ['stock_setting', 'stock_notification-group-help', 'refuses to save'],
    ];

    private User $merchant;

    /** The platform operator, made on demand for the one operator-only panel. */
    private ?User $operator = null;

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

    /**
     * A settings panel in a named language, as the principal that tab actually admits.
     *
     * `chat_gpt_setting` IS OPERATOR-ONLY (`SettingController::settingForm()` answers 403 with
     * `is_success: false` for anyone whose `users.type` is not `super admin`), so driving it as the
     * merchant would assert against an empty panel and pass for the wrong reason. The operator is
     * made once and cached.
     */
    private function panelFor(string $tab, string $language): string
    {
        if ($tab !== 'chat_gpt_setting') {
            return $this->panel($tab, $language);
        }

        $operator = $this->operator ??= User::factory()->create([
            'type' => 'super admin',
            'created_by' => 1,
            'is_active' => 1,
            'email_verified_at' => now(),
            'current_store' => $this->merchant->current_store,
        ]);

        $operator = User::find($operator->id);
        $operator->language = $language;
        $operator->save();

        Cache::forget('admin_details');
        Cache::forget('super_admin_details');

        $response = $this->actingAs(User::find($operator->id))->post('/setting-form', ['tab_type' => $tab]);

        $this->assertSame(200, $response->getStatusCode(),
            "the {$tab} panel did not render for the platform operator");

        $json = json_decode($response->getContent(), true);

        $this->assertTrue((bool) ($json['is_success'] ?? false),
            "the {$tab} panel came back as a refusal: " . ($json['msg'] ?? ''));

        return (string) ($json['data']['content'] ?? '');
    }

    /**
     * The `<fieldset>` that CONTAINS `$id`, or null.
     *
     * Found by walking back from the id to the nearest `<fieldset` and forward to its `</fieldset>`,
     * which is the same shape `SettingsChoiceGroupGuidanceTest::fieldsetFor()` uses — the hint is
     * inside the group it describes, so "the fieldset around the hint" is the group.
     */
    private function fieldsetAroundId(string $html, string $id): ?string
    {
        $at = strpos($html, 'id="' . $id . '"');

        if ($at === false) {
            return null;
        }

        $open = strrpos(substr($html, 0, $at), '<fieldset');

        if ($open === false) {
            return null;
        }

        $close = strpos($html, '</fieldset>', $open);

        return substr($html, $open, ($close === false ? strlen($html) : $close) - $open);
    }

    /**
     * AN ARABIC RENDERING MUST NOT BE CARRYING ENGLISH, and `\p{Arabic}` is NOT that assertion.
     *
     * THIS METHOD EXISTS BECAUSE A MUTATION SURVIVED. The first version of these tests asserted
     * only `assertMatchesRegularExpression('/\p{Arabic}/u', $text)`. The mutation run replaced the
     * OPENING CLAUSE of two Arabic values with their English text — exactly what a partly
     * translated string or a stale key looks like — and both mutants **SURVIVED**, because the rest
     * of the sentence still contained Arabic letters and the check only asked whether ANY Arabic was
     * present. That is the same family of false reading as the English-on-the-Arabic-page defect the
     * previous commit found by driving the real route: a language assertion that cannot fail is not
     * a language assertion.
     *
     * THE RULE IS MEASURED, not guessed. Counting the longest run of consecutive Latin-script words
     * across the sixteen Arabic sentences this slice adds: the worst legitimate run is **1** token —
     * `reCAPTCHA`, `Twilio`, `GPT-3.5-Turbo-Instruct`, `Custom`, `SMTP`, `v2`, `v3` all stand alone
     * between Arabic words. The English sentences run **44 to 55**. So a threshold of 3 is true of
     * every correct Arabic string here with room to spare, and false of any English leak longer than
     * a product name.
     *
     * Re-run after this change: both mutants are CAUGHT.
     */
    private function assertArabicIsNotLeakingEnglish(string $text, string $what): void
    {
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, sprintf(
            '%s rendered with no Arabic in it at all for an Arabic merchant: "%s"',
            $what, mb_strimwidth($text, 0, 90, '...')
        ));

        $longest = 0;
        $run = 0;
        $worst = '';
        $current = [];

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $token) {
            $isLatinWord = preg_match('/[A-Za-z]/', $token) === 1
                && preg_match('/\p{Arabic}/u', $token) !== 1;

            if ($isLatinWord) {
                $run++;
                $current[] = $token;

                if ($run > $longest) {
                    $longest = $run;
                    $worst = implode(' ', $current);
                }
            } else {
                $run = 0;
                $current = [];
            }
        }

        $this->assertLessThan(3, $longest, sprintf(
            '%s is carrying a run of %d consecutive Latin words on the Arabic page ("%s"), which is '
            . 'what an undeclared or half-translated key looks like. The longest legitimate run in '
            . 'this slice is ONE token — a product or service name between Arabic words. Rendered: '
            . '"%s"',
            $what, $longest, mb_strimwidth($worst, 0, 70, '...'), mb_strimwidth($text, 0, 120, '...')
        ));
    }

    /** @return list<string> every `.php` file under `app/`, relative to the project root. */
    private function phpFilesUnderApp(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        sort($files);

        $this->assertNotSame([], $files, 'no PHP files were found under app/ at all');

        return $files;
    }

    /**
     * The panel's TEXT, with markup removed.
     *
     * Tags are stripped so that a label cannot be satisfied by an `id`, a class, a `name` or a
     * `data-` attribute that happens to be spelled like it — which `str_contains($html, 'SMTP')`
     * would accept from `name="smtp_host"`. Entities are decoded because `Form::select` escapes its
     * option text, so an apostrophe or an ampersand in a label arrives as `&#039;`/`&amp;`.
     */
    private function visibleText(string $html): string
    {
        $withoutScripts = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html) ?? $html;

        return (string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($withoutScripts), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
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
     *
     * **26 UNTIL GAP-060 WS5, TEN NOW**, and the sum is asserted rather than just the remainder:
     * 16 instructed + 10 open is the same 26 items, so lowering the remainder by deleting a row
     * instead of instructing it fails here.
     */
    public function test_the_settings_remainder_is_exactly_ten_items(): void
    {
        $this->assertCount(10, self::SETTINGS_OPEN,
            'the declared settings remainder changed size. If an item was closed, its assertion '
            . 'belongs in this file; if one was added, say why it is open.');

        $this->assertCount(12, self::SETTINGS_SELECT_INSTRUCTIONS,
            'the twelve single selects GAP-060 WS5 instructed are no longer twelve');
        $this->assertCount(4, self::SETTINGS_GROUP_INSTRUCTIONS,
            'the four switch groups GAP-060 WS5 instructed are no longer four');

        $this->assertSame(
            26,
            count(self::SETTINGS_OPEN)
                + count(self::SETTINGS_SELECT_INSTRUCTIONS)
                + count(self::SETTINGS_GROUP_INSTRUCTIONS),
            'the reconciliation counted 26 uninstructed choice controls in resources/views/setting/. '
            . 'Instructed + open must still come to 26: if it does not, an item was dropped from the '
            . 'ledger rather than closed, which is the one way this census can be made to read green '
            . 'without any work being done.'
        );
    }

    /** Everything this file instructs is also in the census's own INSTRUCTED list. */
    public function test_the_instructed_lists_cannot_drift_from_the_census(): void
    {
        foreach (array_keys(self::SETTINGS_SELECT_INSTRUCTIONS) as $name) {
            $this->assertContains($name, self::SETTINGS_INSTRUCTED,
                "'{$name}' is instructed by this file and missing from SETTINGS_INSTRUCTED, so the "
                . 'census would report it as unaccounted for.');
        }

        foreach (array_keys(self::SETTINGS_GROUP_INSTRUCTIONS) as $legend) {
            $this->assertContains($legend, self::SETTINGS_INSTRUCTED,
                "the '{$legend}' group is instructed by this file and missing from "
                . 'SETTINGS_INSTRUCTED.');
        }

        foreach (array_keys(self::SETTINGS_OPEN) as $name) {
            $this->assertNotContains($name, self::SETTINGS_INSTRUCTED, sprintf(
                "'%s' is declared OPEN and also listed as instructed. One of the two is wrong, and "
                . 'an item in both lists is counted twice by the reconciliation.',
                $name
            ));
        }
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
    //  GAP-060 WS5 — the sixteen remaining settings controls, in both languages
    // =============================================================================================

    /**
     * SOURCE PRESENCE IS NOT DELIVERY AND A SOURCE ASSERTION IS NOT A LANGUAGE ASSERTION — both
     * lessons are paid for in this file already. The order-toolbar sentence passed every source
     * check in the commit before this one while rendering in ENGLISH on the Arabic page, because
     * the key had not been declared; only a test that drives the real route in both languages
     * caught it. So every instruction added here is asserted through `POST /setting-form`, the
     * endpoint the tab really arrives by, once per language.
     *
     * @dataProvider instructedSettingsSelects
     */
    public function test_each_instructed_settings_select_is_described_by_its_own_instruction(
        string $name,
        string $tab,
        string $phrase
    ): void {
        foreach (['en', 'ar'] as $language) {
            $html = $this->panelFor($tab, $language);

            $this->assertMatchesRegularExpression(
                '/<select[^>]*\bid="' . preg_quote($name, '/') . '"[^>]*\baria-describedby="'
                    . preg_quote($name, '/') . '-help"/',
                $html,
                "the `{$name}` select on {$tab} does not reference its own instruction in "
                . "'{$language}'. The component owns the id; the caller owns the association, and "
                . 'an orphan hint is decoration.'
            );

            $text = $this->textOfId($html, $name . '-help');

            $this->assertNotNull($text, sprintf(
                'the `%s` select describes itself by an id that is on no element in the %s panel. '
                . 'A dangling reference is worse than silence: a reader announces neither.',
                $name, $tab
            ));

            $this->assertGreaterThan(40, mb_strlen((string) $text), sprintf(
                "the `%s` instruction is %d characters (\"%s\"), which is a label rather than an "
                . 'instruction. I5 asks what the control DOES.',
                $name, mb_strlen((string) $text), $text
            ));

            if ($language === 'ar') {
                $this->assertArabicIsNotLeakingEnglish((string) $text, "the `{$name}` instruction");
            } else {
                $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', (string) $text,
                    "the `{$name}` instruction rendered Arabic for an English merchant");
                $this->assertStringContainsString($phrase, (string) $text, sprintf(
                    'the `%s` instruction no longer says the thing it was written for. The phrase '
                    . '"%s" is the fact a merchant cannot see from this screen; without it the '
                    . 'sentence is a restatement of the label, which this convention exists to '
                    . 'rule out. Rendered: "%s"',
                    $name, $phrase, $text
                ));
            }
        }
    }

    public static function instructedSettingsSelects(): array
    {
        $cases = [];

        foreach (self::SETTINGS_SELECT_INSTRUCTIONS as $name => [$tab, $phrase]) {
            $cases[$name] = [$name, $tab, $phrase];
        }

        return $cases;
    }

    /**
     * The four switch/checkbox groups, described on the FIELDSET.
     *
     * `aria-describedby` on the fieldset and not on one switch: the thing being described is the
     * SET, and a description hung off one toggle is announced only when a reader happens to land on
     * that toggle. These panels hold a dozen toggles each, so that difference is the whole point.
     *
     * @dataProvider instructedSettingsGroups
     */
    public function test_each_instructed_switch_group_is_described_on_its_fieldset(
        string $legend,
        string $tab,
        string $hintId,
        string $phrase
    ): void {
        foreach (['en', 'ar'] as $language) {
            $html = $this->panelFor($tab, $language);

            $fieldset = $this->fieldsetAroundId($html, $hintId);

            $this->assertNotNull($fieldset, sprintf(
                "the '%s' group's hint id '%s' is not inside a <fieldset> on the %s panel in '%s', "
                . 'so the instruction is not bound to the group at all',
                $legend, $hintId, $tab, $language
            ));

            $this->assertStringContainsString('aria-describedby="' . $hintId . '"', (string) $fieldset,
                sprintf(
                    "the '%s' fieldset does not reference '%s', so its instruction is announced only "
                    . 'if a reader happens to land on the element it sits beside',
                    $legend, $hintId
                ));

            $text = (string) $this->textOfId($html, $hintId);

            $this->assertGreaterThan(40, mb_strlen($text), sprintf(
                "the '%s' group's instruction is %d characters (\"%s\")", $legend, mb_strlen($text), $text
            ));

            if ($language === 'ar') {
                $this->assertArabicIsNotLeakingEnglish($text, "the '{$legend}' group's instruction");
            } else {
                $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $text,
                    "the '{$legend}' group's instruction rendered Arabic for an English merchant");
                $this->assertStringContainsString($phrase, $text, sprintf(
                    "the '%s' group's instruction no longer says \"%s\", which is the measured fact "
                    . 'it was written for. Rendered: "%s"',
                    $legend, $phrase, $text
                ));
            }
        }
    }

    public static function instructedSettingsGroups(): array
    {
        $cases = [];

        foreach (self::SETTINGS_GROUP_INSTRUCTIONS as $legend => [$tab, $hintId, $phrase]) {
            $cases[$legend] = [$legend, $tab, $hintId, $phrase];
        }

        return $cases;
    }

    /**
     * EVERY CONTROL AN INSTRUCTION NAMES IS NAMED BY A LABEL IT REALLY HAS — IN BOTH LANGUAGES.
     *
     * THIS IS THE ASSERTION THE PREVIOUS SLICE PAID FOR. Its draft told the merchant to set a
     * paired control to "Inclusive", which is not what the radio says; an instruction naming a
     * control by a label it does not have is worse than no instruction, because the merchant now
     * hunts for something that is not there. That was caught by a human re-reading the draft, which
     * is not a method. This is the method.
     *
     * AND IT HAS TO BE CHECKED PER LANGUAGE, not once. A quoted label is translated independently
     * of the sentence that quotes it, so the English sentence can quote the English label correctly
     * while the Arabic sentence quotes a string that is not on the Arabic page at all. Both are
     * driven through the real tab endpoint, and the quoted text must appear in the rendered panel's
     * own text — not in its markup, so an `id` or a class spelled like the label cannot satisfy it.
     *
     * THREE QUOTATIONS POINT AT A DIFFERENT PANEL ON PURPOSE and are declared with it: the stock
     * notification instruction names the Twilio toggle and the panel it lives on, because that is
     * where a merchant has to go.
     */
    public function test_every_label_an_instruction_quotes_is_a_label_that_really_exists(): void
    {
        /* hint id => [the panel it is on, [the panel the label lives on => [en fragment, ar fragment], …]] */
        $quotations = [
            'checkout_price-help' => ['tax_opt_setting', [
                'tax_opt_setting' => [
                    ['Prices entered with tax', 'الأسعار المسجلة مع الضريبة'],
                    ['Including Tax', 'بما في ذلك الضرائب'],
                    ['Display prices in the shop', 'عرض الأسعار في المتجر'],
                ],
            ]],
            'defult_timezone-help' => ['system_setting', [
                'system_setting' => [['Date Format', 'تنسيق التاريخ']],
            ]],
            'google_recaptcha_version-help' => ['recaptcha_setting', [
                'recaptcha_setting' => [['Enable reCAPTCHA', 'تفعيل reCAPTCHA']],
            ]],
            'chat_gpt_model-help' => ['chat_gpt_setting', [
                'chat_gpt_setting' => [['GPT-3.5-Turbo-Instruct', 'GPT-3.5-Turbo-Instruct']],
            ]],
            'email_setting-help' => ['email_setting', [
                'email_setting' => [
                    ['Custom', 'Custom'],
                    ['SMTP', 'SMTP'],
                    ['Save Changes', 'احفظ التغييرات'],
                ],
            ]],
            'cust_theme_bg-group-help' => ['brand_setting', [
                'brand_setting' => [['Transparent layout', 'تخطيط شفاف']],
            ]],
            'cust_darklayout-group-help' => ['brand_setting', [
                'brand_setting' => [['Dark Layout', 'تخطيط مظلم']],
            ]],
            'stock_notification-group-help' => ['stock_setting', [
                /* Deliberately another panel: the toggle the merchant must go and find. */
                'twilio_setting' => [
                    ['Enable Twilio', 'تفعيل Twilio'],
                    ['Twilio Settings', 'إعدادات Twilio'],
                ],
            ]],
        ];

        $checked = 0;

        foreach (['en' => 0, 'ar' => 1] as $language => $index) {
            foreach ($quotations as $hintId => [$hintTab, $byPanel]) {
                $text = (string) $this->textOfId($this->panelFor($hintTab, $language), $hintId);

                $this->assertNotSame('', $text,
                    "the instruction '{$hintId}' did not render on {$hintTab} in '{$language}'");

                foreach ($byPanel as $labelTab => $labels) {
                    $labelText = $this->visibleText($this->panelFor($labelTab, $language));

                    foreach ($labels as $pair) {
                        $quoted = $pair[$index];
                        $checked++;

                        $this->assertStringContainsString($quoted, $text, sprintf(
                            "the '%s' instruction no longer quotes \"%s\" in '%s'. If the wording "
                            . 'changed, the new quotation needs its own row here; the point of this '
                            . 'table is that a quoted label cannot drift silently.',
                            $hintId, $quoted, $language
                        ));

                        $this->assertStringContainsString($quoted, $labelText, sprintf(
                            "the '%s' instruction quotes \"%s\" in '%s', and NO CONTROL ON THE %s "
                            . 'PANEL IS LABELLED THAT in that language. An instruction naming a '
                            . 'control by a label it does not have is worse than no instruction: '
                            . 'the merchant goes looking for something that is not on the screen. '
                            . 'This is the exact defect the previous slice caught in its own draft '
                            . '("Inclusive", which is not what the radio says).',
                            $hintId, $quoted, $language, $labelTab
                        ));
                    }
                }
            }
        }

        $this->assertSame(26, $checked,
            'the number of quoted labels verified changed. Every quotation an instruction makes '
            . 'belongs in this table, in both languages.');
    }

    /**
     * EVERY SETTINGS LEGEND AND HINT IS PRESENT IN BOTH LANGUAGES AND NEITHER DANGLES, on every
     * panel this slice touches — not only the two the first version of this file checked.
     */
    public function test_no_settings_instruction_dangles_or_is_orphaned_on_any_touched_panel(): void
    {
        $tabs = array_unique(array_merge(
            array_column(array_values(self::SETTINGS_SELECT_INSTRUCTIONS), 0),
            array_column(array_values(self::SETTINGS_GROUP_INSTRUCTIONS), 0),
        ));

        sort($tabs);

        foreach ($tabs as $tab) {
            foreach (['en', 'ar'] as $language) {
                $html = $this->panelFor($tab, $language);

                preg_match_all('/aria-describedby="([^"]+)"/', $html, $refs);

                foreach ($refs[1] as $reference) {
                    foreach (preg_split('/\s+/', trim($reference)) ?: [] as $id) {
                        if ($id === '') {
                            continue;
                        }

                        $this->assertStringContainsString('id="' . $id . '"', $html,
                            "{$tab} ({$language}): aria-describedby names '{$id}', which is on no "
                            . 'element in this panel');
                    }
                }

                preg_match_all('/id="([^"]*-help)"/', $html, $hints);

                foreach ($hints[1] as $id) {
                    $this->assertStringContainsString('aria-describedby="' . $id . '"', $html,
                        "{$tab} ({$language}): the instruction '{$id}' is referenced by nothing, so "
                        . 'it is decoration rather than help');
                }
            }
        }
    }

    /**
     * THE DECIMAL FORMAT SELECT MUST REPORT THE FORMAT THE STORE IS ACTUALLY USING.
     *
     * Found while reading the code in order to write that control's instruction, which is the only
     * reason it was found at all: `@selected(($setting['currency_format'] ?? '') === $ssValue)`
     * compared a STRING with an INT, because PHP casts the integer-like keys of
     * `['0' => '1', '1' => '1.0', …]` to integers. `'2' === 2` is false for every option, so the
     * browser fell back to the first one and the screen reported ZERO decimal places whatever the
     * store had saved.
     *
     * AND IT WAS NOT ONLY A DISPLAY LIE. `SettingController::currencySettings()` writes every posted
     * name, so pressing Save on this panel for any reason stored `currency_format = 0` and every
     * price lost its minor units. Exactly the shape of the D-42.3 separator defect this same file
     * already carries a comment about — one `?? ''` short of reporting the opposite of the
     * behaviour.
     *
     * MEASURED BOTH WAYS before the fix: with `currency_format = '2'` stored the panel came back
     * with `selected` on no option at all, while `decimal_separator = 'comma'` four lines below —
     * compared with a string — came back correctly marked. That control is the positive control
     * that makes this a type bug rather than a broken panel.
     */
    public function test_the_decimal_format_select_marks_the_format_the_store_has_saved(): void
    {
        \App\Models\Setting::putForStore(
            'currency_format', '2', $this->merchant->current_store, $this->merchant->id
        );
        Cache::flush();

        $html = $this->panel('currency_setting');

        preg_match('/<select[^>]*id="currency_format".*?<\/select>/s', $html, $m);

        $this->assertNotEmpty($m[0] ?? '', 'the Decimal Format select is not on the currency panel');

        $this->assertMatchesRegularExpression(
            '/<option value="2"[^>]*\bselected\b/',
            $m[0],
            'with `currency_format` = 2 stored, the Decimal Format select does not mark 1.00. The '
            . 'browser then shows the FIRST option (1, i.e. no decimals) and the screen reports the '
            . 'opposite of what the four money formatters do — and because currencySettings() '
            . 'writes every posted name, the next Save on this panel stores 0 and every price loses '
            . 'its minor units.'
        );

        $this->assertSame(1, preg_match_all('/<option[^>]*\bselected\b/', $m[0]),
            'more than one Decimal Format option is marked selected, which is the defect the '
            . 'currency_space pair had: the browser keeps the last one and the screen reports a '
            . 'format nobody chose.');
    }

    /**
     * AND THE CAST ALONE WAS NOT THE WHOLE FIX — an independent review of the first cut said so,
     * and this is the case it asked for.
     *
     * `(string) (… ?? '') === (string) $ssValue` marks exactly one option for the five VALID values
     * and NONE for an absent row, a stored empty string, or anything else. In those states the
     * browser pre-selects `value="0"` and the next Save writes 0 — the very outcome the fix existed
     * to remove, so the fix was only as good as the stored value being one of five.
     *
     * AND THOSE STATES ARE REACHABLE, which is why this is a test and not a note.
     * `Api/DashboardController.php:11551` validates this name as `nullable|string|max:50` and
     * `:11657-11662` writes it with `updateOrCreate`, so the SHIPPED FLUTTER APP can put '' or any
     * fifty-character string in the row. And the two defaults disagree: `defaultSetting()` seeds
     * `"1"` (helper.php:1602) while the formatters default an absent row to 2
     * (helper.php:1758-1762), so a store with no row prints two decimals while the panel shows one.
     *
     * THE FALLBACK IS THE FORMATTERS' OWN, NOT A NUMBER CHOSEN HERE. Anything that is not one of
     * the five is treated as absent and the select marks `2`, because the screen must not be able
     * to report something the price does not do.
     *
     * @dataProvider unusableStoredDecimalFormats
     */
    public function test_the_decimal_format_select_still_marks_one_option_for_an_unusable_stored_value(
        ?string $stored,
        string $expected
    ): void {
        if ($stored !== null) {
            \App\Models\Setting::putForStore(
                'currency_format', $stored, $this->merchant->current_store, $this->merchant->id
            );
        }

        Cache::flush();

        $html = $this->panel('currency_setting');

        preg_match('/<select[^>]*id="currency_format".*?<\/select>/s', $html, $m);

        $this->assertNotEmpty($m[0] ?? '', 'the Decimal Format select is not on the currency panel');

        $this->assertSame(1, preg_match_all('/<option[^>]*\bselected\b/', $m[0]), sprintf(
            'with %s stored, %d options are marked selected. With NONE marked the browser '
            . 'pre-selects the first (value="0", no decimals) and the next Save on this panel '
            . 'stores 0, stripping the minor units off every price — which is the defect this '
            . 'control was fixed for, reachable again through the Flutter settings writer.',
            $stored === null ? 'no row at all' : "'" . $stored . "'",
            preg_match_all('/<option[^>]*\bselected\b/', $m[0])
        ));

        $this->assertMatchesRegularExpression(
            '/<option value="' . preg_quote($expected, '/') . '"[^>]*\bselected\b/',
            $m[0],
            sprintf(
                'with %s stored, the marked option is not %s. The fallback must be the value the '
                . 'FORMATTERS use for an absent row (helper.php:1758-1762), so the screen cannot '
                . 'report a format the price does not use.',
                $stored === null ? 'no row at all' : "'" . $stored . "'",
                $expected
            )
        );
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function unusableStoredDecimalFormats(): array
    {
        return [
            'no row at all' => [null, '2'],
            'an empty string, which the Flutter writer accepts' => ['', '2'],
            'a space' => [' ', '2'],
            'out of range' => ['9', '2'],
            'non-numeric' => ['auto', '2'],
            /* The controls: a valid value must still win over the fallback. */
            'a valid 0' => ['0', '0'],
            'a valid 4' => ['4', '4'],
        ];
    }

    /**
     * THE REAL PAGE, IN ALL FOUR COMBINATIONS — and the capture the browser harness measures.
     *
     * THIS IS A TEST THAT ALWAYS RUNS, not a conditionally-skipped one: a skip would break the
     * declared outcome set (CLAUDE.md §3). It asserts the four renderings really are four different
     * renderings, which is the premise every contrast measurement rests on, and it additionally
     * WRITES them when `SS_SETTINGS_HTML_DIR` is set — the same arrangement
     * `check-attribute-option-keyboard-live.cjs` uses.
     *
     * WHY THE WHOLE PAGE AND NOT THE PANEL FRAGMENT. The previous GAP-060 harness produced two
     * false readings, both from an under-built page: it loaded 2 stylesheets where the layout loads
     * **30** (counted in `layouts/app.blade.php`), and it omitted a wrapper that declares a custom
     * property, which scored labels against the wrong backdrop at 1.09:1. So this takes the REAL
     * `GET /setting` document — its chrome, its thirty links, its `dir`, its brand colour block —
     * and splices the AJAX panel into the container the browser would put it in.
     *
     * AND THE ADMIN'S DARK MODE IS A STYLESHEET SWAP, NOT `prefers-color-scheme`
     * (`layouts/app.blade.php:86`, `:189`, `:193`), so emulating a colour scheme would yield two
     * identical light pages. The dark rendering is produced by storing `cust_darklayout = on`,
     * which is the only thing that produces it — which is also why the two brand switch groups this
     * slice instructs are worth instructing.
     */
    public function test_the_touched_settings_panels_render_in_all_four_combinations(): void
    {
        $dir = getenv('SS_SETTINGS_HTML_DIR') ?: null;

        if ($dir !== null && ! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $tabs = array_unique(array_merge(
            array_column(array_values(self::SETTINGS_SELECT_INSTRUCTIONS), 0),
            array_column(array_values(self::SETTINGS_GROUP_INSTRUCTIONS), 0),
        ));
        sort($tabs);

        $seen = [];

        foreach (['en', 'ar'] as $language) {
            foreach (['light' => 'off', 'dark' => 'on'] as $theme => $dark) {
                \App\Models\Setting::putForStore(
                    'cust_darklayout', $dark, $this->merchant->current_store, $this->merchant->id
                );
                Cache::flush();

                $merchant = User::find($this->merchant->id);
                $merchant->language = $language;
                $merchant->save();

                $page = $this->actingAs(User::find($this->merchant->id))->get('/setting');

                $this->assertSame(200, $page->getStatusCode(),
                    "GET /setting did not render for a '{$language}' merchant in {$theme}");

                $chrome = (string) $page->getContent();

                /* The premise: the four really are four. A capture harness that measured the same
                   light LTR document four times would report a clean sweep and prove nothing. */
                $this->assertSame(
                    $theme === 'dark',
                    str_contains($chrome, 'data-ss-theme="dark"'),
                    "the {$theme} rendering " . ($theme === 'dark' ? 'lacks' : 'carries')
                    . ' the dark theme attribute, so the two themes are not two renderings'
                );

                /* SCOPED TO `<link>` ELEMENTS, because the layout's own theme-toggle script names
                   `style-dark.css` in a regex literal (`layouts/app.blade.php:306`) and a document
                   search therefore matches the LIGHT page too. Measured: the first version of this
                   assertion failed on the light rendering for exactly that reason, which is the
                   same "a search over the whole file answers a different question" fault CLAUDE.md
                   §3 records for stylesheets. */
                preg_match_all('/<link[^>]+href="([^"]+)"[^>]*>/', $chrome, $links);
                $darkSheets = array_values(array_filter(
                    $links[1] ?? [],
                    static fn ($href) => (bool) preg_match('/(rtl-)?style-dark\.css/', $href)
                ));

                $this->assertSame(
                    $theme === 'dark',
                    $darkSheets !== [],
                    "the {$theme} rendering " . ($theme === 'dark' ? 'does not load' : 'loads')
                    . ' the stylesheet its theme is made of (links found: '
                    . implode(', ', $darkSheets) . ')'
                );

                if ($theme === 'dark') {
                    $this->assertSame(
                        $language === 'ar',
                        str_contains($darkSheets[0], 'rtl-style-dark.css'),
                        'the dark stylesheet does not match the direction. The RTL and LTR dark '
                        . 'files are MIRRORED copies, so a rule written in only one of them reaches '
                        . 'half the merchants — the defect the previous GAP-060 slice closed.'
                    );
                }

                $this->assertGreaterThanOrEqual(20, preg_match_all('/<link[^>]+rel="stylesheet"/', $chrome),
                    'the captured page carries far fewer stylesheets than the layout declares, so a '
                    . 'colour measured on it would be measured against the wrong cascade — the exact '
                    . 'false reading the previous harness produced');

                $seen[$language . '-' . $theme] = md5($chrome);

                foreach ($tabs as $tab) {
                    $panel = $this->panelFor($tab, $language);

                    if ($dir === null) {
                        continue;
                    }

                    $spliced = preg_replace(
                        '/(<div class="tab-content" id="pills-tabContent"[^>]*>)/',
                        '$1' . "\n" . $panel,
                        $chrome,
                        1
                    );

                    $this->assertNotSame($chrome, $spliced,
                        'the panel container was not found in the captured page, so the harness '
                        . 'would measure chrome with no panel in it');

                    file_put_contents(
                        rtrim($dir, '/') . "/settings-{$tab}-{$language}-{$theme}.html",
                        $spliced
                    );
                }
            }
        }

        $this->assertCount(4, array_unique($seen),
            'two of the four renderings are byte-identical, so the combination they claim to cover '
            . 'is not actually being produced');
    }

    // =============================================================================================
    //  THE THREE THAT STAY SILENT, and WHY — executable, not prose
    // =============================================================================================

    /**
     * `display_tax_option` IS WRITTEN AND READ BY NOTHING, so it gets no instruction.
     *
     * The old census reason said "needs its call sites read; may interact with the pair above".
     * Read: `TaxOptionController:98` assigns it and no file under `app/` ever reads it back —
     * the cart, the product page, the POS and the tax calculation all read `price_type` and
     * `shop_price` and none of them mentions this name. An instruction describing what "As a single
     * total" versus "Itemized" does would be invented, and this project has already paid for one of
     * those in this very file.
     *
     * ASSERTED BY SHAPE RATHER THAN BY COUNT: every occurrence of `['display_tax_option']` under
     * `app/` must be an ASSIGNMENT. The moment somebody adds a reader, this test fails and tells
     * them the control can now be instructed.
     */
    public function test_the_dead_tax_display_setting_is_read_by_nothing_and_so_says_nothing(): void
    {
        $html = $this->panel('tax_opt_setting');

        $this->assertMatchesRegularExpression('/<select[^>]*id="display_tax_option"/', $html,
            'the control this test is about is not on the panel, so the assertion would be vacuous');

        $this->assertDoesNotMatchRegularExpression(
            '/<select[^>]*id="display_tax_option"[^>]*aria-describedby/',
            $html,
            'display_tax_option has been given an instruction. That is only correct if something '
            . 'now READS the value — if it does, write the sentence and move the name out of '
            . 'SETTINGS_OPEN; if it does not, the instruction describes behaviour that does not '
            . 'happen.'
        );

        $reads = [];

        foreach ($this->phpFilesUnderApp() as $path) {
            $source = $this->sourceWithoutComments($path);

            if (preg_match_all('/\[\s*([\'"])display_tax_option\1\s*\](\s*=(?!=))?/', $source, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    if (trim($hit[2] ?? '') === '') {
                        $reads[] = $path;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($reads)), sprintf(
            "`display_tax_option` is now READ in %s. It was written and never read, which is why it "
            . 'carries no instruction; if it has a behaviour now, instruct it.',
            implode(', ', array_unique($reads))
        ));
    }

    /**
     * `site_time_format` HAS NO FORMATTER AT ALL, so it gets no instruction.
     *
     * The old reason said "needs Utility::timeFormat read". That method DOES NOT EXIST — grepped
     * for `function timeFormat` across `app/`, `resources/views/` and `packages/workdo/` and there
     * is none, and the only other mentions of the name are a default in `Utility::$defaults`, the
     * seeder, and `defaultSetting()`'s bag, all of which WRITE it. Its sibling `site_date_format`
     * has `Utility::dateFormat()` and 34 call sites, which is exactly why that one IS instructed
     * here and this one is not.
     */
    public function test_the_time_format_setting_has_no_formatter_and_so_says_nothing(): void
    {
        $this->assertFalse(method_exists(\App\Models\Utility::class, 'timeFormat'), sprintf(
            'Utility::timeFormat() now exists. That is the condition this item was open on: read '
            . 'it, write the sentence, and move `site_time_format` out of SETTINGS_OPEN. (Its '
            . 'sibling Utility::dateFormat() exists and `site_date_format` is instructed.)'
        ));

        $html = $this->panel('system_setting');

        $this->assertMatchesRegularExpression('/<select[^>]*id="site_time_format"/', $html,
            'the control this test is about is not on the panel');
        $this->assertDoesNotMatchRegularExpression(
            '/<select[^>]*id="site_time_format"[^>]*aria-describedby/',
            $html,
            'site_time_format has been given an instruction while nothing formats a time with it.'
        );
    }

    /**
     * THE WHATSAPP NOTIFICATION GROUP IS SILENT BECAUSE ITS SWITCHES DO NOT SAVE — measured here,
     * not asserted from reading.
     *
     * `whatsapp_notify_setting.blade.php:55` gives each switch `id="whatsapp-notify-<row id>"`, and
     * `setting/index.blade.php:558-560` sends THAT ID as `notification_id` to
     * `update-whatsapp-notification`, whose handler
     * (`SettingController::whatsapp_notification():1407`) does
     * `WhatsappMessage::where('user_id', …)->where('id', $request->notification_id)` — an integer
     * key compared with `whatsapp-notify-1`. No row matches, `$WhatsappMessage` is null, and
     * `->is_active =` on null is fatal.
     *
     * MEASURED through the real endpoint with the id the page really renders: HTTP **500**, and the
     * row's `is_active` unchanged. The same request with the bare numeric id answers 200 and saves,
     * which is the control that makes this a contract mismatch rather than a missing fixture.
     *
     * The Save Changes button does not rescue it either: `whatsapp_notification_setting()` builds
     * `$post` from `whatsapp_phone_number_id` and `whatsapp_access_token` only and never looks at
     * the toggles.
     *
     * SO THE GROUP STAYS WITHOUT AN INSTRUCTION, for the same reason `currency_space` and
     * `storage_setting` do in `SettingsChoiceGroupGuidanceTest`. The fix is not this slice's: the
     * handler is in `SettingController` and carries a SECOND mismatch — it scopes by `user_id`
     * while the panel lists by `store_id` — so a JS-only repair would make the group work for some
     * rows and not others, and an instruction true only sometimes is the thing this file refuses.
     */
    public function test_the_whatsapp_notification_group_is_silent_because_its_switches_do_not_save(): void
    {
        $store = \App\Models\Store::where('created_by', $this->merchant->id)->firstOrFail();

        $row = \App\Models\WhatsappMessage::create([
            'name' => 'Order Placed',
            'from' => 'order',
            'user_id' => $this->merchant->id,
            'is_active' => 0,
            'store_id' => $store->id,
            'created_by' => $this->merchant->id,
        ]);

        $html = $this->panel('whatsapp_notify_setting');

        preg_match_all('/id="(whatsapp-notify-[^"]+)"/', $html, $ids);

        $this->assertNotEmpty($ids[1],
            'the WhatsApp notification panel rendered no switch, so this measurement would be '
            . 'vacuous rather than passing');

        $rendered = $ids[1][0];

        $this->assertNotSame((string) $row->id, $rendered,
            'the rendered switch id is now the bare row id, which is what the handler compares '
            . 'against. If that is deliberate, re-measure: the group may save now, and if it does, '
            . 'instruct it and move it out of SETTINGS_OPEN.');

        $response = $this->actingAs(User::find($this->merchant->id))
            ->get('/update-whatsapp-notification?status=1&notification_id=' . urlencode($rendered));

        $this->assertSame(500, $response->getStatusCode(), sprintf(
            'the WhatsApp notification toggle no longer answers 500 for the id the page renders '
            . '(got %d). If the handler was fixed, write the instruction and close the item.',
            $response->getStatusCode()
        ));

        $this->assertSame(
            0,
            (int) \App\Models\WhatsappMessage::withoutGlobalScopes()->find($row->id)->is_active,
            'the toggle saved after all, so the group can be instructed'
        );

        $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*aria-describedby/', $html,
            "the 'Send a WhatsApp message when' group has been given an instruction while its "
            . 'switches answer 500 and save nothing.');
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
