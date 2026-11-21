<?php

namespace Tests\Unit\Loyalty;

use App\Events\AddRewardClubPoint;
use App\Listeners\NormaliseRewardRedemptionFlag;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * D7 — THE VENDORED REWARD LISTENER READS `club_point_is_active=off` AS "REDEEM", AND `off` IS THE
 * VALUE THE SHIPPED CHECKOUT FORM POSTS ON EVERY ORDER.
 * =================================================================================================
 *
 * OWNER-APPROVED 2026-11-17, D7: *"Use safe first-party protections for the identified vendor
 * defects and report vendor issues upstream. Do not directly modify protected WorkDo vendor
 * packages."*
 *
 * THE DEFECT, one line, `AddRewardClubPointLis.php:38`:
 *
 *     if ($requests_data['club_point_is_active'] ?? '' == 'on' && $requests_data['final_amount_point'] == $order['final_price'])
 *
 * `==` and `&&` both bind TIGHTER than `??`, so PHP parses that as
 *
 *     $requests_data['club_point_is_active'] ?? (('' == 'on') && ($requests_data['final_amount_point'] == $order['final_price']))
 *                                              └────────────────── always false, and never evaluated ──────────────────┘
 *
 * which collapses to `$requests_data['club_point_is_active'] ?? false` — the TRUTHINESS of the
 * posted flag. Two consequences, both measured by executing the expression (see
 * `guardVerdict()` below, and the table in `test_premise_*`):
 *
 *   1. `'off'` IS A NON-EMPTY STRING AND THEREFORE TRUTHY, so the redemption branch runs on an
 *      order that redeemed nothing. That branch does
 *
 *          $totalClubPoint = $orderClubPoint;          // :39  — NOT += ; the prior balance is GONE
 *          $customer['total_club_point'] = $totalClubPoint;
 *          $customer->save();                          // :46-47
 *
 *      so the customer's whole accumulated balance is REPLACED by the points earned on this one
 *      order, and a `RewardClubPoint` row is written with `used_points` = the entire prior balance
 *      (:55) as though it had been spent. The accrual branch at :43 is the one that does
 *      `($customer['total_club_point'] ?? 0) + $orderClubPoint`, and it is the branch that does
 *      not run.
 *
 *   2. THE ANTI-TAMPER CHECK NEVER EXECUTES. `$requests_data['final_amount_point'] == $order['final_price']`
 *      is plainly meant to refuse a forged total, and it sits in the dead right operand of `??`.
 *      It has never run.
 *
 * WHY THIS IS NOT THEORETICAL — AND AN EARLIER VERSION OF THIS BLOCK TRACED THE WRONG INPUT.
 * It cited `clubPointFormField.blade.php` -> `checkout_hide_field.blade.php` ->
 * `common/checkout/checkout_form.blade.php:125` and concluded "every order". Nothing renders that
 * last file — see `test_premise_the_other_hidden_field_is_on_a_path_nothing_renders()` below,
 * which is now a ratchet on exactly that. The input that actually posts is the other one:
 *
 *     clubPointApply.blade.php:1-9     eligibility gate: six reward settings present, AND
 *                                      total_club_point >= checkout_points, AND
 *                                      final_price >= checkout_price
 *     clubPointApply.blade.php:25      <input type="hidden" class="club_point_is_active" name="club_point_is_active" value="off">
 *     ViewComposer.php:27-32           pushed only when the view has both `store` and `response`
 *     checkout_list.blade.php:2        @stack('clubPointApply')
 *     checkout-cart-list.blade.php:97  @include('front_end.hooks.checkout_list')
 *     CartController.php:114           rendered into checkout_html_2
 *     front-theme.js:224               $('.checkout_page_cart').html(response.checkout_html_2)
 *     checkout.blade.php:25/256/263    .checkout_page_cart sits BETWEEN Form::open and Form::close
 *
 * It lands inside the checkout form, so it posts on submit, and the module's JavaScript flips it
 * to `'on'` only when the shopper applies points (`clubPointApply.blade.php:80`). The shared
 * `class` — not the `name` — is the handle it uses.
 *
 * SO THE DESTRUCTIVE PATH FIRES FOR A LOGGED-IN CUSTOMER WHO ALREADY QUALIFIES TO REDEEM and did
 * not press Apply — not for every shopper. It degenerates to every logged-in checkout only when
 * both thresholds are configured at 0, which is savable because `RewardClubPointController:26-31`
 * validates them with a bare `required` and `required` passes both `0` and `'0'`.
 *
 * THE PROTECTION IS A SHAPE NORMALISATION, NOT A SECOND DECISION, and that distinction is the
 * whole design. `NormaliseRewardRedemptionFlag` reduces the posted flag to `'on'`-or-absent and
 * touches nothing else. It does not decide whether redemption was allowed, does not read a
 * balance, does not consult a threshold and does not compute money. It restores exactly the
 * behaviour the vendor's own parenthesised intent would have produced for the flag, which is why
 * it cannot disagree with the eleven first-party sites that apply the discount:
 *
 *     posted flag        before            after            vendored branch after
 *     'on'               truthy  REDEEM    'on'             REDEEM     (unchanged)
 *     'off'  (default)   truthy  REDEEM    absent           accrue     <- THE FIX
 *     absent             false   accrue    absent           accrue     (unchanged)
 *     '' / '0' / null    false   accrue    absent           accrue     (unchanged)
 *
 * No row moves from accrue to redeem. Only `'off'` moves, and it moves to the branch the vendor's
 * comment structure shows was meant for it.
 *
 * `==` AND NOT `===`, DELIBERATELY. Every first-party site that applies the discount compares
 * `$requests_data['club_point_is_active'] == 'on'` loosely, and an API client can post JSON
 * `true`, for which `true == 'on'` is TRUE in PHP 8. A strict `=== 'on'` here would strip a flag
 * those sites honour, giving a discount with no points debited — free money, introduced by the
 * guard meant to prevent a loss. Pinned by
 * `test_a_loosely_equal_flag_is_kept_because_every_first_party_site_compares_loosely`.
 *
 * WHAT THIS DOES NOT FIX, stated here because the next reader will assume otherwise:
 *
 *   - IT DOES NOT ENFORCE ELIGIBILITY. `checkout_points` and `checkout_price` are still checked in
 *     Blade only; a hand-crafted `club_point_is_active=on` still redeems below both thresholds.
 *     That is D6 and a separate slice.
 *   - IT DOES NOT REPAIR ANY EXISTING BALANCE. Every balance already overwritten stays overwritten.
 *     D4 forbids retrospectively altering balances without separate approval, and the data needed
 *     to reconstruct them may not exist.
 *   - IT DOES NOT FIX THE TWO OTHER DEFECTS IN THE SAME VENDORED FILE. `:37` and `:41` both carry
 *     the `($a * $b ?? 0) / ($c ?? 0)` shape this project already fixed on the first-party side —
 *     a mis-bound multiplication and a divisor defaulted to zero. Recorded, not intercepted.
 *
 * NO TEST HERE EXECUTES THE VENDORED LISTENER. It cannot: no WorkDo module loads without an
 * `add_on_managers` row and this container has none. So the premise tests PIN THE VENDORED SOURCE
 * TEXT and reproduce its expression locally; if WorkDo fixes the precedence, the premise test
 * fails and says so rather than this slice silently becoming redundant.
 */
class RewardRedemptionFlagTest extends TestCase
{
    private const VENDORED_LISTENER = 'packages/workdo/RewardClubPoint/src/app/Listeners/AddRewardClubPointLis.php';

    /**
     * The vendored condition, reproduced verbatim so its verdict can be executed.
     *
     * NOT `eval()` of the real line: that would make the test's behaviour depend on parsing
     * vendored source at runtime. Instead the expression is written out here, and
     * `test_premise_the_vendored_guard_is_still_the_mis_bound_expression` asserts the real file
     * still contains that exact spelling. If the vendor re-parenthesises it, that test fails.
     */
    private function guardVerdict(array $requestsData, array $order): bool
    {
        return (bool) ($requestsData['club_point_is_active'] ?? '' == 'on' && $requestsData['final_amount_point'] == $order['final_price']);
    }

    private function normalise(array $requestsData): array
    {
        $event = new AddRewardClubPoint($requestsData, ['id' => 1, 'final_price' => 100.0], 'a-slug');
        (new NormaliseRewardRedemptionFlag)->handle($event);

        return $event->requests_data;
    }

    // ---------------------------------------------------------------------------------------------
    // Premises. Each is a claim about a file I do not own, so each is measured rather than assumed.
    // ---------------------------------------------------------------------------------------------

    public function test_premise_the_vendored_guard_is_still_the_mis_bound_expression(): void
    {
        $source = (string) file_get_contents(base_path(self::VENDORED_LISTENER));

        $this->assertStringContainsString(
            "\$requests_data['club_point_is_active'] ?? '' == 'on' && \$requests_data['final_amount_point'] == \$order['final_price']",
            $source,
            'The vendored guard this slice protects against has changed. Re-measure before trusting '
            .'NormaliseRewardRedemptionFlag: if WorkDo parenthesised the condition, the `off` branch '
            .'may already be fixed upstream and this listener may no longer match its semantics.'
        );

        // AND THE MIS-BINDING IS THE POINT, not merely the text. No parenthesis closes the `??`
        // before the `&&`, which is what makes the right operand dead.
        $this->assertDoesNotMatchRegularExpression(
            "/\\(\\s*\\\$requests_data\\['club_point_is_active'\\]\\s*\\?\\?\\s*''\\s*\\)/",
            $source,
            'The `??` is now parenthesised, so the expression no longer mis-binds.'
        );
    }

    public function test_premise_the_vendored_guard_reads_off_as_a_redemption(): void
    {
        $order = ['final_price' => 100.0];

        // THE WHOLE JUSTIFICATION FOR THIS SLICE, executed rather than argued.
        $this->assertTrue(
            $this->guardVerdict(['club_point_is_active' => 'off'], $order),
            "'off' must currently reach the redemption branch; if it does not, this slice is moot."
        );
        $this->assertTrue($this->guardVerdict(['club_point_is_active' => 'on'], $order));

        // The anti-tamper comparison is dead: a mismatched total changes nothing.
        $this->assertTrue($this->guardVerdict(
            ['club_point_is_active' => 'on', 'final_amount_point' => 7.0],
            $order
        ), 'final_amount_point is in the dead operand, so a forged total is not refused.');

        // And these already take the accrual branch, so normalising to "absent" is a no-op for them.
        $this->assertFalse($this->guardVerdict([], $order));
        $this->assertFalse($this->guardVerdict(['club_point_is_active' => ''], $order));
        $this->assertFalse($this->guardVerdict(['club_point_is_active' => '0'], $order));
        $this->assertFalse($this->guardVerdict(['club_point_is_active' => null], $order));
    }

    /**
     * THIS TEST REPLACES ONE THAT COULD NOT FAIL, and the replacement is the point.
     *
     * The first version asserted that four strings existed in four files. Reverting the entire fix
     * left it green; so did deleting the live checkout page. Its NAME stated a claim about
     * production behaviour while its BODY tested only that some text was on disk — defect shape 7
     * in this project's own skill file, written by me on the same day I wrote that rule down.
     *
     * AND THE CLAIM IT MADE WAS FALSE. It traced the flag through
     * `clubPointFormField.blade.php` -> `checkout_hide_field.blade.php` ->
     * `common/checkout/checkout_form.blade.php:125`. **Nothing renders that last file.** Its only
     * reference anywhere is LynomiaChat's ViewComposer naming it as a composer target, which
     * composes a view that is never resolved. The live checkout includes a DIFFERENT file of
     * nearly the same name — `front_end/hooks/checkout_form.blade.php` — holding one unrelated
     * stack. So `clubPointFormField` never reaches a browser, and neither does its
     * `final_amount_point`.
     *
     * THE REAL CHAIN, every link asserted below and each able to fail:
     *
     *     clubPointApply.blade.php:1-9     eligibility gate: six settings present, AND
     *                                      total_club_point >= checkout_points, AND
     *                                      final_price >= checkout_price
     *     clubPointApply.blade.php:25      <input … name="club_point_is_active" value="off">
     *     checkout_list.blade.php:2        @stack('clubPointApply')
     *     checkout-cart-list.blade.php:97  @include('front_end.hooks.checkout_list')
     *     CartController.php:114           renders that view into checkout_html_2, with `store`
     *                                      and `response` — the two keys ViewComposer:27 requires
     *     front-theme.js:224               $('.checkout_page_cart').html(response.checkout_html_2)
     *     checkout.blade.php:25/256/263    .checkout_page_cart sits BETWEEN Form::open and
     *                                      Form::close, so the injected input is submitted
     *
     * SO THE CORRECTED SEVERITY IS NARROWER THAN FIRST CLAIMED: the destructive path fires for a
     * logged-in customer **who already qualifies to redeem** and did not press Apply — not on
     * every checkout. That is still the population whose balance is worth destroying, and it
     * degenerates to every logged-in checkout when `checkout_points` and `checkout_price` are
     * configured at 0, which is savable because `RewardClubPointController`'s rules are a bare
     * `required` and Laravel's `required` passes 0. A hand-crafted POST reaches the flag whatever
     * the form renders, so the fix is right either way.
     */
    public function test_premise_the_flag_is_posted_only_when_the_customer_already_qualifies(): void
    {
        $apply = (string) file_get_contents(base_path(
            'packages/workdo/RewardClubPoint/src/resources/views/theme/clubPointApply.blade.php'
        ));

        // The input that actually posts.
        $this->assertStringContainsString('name="club_point_is_active" value="off"', $apply);

        // AND IT IS INSIDE THE ELIGIBILITY GATE. Asserted by position, not by presence: if the
        // input is ever moved above the `@if`, this fails and the severity has changed.
        $gate = strpos($apply, "\$customer->total_club_point >= \$setting['checkout_points']");
        $input = strpos($apply, 'name="club_point_is_active"');

        $this->assertIsInt($gate, 'The eligibility gate is gone from clubPointApply.blade.php.');
        $this->assertIsInt($input, 'The club_point_is_active input is gone from clubPointApply.blade.php.');
        $this->assertGreaterThan($gate, $input, 'The flag input is no longer inside the eligibility gate.');
        $this->assertStringContainsString("\$response->data->final_price >= \$setting['checkout_price']", $apply);

        // The stack that renders it, and the view that includes that stack.
        $this->assertStringContainsString("@stack('clubPointApply')", (string) file_get_contents(
            base_path('resources/views/front_end/hooks/checkout_list.blade.php')
        ));
        $this->assertStringContainsString("@include('front_end.hooks.checkout_list')", (string) file_get_contents(
            base_path('resources/views/front_end/pages/checkout-cart-list.blade.php')
        ));

        // Rendered WITH `store` and `response`, which is what ViewComposer:27 requires before it
        // will push clubPointApply at all.
        $cart = (string) file_get_contents(base_path('app/Http/Controllers/CartController.php'));
        $this->assertMatchesRegularExpression(
            "/checkout_html_2'\]\s*=\s*view\('front_end\.pages\.checkout-cart-list',\s*compact\([^)]*'response'[^)]*'store'/",
            $cart,
            'checkout-cart-list is no longer rendered with both `response` and `store`.'
        );

        // And it lands INSIDE the posting form.
        $checkout = (string) file_get_contents(base_path('resources/views/front_end/pages/checkout.blade.php'));
        $open = strpos($checkout, 'Form::open');
        $target = strpos($checkout, 'checkout_page_cart');
        $close = strpos($checkout, 'Form::close');

        $this->assertIsInt($open);
        $this->assertIsInt($target);
        $this->assertIsInt($close);
        $this->assertGreaterThan($open, $target, 'The AJAX target is no longer inside the checkout form.');
        $this->assertLessThan($close, $target, 'The AJAX target is no longer inside the checkout form.');
    }

    public function test_premise_the_other_hidden_field_is_on_a_path_nothing_renders(): void
    {
        // `clubPointFormField.blade.php` carries the SAME input, and an earlier version of this
        // suite cited it as the production path. It is not: the only `@stack('clubPointFormField')`
        // lives in `checkout_hide_field.blade.php`, which is included only by
        // `common/checkout/checkout_form.blade.php`, which NOTHING renders.
        //
        // Pinned so that reviving that view is a deliberate act with a red test attached: if it is
        // ever included or returned, the flag starts posting UNGATED and the severity of the
        // vendored defect goes back up to "every logged-in checkout".
        $needle = 'common.checkout.checkout_form';
        $alt = 'common/checkout/checkout_form';
        $renderers = [];

        foreach ([base_path('app'), base_path('resources/views'), base_path('packages/workdo')] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                if (! str_contains($src, $needle) && ! str_contains($src, $alt)) {
                    continue;
                }
                foreach (explode("\n", $src) as $n => $line) {
                    // Prose must not be able to satisfy this. Blade files are not reliably
                    // tokenisable (a `{{-- --}}` comment is T_INLINE_HTML), so this uses the cheap
                    // prefix filter rather than codeWithoutComments().
                    $trimmed = ltrim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                        || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*')) {
                        continue;
                    }
                    if ((str_contains($line, $needle) || str_contains($line, $alt))
                        && (str_contains($line, '@include') || preg_match('/\bview\s*\(/', $line))) {
                        $renderers[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($n + 1).'  '.trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $renderers,
            "common/checkout/checkout_form.blade.php is now rendered somewhere. The clubPointFormField\n"
            ."path is live again and the severity analysis in this file is stale:\n  ".implode("\n  ", $renderers)
        );
    }

    /** @return list<string> */
    private function registrations(): array
    {
        return array_map(
            static fn ($l) => is_string($l) ? $l : (is_array($l) ? (string) ($l[0] ?? '') : get_debug_type($l)),
            Event::getRawListeners()[AddRewardClubPoint::class] ?? []
        );
    }

    public function test_premise_the_listener_is_registered_on_the_event(): void
    {
        $this->assertTrue(
            Event::hasListeners(AddRewardClubPoint::class),
            'Nothing listens to AddRewardClubPoint, so the normalisation never runs.'
        );

        // AT LEAST ONE registration must name the class, and the assertion is deliberately NOT
        // `assertContains(::class)`: Laravel's two registration forms spell it differently --
        // `Foo` for `Event::listen(Foo::class)` and `Foo@handle` for a discovered listener -- and
        // an earlier version of this test asserted only the first. A mutation deleting the
        // explicit registration then reddened THIS test while the behavioural test stayed green,
        // which is how the duplicate registration was found at all.
        $naming = array_values(array_filter(
            $this->registrations(),
            static fn (string $l) => str_starts_with($l, NormaliseRewardRedemptionFlag::class)
        ));

        $this->assertNotEmpty($naming, 'No registration names the listener: '.implode(', ', $this->registrations()));
    }

    public function test_premise_the_registration_is_doubled_and_that_is_deliberate(): void
    {
        // TWO registrations, by two different mechanisms, and the docblock explains why both stay:
        //
        //   NormaliseRewardRedemptionFlag          explicit, AppServiceProvider::boot()
        //   NormaliseRewardRedemptionFlag@handle   Laravel 11 event discovery over app/Listeners
        //
        // `Application::configure()` calls `->withEvents()` unconditionally and the framework
        // EventServiceProvider it registers has discovery ON -- even though
        // `App\Providers\EventServiceProvider::shouldDiscoverEvents()` returns FALSE, which applies
        // only to the app's own instance. If this count moves, the registration story changed:
        // read the listener docblock before renumbering it.
        $naming = array_values(array_filter(
            $this->registrations(),
            static fn (string $l) => str_starts_with($l, NormaliseRewardRedemptionFlag::class)
        ));

        $this->assertSame(
            [NormaliseRewardRedemptionFlag::class, NormaliseRewardRedemptionFlag::class.'@handle'],
            $naming,
            'Expected exactly the explicit registration and the discovered one, in that order.'
        );
    }

    public function test_the_handler_is_idempotent_because_it_is_registered_twice(): void
    {
        foreach ([['club_point_is_active' => 'off'], ['club_point_is_active' => 'on'], ['club_point_is_active' => true], []] as $payload) {
            $once = $this->normalise($payload);
            $twice = $this->normalise($once);

            $this->assertSame($once, $twice, 'A second pass must change nothing: the listener runs twice per dispatch.');
        }
    }

    public function test_a_listener_registered_the_way_module_providers_arrive_sees_the_normalised_array(): void
    {
        // THE LOAD-BEARING ORDERING CLAIM, exercised rather than argued. The vendored listener
        // arrives when its module's EventServiceProvider is registered, and module providers are
        // brought in from `app->booted()` -- after this listener is registered. A probe registered
        // the same way must therefore observe the normalised array.
        $seen = 'PROBE NEVER RAN';

        $this->app->booted(function () use (&$seen) {
            Event::listen(AddRewardClubPoint::class, function (AddRewardClubPoint $e) use (&$seen) {
                $seen = $e->requests_data['club_point_is_active'] ?? 'ABSENT';
            });
        });

        $event = new AddRewardClubPoint(
            ['club_point_is_active' => 'off', 'final_amount_point' => 0],
            ['id' => 1, 'final_price' => 100.0],
            'a-slug'
        );

        Event::dispatch($event);

        $this->assertSame('ABSENT', $seen, 'A later listener still saw the raw flag, so the normalisation is useless.');
    }

    // ---------------------------------------------------------------------------------------------
    // The normalisation itself.
    // ---------------------------------------------------------------------------------------------

    public function test_a_flag_of_off_is_stripped_so_the_vendored_guard_takes_the_accrual_branch(): void
    {
        $out = $this->normalise(['club_point_is_active' => 'off', 'final_amount_point' => 0]);

        $this->assertArrayNotHasKey('club_point_is_active', $out);

        // THE ASSERTION THAT MATTERS: not "the key is gone" but "the vendored verdict changed".
        $this->assertFalse(
            $this->guardVerdict($out, ['final_price' => 100.0]),
            'Stripping the key must flip the vendored guard to the accrual branch.'
        );
    }

    public function test_a_flag_of_on_is_preserved_and_still_redeems(): void
    {
        $out = $this->normalise(['club_point_is_active' => 'on', 'final_amount_point' => 100.0]);

        $this->assertSame('on', $out['club_point_is_active']);
        $this->assertTrue($this->guardVerdict($out, ['final_price' => 100.0]));
    }

    public function test_an_absent_flag_stays_absent(): void
    {
        $out = $this->normalise(['payment_type' => 'cod']);

        $this->assertArrayNotHasKey('club_point_is_active', $out);
        $this->assertSame('cod', $out['payment_type']);
    }

    public function test_final_amount_point_is_stripped_alongside_the_flag(): void
    {
        // It is stripped so that a FUTURE upstream precedence fix cannot read a stale total. With
        // the flag absent, `'' == 'on'` short-circuits the `&&`, so the key is never touched and
        // removing it cannot introduce an undefined-key fatal.
        $out = $this->normalise(['club_point_is_active' => 'off', 'final_amount_point' => 4242.0]);

        $this->assertArrayNotHasKey('final_amount_point', $out);
    }

    public function test_final_amount_point_is_kept_when_the_flag_is_kept(): void
    {
        $out = $this->normalise(['club_point_is_active' => 'on', 'final_amount_point' => 4242.0]);

        $this->assertSame(4242.0, $out['final_amount_point']);
    }

    public function test_a_loosely_equal_flag_is_kept_because_every_first_party_site_compares_loosely(): void
    {
        // An API client posting JSON `true`: `true == 'on'` is TRUE in PHP 8, so all eleven
        // first-party sites apply the discount. Stripping it here would debit no points.
        $this->assertTrue(true == 'on', 'premise: PHP 8 loose-compares boolean true to a non-empty string as true');

        $out = $this->normalise(['club_point_is_active' => true]);

        $this->assertArrayHasKey('club_point_is_active', $out);
        $this->assertSame('on', $out['club_point_is_active'], 'A kept flag is canonicalised to the literal "on".');
        $this->assertTrue($this->guardVerdict($out, ['final_price' => 100.0]));
    }

    public function test_values_the_first_party_sites_refuse_are_stripped(): void
    {
        foreach (['off', '', '0', 0, null, 'ON ', 'yes', 'true'] as $value) {
            $out = $this->normalise(['club_point_is_active' => $value]);

            $this->assertArrayNotHasKey(
                'club_point_is_active',
                $out,
                sprintf('%s is not loosely equal to "on", so no first-party site applies a discount for it.', var_export($value, true))
            );
        }
    }

    public function test_nothing_else_in_the_payload_is_touched(): void
    {
        $in = [
            'club_point_is_active' => 'off',
            'final_amount_point' => 0,
            'payment_type' => 'cod',
            'coupon_code' => 'SAVE10',
            'billing_info' => ['email' => 'shopper@example.test'],
            'cart' => [['id' => 7, 'qty' => 2]],
        ];

        $out = $this->normalise($in);

        unset($in['club_point_is_active'], $in['final_amount_point']);
        $this->assertSame($in, $out, 'The listener must remove two keys and change nothing else.');
    }

    public function test_a_non_array_payload_is_left_alone(): void
    {
        // The event's constructor is untyped, so a non-array is not excluded by the type system
        // even though no live dispatch site sends one. Measured: 'a string' and 42 throw
        // `Error: Cannot unset ...` at the listener's unset() without the is_array() guard; null
        // does NOT, and is included here only to pin that it passes through unchanged.
        foreach ([null, 'a string', 42] as $payload) {
            $event = new AddRewardClubPoint($payload, ['id' => 1], 'a-slug');
            (new NormaliseRewardRedemptionFlag)->handle($event);

            $this->assertSame($payload, $event->requests_data);
        }
    }

    public function test_the_real_dispatcher_normalises_the_event(): void
    {
        // Not the listener called by hand: the registered listener, through the real dispatcher.
        // This is what proves the registration is load-bearing rather than decorative.
        $event = new AddRewardClubPoint(
            ['club_point_is_active' => 'off', 'final_amount_point' => 0, 'payment_type' => 'cod'],
            ['id' => 1, 'final_price' => 100.0],
            'a-slug'
        );

        Event::dispatch($event);

        $this->assertArrayNotHasKey('club_point_is_active', $event->requests_data);
        $this->assertSame('cod', $event->requests_data['payment_type']);
    }

    /**
     * The file's code with every comment blanked out, LINE NUMBERS PRESERVED.
     *
     * A source-grep ratchet that does not do this is satisfiable by prose, and in this family that
     * is not hypothetical — it has now fired three times in one working session. The most recent
     * was this very test: the sentence in `NormaliseRewardRedemptionFlag`'s docblock explaining
     * that nine `event(new AddRewardClubPoint(` sites exist pushed the census to TEN. Same shape as
     * the `applyTo(` count in `RewardDiscountCapTest`, which an independent review PREDICTED and
     * which then fired within minutes from a comment added in the same change.
     *
     * Each comment token is replaced by its own newlines rather than dropped, so a hit's reported
     * line still matches the real file.
     */
    private static function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /** @return list<array{0:string,1:int,2:string}> path, line, text */
    private function grepTree(array $roots, string $needle): array
    {
        $hits = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (! str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                    continue;  // cheap pre-filter; the tokenised read below is the authority
                }
                foreach (explode("\n", self::codeWithoutComments($file->getPathname())) as $n => $line) {
                    if (str_contains($line, $needle)) {
                        $hits[] = [str_replace(base_path().'/', '', $file->getPathname()), $n + 1, trim($line)];
                    }
                }
            }
        }

        usort($hits, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $hits;
    }

    public function test_every_construction_of_the_event_goes_through_the_dispatcher(): void
    {
        // The listener is a single interception point only if EVERY construction of the event is
        // dispatched. A `new AddRewardClubPoint(...)` handed straight to the vendored listener
        // would bypass it entirely.
        //
        // SCANS `packages/` AS WELL AS `app/`. An earlier version scanned only `app/` while its
        // name and comment claimed "every" -- and `packages/workdo/Campaigns/.../CampaignsPaymentService.php`
        // already imports this event (unused), so a module dispatch is the likeliest way this
        // becomes wrong.
        $roots = [base_path('app'), base_path('packages')];

        $constructions = $this->grepTree($roots, 'new AddRewardClubPoint(');
        $label = static fn (array $h) => $h[0].':'.$h[1];

        $this->assertCount(
            9,
            $constructions,
            "Expected the nine known dispatch sites. Found:\n  ".implode("\n  ", array_map($label, $constructions))
        );

        foreach ($constructions as $hit) {
            $this->assertStringContainsString(
                'event(new AddRewardClubPoint(',
                $hit[2],
                $label($hit).' constructs the event without dispatching it, so the listener cannot see it: '.$hit[2]
            );
        }

        // And nothing calls the vendored listener directly, which would also bypass the dispatcher.
        $direct = $this->grepTree($roots, 'new AddRewardClubPointLis');
        $this->assertSame(
            [],
            array_map($label, $direct),
            'The vendored listener is instantiated directly somewhere; the normalisation is bypassed there.'
        );
    }
}
