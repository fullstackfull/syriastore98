<?php

namespace Tests\Feature\Merchant;

use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F-validation-errors-lose-the-modal, THE SETTINGS GROUP (9 refusal sites in one file).
 * =================================================================================================
 *
 * The same one-expression repair `CategoryController`,
 * `ValidationRefusalNamesEveryFieldTest` and the content/commerce groups landed:
 *
 *     -  $messages->first()
 *     +  implode(' ', $messages->all())
 *
 * applied to the nine reachable, in-scope refusal sites on `SettingController`. Same flash key,
 * same 302, same JSON shape for the one AJAX site. No validator rule touched, no `fails()` guard
 * created, no refusal target changed.
 *
 * `SettingController:585` is the highest-value site in the whole 213-site census, and the figure
 * was MEASURED by running its rule set: **a first-time email setup produces EIGHT messages and the
 * merchant was shown ONE**, so seven reasons their SMTP config was rejected were discarded. With a
 * password already stored the conditional `secretRule` relaxes and the bag is seven; either way
 * the merchant saw one.
 *
 * ------------------------------------------------------------------------------------------------
 * EVERY BAG SIZE WAS COUNTED BY RUNNING `Validator::make`, NOT BY COUNTING RULE KEYS -- the
 * distinction that made the original census wrong three times. Seven of the nine are OBSERVABLE:
 *
 *   saveEmailSettings             :585   8   (7 with a password already stored)
 *   testSendMail                  :692   8   -- JSON, not a flash; see below
 *   WoocommerceSettings           :1092  2
 *   StockSettings                 :1319  3
 *   whatsapp_notification_setting :1432  2
 *   testSendwhatsappmassage       :1511  2
 *   TwilioSettings                :1667  2
 *
 *  *   shopifySettings               :1160  2   CORRECTED -- first measured as 1 by a parse that
 *                                            stopped at the P1-SEC-06 comment inside the rule
 *                                            array. Driven, it is 2; see inertSites().
 *
 * and ONE IS INERT, carrying exactly one field, so the bag can never hold a second message and the
 * join is byte-identical to `first()` for every possible request:
 *
 *   WhatsappSettings              :1362  1   one field with two rules -- `required` fires on an
 *                                            absent value and `regex` on a present invalid one,
 *                                            never both, so the bag is at most one
 *
 * The inert site still takes the expression, because it is the one that stays correct the moment a
 * rule is added; but this file does NOT pretend they changed behaviour. Its assertion is the
 * BYTE-FOR-BYTE one -- exactly the validator's own message, no separator, no padding -- which is
 * simultaneously the negative control the repair has to survive.
 *
 * ------------------------------------------------------------------------------------------------
 * `:692` IS A JSON RESPONSE, NOT A FLASH, and it was checked before being touched.
 * `testSendMail` answers `response()->json(['is_success' => false, 'message' => ...])`, so the
 * change is to a response payload rather than to session state. It is included because nothing
 * PARSES that field: `resources/views/setting/index.blade.php:541-544` hands it straight to
 * `show_toastr('Error', data.message, 'error')` as display text. It is not the Flutter contract
 * either -- that lives under `app/Http/Controllers/Api/`, which this change does not touch.
 *
 * ------------------------------------------------------------------------------------------------
 * TWO EXCLUSIONS, BOTH MEASURED RATHER THAN ASSUMED.
 *
 * `CookieSettings` BUILDS A VALIDATOR AND NEVER CONSULTS IT. Measured by pairing every
 * `Validator::make(` with the next `fails()`: 12 built, 11 consulted, and the one at :741 has no
 * `fails()` before :860 -- which belongs to `RecaptchaSetting`'s validator at :859. Giving it a
 * guard would start REFUSING posts that succeed today. It carries no refusal site, so it is
 * naturally out of this change; the test below pins it so that stays true.
 *
 * `customMassage` :1269 IS NOT REPAIRED, and the reason is a finding rather than a scope call.
 * Its only live route is `routes/customer.php:188`, inside a group carrying just
 * `[ActiveTheme::class, 'themelanguage']` -- NO `auth`, NO permission -- while `routes/web.php:1066`
 * holds a commented-out route to a different controller's method of the same name. Every sibling
 * handler in this file is gated on `StoreSettingsAccess::permits()`; this one is gated on nothing,
 * and it writes `settings` rows. It does not write for an ANONYMOUS caller only because
 * `Auth::user()->id` at :1289 throws first, before `updateOrCreate` reaches the database. So the
 * live exposure is that ANY authenticated `web` user -- a staff member with no Settings
 * permission -- can overwrite their own store's WhatsApp template. Same-tenant, authenticated,
 * no permission check. That is an authorization change to fix, which this slice is not authorised
 * to make, so it is escalated and the site is left alone. The test pins it untouched so the
 * exclusion is recorded rather than looking like an oversight.
 */
class SettingsRefusalNamesEveryFieldTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SOURCE = 'app/Http/Controllers/SettingController.php';

    /** The nine repaired sites, by line, with the handler each sits in. */
    private const REPAIRED = [
        585 => 'saveEmailSettings',
        692 => 'testSendMail',
        1092 => 'WoocommerceSettings',
        1160 => 'shopifySettings',
        1319 => 'StockSettings',
        1362 => 'WhatsappSettings',
        1432 => 'whatsapp_notification_setting',
        1511 => 'testSendwhatsappmassage',
        1667 => 'TwilioSettings',
    ];

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant(['language' => 'en']);
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        // Every repaired handler is gated on StoreSettingsAccess::permits(), so the refusal branch
        // is unreachable without the permission. Granting it is what makes these readings about
        // VALIDATION rather than about authorization.
        foreach (['Manage Setting', 'Manage Store Setting'] as $name) {
            $this->grantPermission(User::find($this->merchant->id), $name);
        }

        $this->merchant = User::find($this->merchant->id);
    }

    private function source(): string
    {
        return (string) file_get_contents(base_path(self::SOURCE));
    }

    public function test_premise_every_repaired_line_carries_the_joined_expression(): void
    {
        $lines = explode("\n", $this->source());

        foreach (self::REPAIRED as $line => $handler) {
            $this->assertStringContainsString(
                "implode(' ', \$messages->all())",
                $lines[$line - 1] ?? '',
                "{$handler} at :{$line} should carry the joined expression; found: "
                . trim($lines[$line - 1] ?? '(past end of file)')
            );
        }
    }

    public function test_no_first_call_remains_on_a_repaired_handler(): void
    {
        // The whole file, so a tenth site appearing later is caught rather than ignored. The two
        // deliberate survivors are named, with the reason, so this is a declared set and not a
        // blanket count.
        $lines = explode("\n", $this->source());
        $survivors = [];

        foreach ($lines as $i => $line) {
            if (str_contains($line, '$messages->first()')) {
                $survivors[] = $i + 1;
            }
        }

        $this->assertSame(
            [1269],
            $survivors,
            'The only `$messages->first()` left in SettingController should be customMassage at '
            . ':1269, excluded because its route carries no auth or permission (see the class '
            . 'docblock). Found: ' . implode(', ', $survivors)
        );
    }

    public function test_the_dormant_validator_gained_no_fails_guard(): void
    {
        $source = $this->source();

        // 12 built, 11 consulted -- CookieSettings at :741 is the dormant one. Activating it would
        // refuse posts that succeed today, so the COUNTS are pinned, not just its line.
        $this->assertSame(
            12,
            substr_count($source, 'Validator::make('),
            'A validator was added or removed; re-measure which one is dormant before trusting '
            . 'the fails() count below.'
        );
        $this->assertSame(
            11,
            substr_count($source, 'fails()'),
            'A fails() guard was added or removed. If a dormant validator gained one, posts that '
            . 'succeed today would start being refused.'
        );
    }

    public function test_custommassage_is_untouched_and_its_route_really_is_ungated(): void
    {
        $lines = explode("\n", $this->source());

        $this->assertStringContainsString(
            '$messages->first()',
            $lines[1268] ?? '',
            'customMassage :1269 is deliberately NOT repaired; see the class docblock.'
        );

        // The reason, asserted rather than described: the route exists, and it carries neither
        // auth nor a permission.
        $route = collect(Route::getRoutes())->first(
            fn ($r) => str_contains((string) $r->getActionName(), 'SettingController@customMassage')
        );

        $this->assertNotNull($route, 'premise: customMassage is routed somewhere');

        $middleware = app('router')->gatherRouteMiddleware($route);
        $flat = implode('|', array_map(fn ($m) => is_string($m) ? $m : get_debug_type($m), $middleware));

        $this->assertStringNotContainsString(
            'Authenticate',
            $flat,
            'customMassage has gained an auth middleware. If that is deliberate, this exclusion '
            . 'can be revisited and the site repaired with the rest.'
        );
    }

    /**
     * @dataProvider observableSites
     */
    public function test_an_observable_refusal_names_every_failing_field(
        string $routeName,
        int $atLeast,
        array $payload = []
    ): void {
        $response = $this->actingAs($this->merchant)->post(route($routeName), $payload);

        $flash = (string) (session('error') ?? '');

        if ($flash === '') {
            // The JSON site answers a payload rather than a flash.
            $payload = $response->json();
            $flash = (string) ($payload['message'] ?? '');
            $this->assertNotSame('', $flash, "{$routeName} produced neither a flash nor a JSON message");
        }

        // A joined bag contains more than one sentence. Counting terminal periods is the cheapest
        // honest proxy and it is why `$atLeast` is a floor rather than an equality: the exact bag
        // depends on what is already stored for the conditional secret rules.
        $sentences = preg_match_all('/\.(\s|$)/', $flash);

        $this->assertGreaterThanOrEqual(
            $atLeast,
            $sentences,
            "{$routeName} should name at least {$atLeast} failing fields; it said: {$flash}"
        );
    }

    /**
     * FOUR OF THE NINE VALIDATORS ARE CONDITIONAL, and a payload of `[]` does not reach them --
     * measured, not assumed. `WoocommerceSettings`, `shopifySettings`, `WhatsappSettings` and
     * `TwilioSettings` each wrap their validator in
     * `if (isset($request->X_setting_enabled) && ... == 'on')`, so an empty post skips validation
     * entirely and the handler answers with NO error at all. A test that posted `[]` to those four
     * would fail for the wrong reason -- or, had it asserted the absence of an error, would have
     * passed vacuously, which is the fourth of this project's recorded ways a test lies.
     *
     * So every row below carries exactly the payload that reaches its validator and nothing more.
     */
    public static function observableSites(): array
    {
        return [
            'email settings (the 8-field panel)' => ['email.settings', 2, []],
            'test send mail (JSON)' => ['test.send.mail', 2, []],
            'stock settings' => ['stock.settings', 2, []],
            'whatsapp notification' => ['whatsapp-notification', 2, []],
            'whatsapp send message' => ['whatsapp.send.massage', 2, []],
            'woocommerce settings (conditional)' => [
                'woocommerce.settings', 2, ['woocommerce_setting_enabled' => 'on'],
            ],
            'twilio settings (conditional)' => [
                'twilio.settings', 2, ['twilio_setting_enabled' => 'on'],
            ],
            'shopify settings (conditional)' => [
                'shopify.settings', 2, ['shopify_setting_enabled' => 'on'],
            ],
        ];
    }

    /**
     * @dataProvider inertSites
     */
    public function test_an_inert_refusal_is_byte_for_byte_one_message(
        string $routeName,
        array $payload
    ): void {
        $this->actingAs($this->merchant)->post(route($routeName), $payload);

        $flash = (string) (session('error') ?? '');

        $this->assertNotSame('', $flash, "{$routeName} should still refuse an empty post");

        // ONE sentence, and no separator padding. This is the negative control: a join over a
        // one-message bag must be indistinguishable from first().
        $this->assertSame(
            trim($flash),
            $flash,
            "{$routeName} gained leading or trailing whitespace from the join: '{$flash}'"
        );
        $this->assertSame(
            1,
            preg_match_all('/\.(\s|$)/', $flash),
            "{$routeName} is a one-field validator, so its refusal must stay a single message; "
            . "it said: {$flash}"
        );
    }

    /**
     * ONE INERT SITE, NOT TWO -- AND THIS TEST IS WHAT CORRECTED THAT.
     *
     * `shopifySettings` was classified inert from a scratch measurement that parsed the rule array
     * out of the source between the validator and the refusal line. The P1-SEC-06 comment block
     * sits inside that array, and the parse took only the first rule, so the bag read as 1. Driven
     * for real, it is 2: "The shopify store url field is required. The shopify access token field
     * is required." It is now in `observableSites()` where it belongs.
     *
     * That is the fifth census error this round found by RUNNING the thing rather than reading it,
     * and the only one caught by a test instead of by inspection -- which is the argument for
     * driving every site rather than trusting a classification.
     *
     * `WhatsappSettings` really is inert: one field, `['required', 'regex:...]`, where `required`
     * fires on an absent value and `regex` on a present invalid one, never both.
     */
    public static function inertSites(): array
    {
        return [
            'whatsapp settings' => ['whatsapp.settings', ['whatsapp_setting_enabled' => 'on']],
        ];
    }

    public function test_a_valid_post_still_succeeds_on_a_repaired_screen(): void
    {
        // The positive control: the repair must not change whether a post is ACCEPTED, only which
        // message a refusal carries.
        // The three rules are low_stock_threshold, out_of_stock_threshold and `notification`,
        // read from the validator rather than guessed -- my first attempt invented a third field
        // and the control failed because the POST was genuinely refused.
        $response = $this->actingAs($this->merchant)->post(route('stock.settings'), [
            'low_stock_threshold' => '5',
            'out_of_stock_threshold' => '0',
            'notification' => 'on',
        ]);

        $response->assertRedirect();
        $this->assertNull(
            session('error'),
            'A valid stock-settings post was refused: ' . (string) session('error')
        );
        $this->assertNotNull(
            Setting::withoutGlobalScopes()
                ->where('store_id', $this->store->id)
                ->where('name', 'low_stock_threshold')
                ->first(),
            'The valid post should have written its setting row.'
        );
    }
}
