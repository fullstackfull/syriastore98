<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Setting;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-22 / #70 item 5 -- the lottery payment-method selection mismatch, VERIFIED BY EXECUTION.
 *
 * WHAT THIS FILE IS. #70 item 5 was filed from reading, and the issue says so: "I have NOT
 * reproduced item 5 by execution". The owner asked for it to be verified with executable tests
 * before any correction is proposed. This is that verification. It is a CHARACTERIZATION suite:
 * every assertion pins behaviour the shipped code has TODAY. Do not repair an assertion here to
 * get green -- the numbers are the evidence.
 *
 * CORRECTION TO AN EARLIER VERSION OF THIS FILE, AND THE REASON THE HARVEST TEST BELOW EXISTS.
 * The first version built its census from `App\Models\Setting::paymentList()`, whose `name_string`
 * is a TRANSLATED display label (`__('PayFast')`). From that it concluded that under Arabic no
 * gateway at all is reachable, because `resources/lang/ar.json` translates the brand names. THAT
 * WAS WRONG. The lottery form does not use that builder:
 *
 *   LotteryPrizesController.php:489-491
 *       $api = new ApiController();                       // App\Http\Controllers\Api\ApiController
 *       $payment_list_data = $api->payment_list($request, $store->slug);
 *       $payment_list      = $payment_list_data->getData();
 *
 * and `Api\ApiController::payment_list` assigns `name_string` as a BARE LITERAL -- zero of its 52
 * assignments call `__()` (asserted). Measured by rendering the real form in both locales: the
 * radio values are byte-identical. The Arabic finding is deleted, and the structural lesson is
 * that a census must be harvested from the rendered page, not reconstructed from a builder that
 * looks like the right one.
 *
 * THE MECHANISM, as it actually is. ApiController's `name_string` IS the gateway slug, lower-case
 * for 26 of the 35 the service implements. The view posts `ucfirst()` of it, and the switch is
 * case-sensitive:
 *
 *   lottery-form.blade.php:290     value="{{ ucfirst($payment_data->name_string) }}"
 *   ApiController.php:2452         $Setting_array[18]['name_string'] = 'payfast';
 *   LotteryPaymentService.php:83   case 'payfast':
 *
 *   'payfast' --ucfirst--> 'Payfast'  !==  'payfast'   -> default arm
 *
 * The nine that work are exactly the nine whose `case` literal is already capitalised. So every
 * one of the 26 failures differs from its `case` label ONLY in the first character's case, and a
 * single `strcasecmp` resolves all 26 -- the fix is SMALLER than the first version argued, not
 * larger.
 *
 * MEASURED: 52 catalogue entries, 35 implemented by the switch,
 *            9 REACHABLE   stripe Razorpay paypal flutterwave paytm Sspay Paytabs Xendit khalti
 *           26 UNREACHABLE every other implemented gateway, in both `en` and `ar`
 *
 * WHAT THE CUSTOMER SEES, measured end-to-end through the real route: `process()` returns
 * `response()->json(['error' => 'Invalid payment method'], 422)`; `lottery_form_data` reaches its
 * `else` arm and calls `filter_var($response, FILTER_VALIDATE_URL)`. `JsonResponse` inherits
 * `__toString` from `Symfony\Component\HttpFoundation\Response`, so nothing throws -- filter_var
 * stringifies the HTTP message and returns `false`. The handler then throws, its own
 * `catch (\Exception $e)` swallows it, and the customer is redirected back with
 * __('Incorrect currency of payment.') -- a message with nothing to do with the cause.
 *
 * THREE MORE THINGS IN THE SAME FLOW, all pinned below:
 *   - `lottery_form_data` NEVER CHECKS THE GATEWAY IS ENABLED FOR THE STORE. It validates
 *     `payment_methods` as `required` and nothing else; the view's `@if ($payment_data->status ==
 *     'on')` is the only consultation of the per-store row, and a POST does not go through the
 *     view. Measured: a store with zero `settings` rows accepts `payment_methods=telegram` with
 *     HTTP 200. (A forged POST, not a customer-selectable path -- the form posts `Telegram`.)
 *   - THE FORM TRUNCATES ITS OWN CATALOGUE. `LotteryPrizesController.php:499-504` drops `cod` and
 *     `bank_transfer`, and on reaching `CinetPay` sets `$stopFiltering = true`, so every catalogue
 *     entry AFTER CinetPay is silently never offered. Measured: `payu` enabled for the store does
 *     not appear among the rendered radios.
 *   - lottery-form.blade.php:397 and :428 compare the selection to `'payfast'` in JAVASCRIPT, so
 *     PayFast's two-step client flow never fires for the `'Payfast'` the radio emits. A server
 *     middleware cannot reach a browser comparison.
 *
 * WHY THERE IS NO FIX IN THIS COMMIT, and the two gateways a naive normalisation would break
 * (`phonepe` and `coingate`): docs/security/P01-LOTTERY-PAYMENT-SELECTION-PROPOSAL.md.
 */
class LotteryPaymentMethodSelectionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SERVICE = 'Workdo\LotteryPrizes\app\Services\LotteryPaymentService';

    private const VIEW = 'packages/workdo/LotteryPrizes/src/resources/views/theme/lottery-form.blade.php';

    private const SERVICE_FILE = 'packages/workdo/LotteryPrizes/src/app/Services/LotteryPaymentService.php';

    private const CONTROLLER_FILE = 'packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryPrizesController.php';

    /** The nine the storefront can actually dispatch, by gateway slug, in any locale. */
    private const REACHABLE = [
        'stripe', 'Razorpay', 'paypal', 'flutterwave', 'paytm', 'Sspay', 'Paytabs', 'Xendit', 'khalti',
    ];

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'LotteryPrizes',
            'name' => 'Lottery Prizes',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'lottery-prizes',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['LotteryPrizes']);

        foreach (glob(base_path('packages/workdo/LotteryPrizes/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        foreach (['purchase_tickets', 'lottery_settings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Module table `{$table}` was not created.");
        }
    }

    /* ---------------------------------------------------------------------------------------- *
     *  READING THE SHIPPED SOURCES
     *
     *  The census is recomputed from the vendored files on every run rather than restated from a
     *  hard-coded table, so an upstream rename moves the numbers and the pinned counts go red.
     * ---------------------------------------------------------------------------------------- */

    /** @return list<string> the `case '...'` labels of LotteryPaymentService::process, in order */
    private function switchLabels(): array
    {
        $src = file_get_contents(base_path(self::SERVICE_FILE));
        $start = strpos($src, 'public function process(');
        $end = strpos($src, 'private function processStripe(');

        $this->assertIsInt($start, 'LotteryPaymentService::process() was not found.');
        $this->assertIsInt($end, 'processStripe() was not found, so the switch cannot be bounded.');

        preg_match_all("/case '([^']*)':/", substr($src, $start, $end - $start), $m);

        return $m[1];
    }

    /**
     * The catalogue THE FORM USES: `Api\ApiController::payment_list`, slug => the literal
     * `name_string`. Read from the source because the builder needs a resolved store and ~50
     * settings rows; `test_premise_the_harvest_*` proves the parse agrees with the rendered page,
     * which is the check the first version of this file was missing.
     *
     * @return array<string, string>
     */
    private function catalogue(): array
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Api/ApiController.php'));

        preg_match_all("/Setting_array\[\s*(\d+)\s*\]\[\s*'name_string'\s*\]\s*=\s*'([^']*)'/", $src, $labels, PREG_SET_ORDER);
        preg_match_all("/Setting_array\[\s*(\d+)\s*\]\[\s*'name'\s*\]\s*=\s*'([^']*)'/", $src, $slugs, PREG_SET_ORDER);

        $byIndex = [];
        foreach ($labels as $row) {
            $byIndex[(int) $row[1]]['label'] = $row[2];
        }
        foreach ($slugs as $row) {
            $byIndex[(int) $row[1]]['slug'] = $row[2];
        }
        ksort($byIndex);

        $out = [];
        foreach ($byIndex as $pair) {
            if (isset($pair['slug'], $pair['label'])) {
                $out[$pair['slug']] = $pair['label'];
            }
        }

        return $out;
    }

    /**
     * @return array{reachable: list<string>, unreachable: array<string, array{posted: string, wants: string}>, unimplemented: list<string>}
     */
    private function census(): array
    {
        $labels = $this->switchLabels();
        $reachable = $unreachable = $unimplemented = [];

        foreach ($this->catalogue() as $slug => $raw) {
            // Case-INSENSITIVE here only to decide whether the gateway is implemented at all.
            // Dispatch is `switch`, which is case-SENSITIVE. That asymmetry is the whole defect.
            $wants = null;
            foreach ($labels as $label) {
                if (strcasecmp($label, $slug) === 0) {
                    $wants = $label;
                    break;
                }
            }

            $posted = ucfirst($raw);

            if ($wants === null) {
                $unimplemented[] = $slug;
            } elseif (in_array($posted, $labels, true)) {
                $reachable[] = $slug;
            } else {
                $unreachable[$slug] = ['posted' => $posted, 'wants' => $wants];
            }
        }

        return compact('reachable', 'unreachable', 'unimplemented');
    }

    /* ---------------------------------------------------------------------------------------- *
     *  PREMISES
     * ---------------------------------------------------------------------------------------- */

    public function test_premise_the_module_loads_and_the_payment_service_dispatches_a_switch(): void
    {
        $this->enableModule();

        $this->assertTrue(class_exists(self::SERVICE),
            'LotteryPaymentService did not load. Module classes are not in the root composer '
            . 'autoload map; they arrive only through PackageServiceProvider::loadAddons().');

        $labels = $this->switchLabels();

        $this->assertCount(35, $labels, 'The switch no longer has 35 case labels.');
        $this->assertSame($labels, array_values(array_unique($labels)),
            'A duplicated case label would make one arm dead code and skew the census.');

        // Nine capitalised labels; those nine are exactly the reachable set.
        $capitalised = array_values(array_filter($labels, fn ($l) => $l !== lcfirst($l)));
        $this->assertCount(9, $capitalised);
    }

    /**
     * THE PROVENANCE TEST. This is the one the first version of this file lacked, and its absence
     * is what let a false Arabic finding through.
     */
    public function test_premise_the_form_uses_the_api_controller_catalogue_which_never_translates(): void
    {
        $controller = file_get_contents(base_path(self::CONTROLLER_FILE));

        $this->assertStringContainsString('use App\Http\Controllers\Api\ApiController;', $controller);
        $this->assertStringContainsString('$api = new ApiController();', $controller);
        $this->assertStringContainsString('$api->payment_list($request, $store->slug)', $controller,
            'The form no longer builds its list from Api\ApiController::payment_list. Re-derive '
            . 'the census from whichever builder it now uses -- do NOT assume Setting::paymentList.');

        $api = file_get_contents(base_path('app/Http/Controllers/Api/ApiController.php'));
        $start = strpos($api, 'public function payment_list(');
        $this->assertIsInt($start);
        $body = substr($api, $start, strpos($api, "\n    public function ", $start + 10) - $start);

        $this->assertSame(0, preg_match_all("/name_string'\]\s*=\s*__\(/", $body),
            'Api\ApiController::payment_list has started translating name_string. The posted '
            . 'values would become locale-dependent and the invariance test below is then false.');
        $this->assertGreaterThan(40, preg_match_all("/name_string'\]\s*=/", $body),
            'The catalogue shrank unexpectedly.');
    }

    /**
     * THE HARVEST. Render the real form and take the radio values out of the HTML, in both
     * locales. Nothing is reconstructed; this is what a customer's browser is handed.
     */
    public function test_premise_the_harvest_from_the_rendered_form_is_locale_invariant(): void
    {
        $this->enableModule();

        [$store, , $productSlug] = $this->lotteryAt(25.0, ['stripe', 'payfast', 'telegram',
            'paytabs', 'phonepe', 'coingate', 'cinetpay', 'payu']);

        $harvested = [];
        foreach (['en', 'ar'] as $locale) {
            $this->app->setLocale($locale);

            $response = $this->get('/' . $store->slug . '/lottery-form/' . $productSlug);
            $response->assertOk();

            preg_match_all('/name="payment_methods"[^>]*value="([^"]*)"/', $response->getContent(), $m);
            $harvested[$locale] = $m[1];
        }

        $this->assertSame(
            ['Stripe', 'Coingate', 'Paytabs', 'Payfast', 'Telegram', 'Phonepe', 'Cinetpay'],
            $harvested['en'],
            'The rendered radio values changed. Every posted value in this file comes from here.'
        );

        $this->assertSame($harvested['en'], $harvested['ar'],
            'The radio values differ by locale. THE ARABIC FINDING THIS FILE RETRACTED WOULD THEN '
            . 'BE BACK -- re-measure before touching anything else.');

        // `payu` was enabled for this store and is NOT offered: the $stopFiltering truncation.
        $this->assertNotContains('Payu', $harvested['en']);
        $this->assertNotContains('COD', $harvested['en']);
    }

    public function test_premise_the_radio_still_posts_the_ucfirst_of_the_catalogue_label(): void
    {
        $view = file_get_contents(base_path(self::VIEW));

        $this->assertStringContainsString(
            'name="payment_methods" id="payment_method_{{$payment_key}}" value="{{ ucfirst($payment_data->name_string) }}"',
            $view,
            'The radio no longer posts ucfirst(name_string). If it now posts $payment_data->name, '
            . 'this file describes a defect that no longer exists.'
        );

        $this->assertStringContainsString('@if ($payment_data->status == \'on\')', $view,
            'The view no longer gates on status, so "a customer can select it" is unproven.');
    }

    /* ---------------------------------------------------------------------------------------- *
     *  THE CENSUS
     * ---------------------------------------------------------------------------------------- */

    public function test_only_nine_of_the_thirty_five_implemented_gateways_are_reachable(): void
    {
        $this->enableModule();

        $census = $this->census();

        $this->assertSame(self::REACHABLE, $census['reachable'],
            'The set of gateways the storefront can dispatch has changed.');
        $this->assertCount(26, $census['unreachable'],
            'Implemented-but-unreachable gateway count changed. Pinned at 26.');
        $this->assertCount(17, $census['unimplemented'],
            'Catalogue entries with no switch arm at all. Pinned at 17.');
        $this->assertSame(52,
            count($census['reachable']) + count($census['unreachable']) + count($census['unimplemented']),
            'The three buckets no longer partition the 52-entry catalogue.');
    }

    /**
     * EVERY ONE OF THE 26 DIFFERS ONLY IN THE FIRST CHARACTER'S CASE. This is what makes the
     * correction a `strcasecmp` rather than a hand-maintained map, and the first version of this
     * file got it wrong by measuring the translated builder: it reported three "not a case
     * difference" rows (`Paytabs`/`Paytab`, `mercado`/`Mercado Pago`, `FedPay`/`FedaPay`) that do
     * not exist on this surface.
     */
    public function test_every_unreachable_selection_differs_from_its_case_label_only_in_capitalisation(): void
    {
        $this->enableModule();

        $unreachable = $this->census()['unreachable'];

        foreach ($unreachable as $slug => $pair) {
            $this->assertSame(0, strcasecmp($pair['posted'], $pair['wants']),
                "Gateway `{$slug}`: posted '{$pair['posted']}' vs case '{$pair['wants']}' differ by "
                . 'more than case, so a strcasecmp-based correction would not reach it.');
            $this->assertSame(ucfirst($pair['wants']), $pair['posted'],
                "Gateway `{$slug}`: the posted value is not ucfirst() of the case label.");
        }

        // Named rows so a failure carries the mechanism, with the values actually posted.
        foreach ([
            'payfast' => ['posted' => 'Payfast', 'wants' => 'payfast'],
            'paytr' => ['posted' => 'Paytr', 'wants' => 'paytr'],
            'mercado' => ['posted' => 'Mercado', 'wants' => 'mercado'],
            'FedPay' => ['posted' => 'Fedpay', 'wants' => 'fedpay'],
            'PhonePe' => ['posted' => 'Phonepe', 'wants' => 'phonepe'],
            'coingate' => ['posted' => 'Coingate', 'wants' => 'coingate'],
        ] as $slug => $pair) {
            $this->assertSame($pair, $unreachable[$slug] ?? null, "Gateway `{$slug}`.");
        }
    }

    /**
     * RETRACTION, PINNED. The posted values are locale-invariant because the builder never calls
     * `__()`. `resources/lang/ar.json` really does translate the brand names -- that part was
     * true -- but those translations only reach `Setting::paymentList`, which this form does not
     * use. Asserted so the retracted finding cannot be re-filed from the ar.json evidence alone.
     */
    public function test_the_arabic_translations_exist_but_never_reach_this_form(): void
    {
        $this->app->setLocale('ar');

        $this->assertNotSame('Stripe', __('Stripe'),
            'resources/lang/ar.json no longer translates the brand name `Stripe`.');

        $fromSettingBuilder = array_values(array_filter(
            file(base_path('app/Models/Setting.php')),
            fn ($line) => str_contains($line, "['name_string'] = __('Stripe')")
        ));
        $this->assertNotEmpty($fromSettingBuilder,
            'Setting::paymentList no longer translates, so the two builders no longer differ and '
            . 'the retraction note in this file is obsolete.');

        // The form's own catalogue, by contrast, is a literal.
        $this->assertSame('Stripe', $this->catalogue()['stripe'],
            "Api\\ApiController's label for stripe is no longer the plain literal `Stripe`.");
    }

    /* ---------------------------------------------------------------------------------------- *
     *  EXECUTABLE PROOF -- the service is driven, offline
     *
     *  `telegram` and `whatsapp` are the only processors that touch no network and no credentials
     *  (LotteryPaymentService.php:1135 and :1368 -- a Session::put and a view), so they are the
     *  only pair that can prove BOTH directions for the SAME gateway: the exact string the form
     *  posts, and the exact string the switch wants.
     * ---------------------------------------------------------------------------------------- */

    /** ONE CHARACTER OF CASE, TWO OUTCOMES, BOTH EXECUTED. This is the defect entire. */
    public function test_the_same_gateway_dispatches_under_the_switch_label_and_fails_under_the_posted_one(): void
    {
        $this->enableModule();

        $service = new (self::SERVICE)();
        $data = ['product_id' => 1, 'ticket_price' => 10];

        foreach (['telegram' => 'Telegram', 'whatsapp' => 'Whatsapp'] as $switchLabel => $posted) {
            $dispatched = $service->process($data, $switchLabel, 'any-slug', 10);

            $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $dispatched,
                "`{$switchLabel}` is a real case label and must dispatch to its processor.");

            $refused = $service->process($data, $posted, 'any-slug', 10);

            $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $refused,
                "`{$posted}` -- the string the radio actually posts for this gateway -- should "
                . 'have fallen through to the default arm.');
            $this->assertSame(422, $refused->getStatusCode());
            $this->assertSame(['error' => 'Invalid payment method'], $refused->getData(true));
        }
    }

    /** All 26 unreachable selections, driven through the real service. */
    public function test_every_unreachable_selection_lands_on_the_invalid_payment_method_arm(): void
    {
        $this->enableModule();

        $service = new (self::SERVICE)();
        $unreachable = $this->census()['unreachable'];

        $this->assertCount(26, $unreachable);

        foreach ($unreachable as $slug => $pair) {
            $response = $service->process(['product_id' => 1], $pair['posted'], 'any-slug', 10);

            $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response,
                "`{$slug}`: posting '{$pair['posted']}' reached a processor, so it is not "
                . 'unreachable after all.');
            $this->assertSame(422, $response->getStatusCode(), "`{$slug}`");
        }
    }

    /**
     * THE NINE THAT WORK MUST KEEP WORKING, and `Paytabs` is the one the first version of this file
     * mis-filed as broken. Proven by execution for the only two that are offline; the other seven
     * need gateway credentials, so for those the proof is that the posted value IS a case label.
     */
    public function test_the_nine_reachable_selections_are_not_refused_by_the_switch(): void
    {
        $this->enableModule();

        $labels = $this->switchLabels();
        $catalogue = $this->catalogue();

        foreach (self::REACHABLE as $slug) {
            $this->assertContains(ucfirst($catalogue[$slug]), $labels,
                "`{$slug}` must still post a string the switch dispatches on.");
        }

        $this->assertContains('Paytabs', $labels);
        $this->assertSame('Paytabs', ucfirst($catalogue['Paytabs']),
            'Paytabs dispatches TODAY. Any correction must not break it -- the first version of '
            . 'this file wrongly listed it as unreachable, from the translated builder where the '
            . "label is `Paytab`.");
    }

    /**
     * The controller's handling of the 422, measured rather than reasoned: `JsonResponse` inherits
     * `__toString` from Symfony's `Response`, so `filter_var` stringifies instead of throwing.
     */
    public function test_the_default_arm_stringifies_rather_than_throwing_which_is_why_the_message_is_wrong(): void
    {
        $this->enableModule();

        $response = (new (self::SERVICE)())->process([], 'Payfast', 'any-slug', 10);

        $this->assertTrue(method_exists($response, '__toString'),
            'If JsonResponse ever loses __toString, filter_var() would throw \Error, which '
            . 'catch (\Exception) does NOT catch -- the outcome would become a 500.');
        $this->assertFalse(filter_var($response, FILTER_VALIDATE_URL));

        $controller = file_get_contents(base_path(self::CONTROLLER_FILE));

        $this->assertStringContainsString(
            'throw new \Exception("Invalid response URL for payment method: $paymentMethod");',
            $controller);
        $this->assertStringContainsString(
            "redirect()->back()->with('error', __('Incorrect currency of payment.'))",
            $controller);
    }

    /**
     * END TO END, through the registered route, with every posted value taken from the harvest.
     *
     * NOTE ON WHAT THIS CAN AND CANNOT SHOW: a reachable gateway without credentials ALSO ends in
     * the currency message, because its processor throws and the same catch swallows it. So this
     * test proves the user-visible OUTCOME, not the reachable/unreachable split -- that split is
     * proven by driving `process()` directly, above. Both are needed.
     */
    public function test_a_customer_selecting_payfast_is_redirected_back_with_the_currency_message(): void
    {
        $this->enableModule();

        [$store, $productId] = $this->lotteryAt(25.0);

        foreach (['Payfast', 'Telegram', 'Phonepe', 'Coingate'] as $posted) {
            $response = $this->from('/' . $store->slug . '/lottery-form')
                ->post('/' . $store->slug . '/lottery-form-data', $this->formFields($productId, $posted));

            $response->assertRedirect('/' . $store->slug . '/lottery-form');
            $response->assertSessionHas('error', 'Incorrect currency of payment.');

            $this->flushSession();
        }

        $this->assertSame(0, (int) DB::table('purchase_tickets')->count(),
            'No ticket should have been written -- this is a functional outage, not a forged row.');
    }

    /**
     * THE CONTROL, so the test above is not green because of a broken fixture, an unloaded module,
     * a validator rejection or a 404 -- each of which also produces a non-200.
     *
     * MEASURED through this same route: `telegram` and `whatsapp` return 200 with a rendered
     * payment view; `Payfast`, `Telegram`, `Phonepe`, `Coingate` and `COD` return 302 with the
     * currency message. The pair differing by one character of case is the whole finding.
     */
    public function test_control_the_same_request_with_the_switch_label_renders_the_payment_view(): void
    {
        $this->enableModule();

        [$store, $productId] = $this->lotteryAt(25.0);

        foreach (['telegram', 'whatsapp'] as $switchLabel) {
            $response = $this->from('/' . $store->slug . '/lottery-form')
                ->post('/' . $store->slug . '/lottery-form-data', $this->formFields($productId, $switchLabel));

            $response->assertSessionMissing('error');
            $this->assertSame(200, $response->getStatusCode(),
                "`{$switchLabel}` is a real case label: the handler must render, not redirect.");
            $this->assertGreaterThan(1000, strlen($response->getContent()),
                "`{$switchLabel}` rendered an empty body, so 'it dispatched' is unproven.");
        }
    }

    /* ---------------------------------------------------------------------------------------- *
     *  THREE MORE THINGS IN THE SAME FLOW
     * ---------------------------------------------------------------------------------------- */

    /**
     * THE HANDLER NEVER CHECKS THAT THE SELECTED GATEWAY IS ENABLED FOR THE STORE.
     *
     * Independent of the case mismatch, and fixing the mismatch alone would WIDEN it: 26 more
     * processors would become reachable by direct POST regardless of what the merchant switched
     * on. A forged POST rather than a customer-selectable path, since the form posts `Telegram`.
     */
    public function test_the_handler_accepts_a_gateway_the_store_has_switched_off(): void
    {
        $this->enableModule();

        [$store, $productId] = $this->lotteryAt(25.0);

        $this->assertSame(0, (int) DB::table('settings')->where('store_id', $store->id)->count(),
            'The fixture must have no payment settings, or "switched off" is not established.');

        $catalogue = collect(Setting::paymentList($store->slug))
            ->mapWithKeys(fn ($row) => [$row['name'] => $row['status']]);
        $this->assertSame('off', $catalogue['telegram'],
            'Telegram must be off for this store, or the test below proves nothing.');

        $response = $this->from('/' . $store->slug . '/lottery-form')
            ->post('/' . $store->slug . '/lottery-form-data', $this->formFields($productId, 'telegram'));

        $this->assertSame(200, $response->getStatusCode(),
            'PINNING A DEFECT: a disabled gateway is dispatched. When this starts returning a '
            . 'refusal, the allowlist landed -- update this test rather than restoring 200.');
    }

    /**
     * THE FORM TRUNCATES ITS OWN CATALOGUE AT CinetPay.
     *
     * `$stopFiltering = true` on reaching CinetPay (catalogue index 37) means every later entry --
     * SenangPay, CyberSource, Ozow, Easebuzz, NMI, PayU, Sofort, eSewa, MyFatoorah, Paynow, DPO,
     * Braintree, PowerTranz, SSLCommerz -- is never offered, however the merchant configures it.
     * COD and bank transfer are dropped by the same loop.
     *
     * This replaces a claim in the first version of this file that COD "is offered by default and
     * cannot complete". COD is never offered on the lottery form at all.
     */
    public function test_the_form_drops_cod_and_everything_after_cinetpay(): void
    {
        $this->enableModule();

        $controller = file_get_contents(base_path(self::CONTROLLER_FILE));

        $this->assertStringContainsString("if (\$payment->name === 'CinetPay') {", $controller);
        $this->assertStringContainsString('$stopFiltering = true;', $controller);
        $this->assertStringContainsString("\$payment->name !== 'cod' && \$payment->name !== 'bank_transfer'", $controller);

        [$store, , $productSlug] = $this->lotteryAt(25.0, ['cinetpay', 'payu', 'sslcommerz', 'braintree']);

        $response = $this->get('/' . $store->slug . '/lottery-form/' . $productSlug);
        $response->assertOk();

        preg_match_all('/name="payment_methods"[^>]*value="([^"]*)"/', $response->getContent(), $m);

        $this->assertSame(['Cinetpay'], $m[1],
            'Only CinetPay should be offered: it is the truncation point, and the three gateways '
            . 'enabled after it are dropped.');
    }

    /**
     * PayFast's client-side flow is gated on a string the radio cannot emit, so even with the
     * switch fixed server-side the form is never retargeted at the PayFast host.
     */
    public function test_the_payfast_javascript_compares_a_value_the_radio_never_emits(): void
    {
        $view = file_get_contents(base_path(self::VIEW));

        $this->assertSame(2, substr_count($view, "if (payment_type == 'payfast')"),
            'Both PayFast click handlers should still compare the lowercase slug.');

        $this->assertSame('Payfast', ucfirst($this->catalogue()['payfast']),
            'What the radio posts for PayFast.');
        $this->assertNotSame('payfast', ucfirst($this->catalogue()['payfast']),
            'If these ever matched, the JavaScript branch would fire and this test is obsolete.');
    }

    /**
     * THE TWO GATEWAYS A NAIVE CASE-FOLDING FIX WOULD BREAK. Both are response-dispatch mismatches
     * in `lottery_form_data`, downstream of the switch, and both are pinned here because the
     * proposal's sequencing depends on them.
     *
     *   phonepe  list B at :546 names 'Phonepe', while the switch label is 'phonepe'. Normalising
     *            to the switch label makes the gateway dispatch and makes this list stop matching,
     *            so PhonePe lands in the else arm and still ends in the currency message.
     *   coingate list B names 'coingate'. Normalising routes a SUCCESSFUL processCoingate -- which
     *            returns `(object) $response` at LotteryPaymentService:629 -- into
     *            `new RedirectResponse($response->getTargetUrl())`. `stdClass::getTargetUrl()` is
     *            an `\Error`, NOT an Exception, so `catch (\Exception)` does not catch it: HTTP
     *            500 where today it is a 302. The dedicated `elseif ($paymentMethod ==
     *            'coingate')` at :549 is already dead code, because :546 matches first.
     *
     * Today neither hazard fires, because neither 'Phonepe' nor 'Coingate' matches list B. The
     * measured reason PhonePe is a 302 today and not a 500 is that `JsonResponse::getTargetUrl()`
     * raises `BadMethodCallException`, which IS an Exception -- so the existing catch swallows it.
     */
    public function test_the_response_dispatch_lists_hold_two_hazards_for_a_case_folding_fix(): void
    {
        $this->enableModule();

        $controller = file_get_contents(base_path(self::CONTROLLER_FILE));
        $labels = $this->switchLabels();

        $this->assertStringContainsString("'tap', 'payhere', 'Phonepe', 'paddle'", $controller,
            "Response-dispatch list B no longer names 'Phonepe'.");
        $this->assertContains('phonepe', $labels);
        $this->assertNotContains('Phonepe', $labels,
            "If the switch gains a 'Phonepe' arm the phonepe hazard is gone.");

        $this->assertStringContainsString("'fedpay', 'cinetpay', 'coingate'", $controller,
            "Response-dispatch list B no longer names 'coingate'.");
        $this->assertStringContainsString('return (object)$response;',
            file_get_contents(base_path(self::SERVICE_FILE)),
            'processCoingate no longer returns a bare object, so the getTargetUrl hazard is gone.');

        // The mechanisms, executed.
        $this->assertFalse((new \BadMethodCallException) instanceof \Error);
        $this->assertTrue((new \BadMethodCallException) instanceof \Exception,
            'BadMethodCallException must remain an Exception -- that is why an undefined method on '
            . 'the 422 JsonResponse is a 302 today rather than a 500.');

        try {
            ((object) ['payment_url' => 'x'])->getTargetUrl();
            $this->fail('stdClass accepted an undefined method call.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Error::class, $e,
                'An undefined method on stdClass must be an \Error, which catch(\Exception) does '
                . 'not catch -- that is the coingate 500.');
        }
    }

    /* ---------------------------------------------------------------------------------------- *
     *  FIXTURES
     * ---------------------------------------------------------------------------------------- */

    /**
     * @param  list<string>  $enabledGateways  `is_<name>_enabled` rows to write for the store
     * @return array{0: Store, 1: int, 2: string}  store, product id, product slug
     */
    private function lotteryAt(float $price, array $enabledGateways = []): array
    {
        [$owner, $store] = $this->makeTenant();

        $productSlug = 'lottery-product-' . $store->id;

        $productId = (int) DB::table('products')->insertGetId([
            'name' => 'Lottery product',
            'slug' => $productSlug,
            'store_id' => $store->id,
            'created_by' => $owner->id,
            'price' => $price,
            'track_stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lottery_settings')->insert([
            'store_id' => $store->id,
            'lottery_products' => $productId,
            'ticket_price' => $price,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($enabledGateways as $gateway) {
            DB::table('settings')->insert([
                'name' => "is_{$gateway}_enabled",
                'value' => 'on',
                'created_by' => $owner->id,
                'store_id' => $store->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($enabledGateways !== []) {
            // Setting is Cachable and getAdminAllSetting memoises the user for an hour.
            Cache::flush();
            (new Setting)->flushCache();
        }

        return [$store, $productId, $productSlug];
    }

    private function formFields(int $productId, string $paymentMethod): array
    {
        return [
            'product_id' => $productId,
            'payment_methods' => $paymentMethod,
            'ticket_price' => 25,
            'lottery_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'lottery_user_telephone' => '1', 'lottery_address' => 'x',
                'lottery_country' => 1, 'lottery_state' => 1, 'lottery_city' => 1,
                'lottery_postecode' => '1',
            ],
        ];
    }
}
