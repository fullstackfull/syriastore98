<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F271 — the validation errors were in the response and invisible on the screen.
 * =================================================================================================
 *
 * WS F asks that a merchant be told what went wrong when a form refuses their input. These five
 * screens did everything right except the one thing that makes it visible.
 *
 * `OrderController::store()` validates and returns `redirect()->back()->withErrors($validator)
 * ->withInput()` (:108-110). The form re-renders. Each field carries
 *
 *     @error('customer_id')
 *         <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
 *     @enderror
 *
 * So the message really is in the HTML. AND IT IS `display: none`. The shipped theme stylesheet
 * says so, measured rather than assumed — `public/assets/css/style.css:3297-3303`:
 *
 *     .invalid-feedback { display: none; ... }
 *
 * and the only rules that ever show it again are at `:3320-3325`:
 *
 *     .was-validated :invalid~.invalid-feedback,
 *     .is-invalid~.invalid-feedback { display: block; }
 *
 * `is-invalid` appears **zero times** in all five views, and none of the forms carries
 * `was-validated`. So the merchant submits a manual order, is bounced back to a form that looks
 * exactly as they left it, and is told nothing at all. Submitting again does the same. There is no
 * error, no toast, no summary — the screen simply refuses to move.
 *
 * -------------------------------------------------------------------------------------------------
 * THE FIRST CENSUS WAS WRONG, AND IT WOULD HAVE SENT ME TO FIX THREE SCREENS THAT ARE NOT BROKEN.
 *
 * Counting the string `invalid-feedback` found five views. Two of those counts are real and three
 * are not, because the string serves two unrelated purposes in this codebase:
 *
 *   SERVER-SIDE, inside an `@error(...)` block — a Laravel validation message. THIS is the defect.
 *       resources/views/order/createOrder.blade.php   13 messages
 *       resources/views/customer/create.blade.php      6 messages
 *
 *   STATIC, with no `@error` and no server error behind it — Bootstrap's CLIENT-side validation
 *   idiom, a bare `<div class="invalid-feedback">{{ __('invalid form file') }}</div>` beside a
 *   file input, which only `was-validated` could ever reveal and no form here sets:
 *       resources/views/product/create.blade.php       2
 *       resources/views/product/edit.blade.php         2
 *       resources/views/product/show.blade.php         2
 *
 * Those six are DEAD MARKUP, not hidden feedback. Bolting `is-invalid` onto a file input would
 * have been a fix to nothing — and on `product/show`, a READ-ONLY view, a validation message could
 * never have been meaningful in the first place. Recorded as F272 and deliberately left alone;
 * deleting markup across three product views is a wider change than this slice.
 *
 * So the population here is the TWO views that really render hidden server-side messages.
 * -------------------------------------------------------------------------------------------------
 *
 * WHY THIS IS A STRUCTURAL TEST AND NOT A SCREENSHOT. `display: none` is not visible to PHPUnit,
 * and a test that only asserted the message text is PRESENT passes against the defect — which is
 * exactly how this shipped. What can be asserted is the structure the stylesheet requires: the
 * control that failed carries `is-invalid`, and the message is a following sibling of it. The
 * rendered appearance is checked separately in the browser harness.
 */
class ValidationFeedbackVisibilityTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The two views that really render server-side validation messages, and how many each has. */
    private const AFFECTED_VIEWS = [
        'resources/views/order/createOrder.blade.php' => 13,
        'resources/views/customer/create.blade.php' => 6,
    ];

    /** The six dead client-side placeholders, frozen so no new one appears unnoticed (F272). */
    private const DEAD_PLACEHOLDERS = [
        'resources/views/product/create.blade.php' => 2,
        'resources/views/product/edit.blade.php' => 2,
        'resources/views/product/show.blade.php' => 2,
    ];

    /**
     * BLADE COMMENTS STRIPPED BEFORE COUNTING, and this is not hypothetical: the comment this
     * change adds above each form explains the defect and therefore CONTAINS the words
     * `invalid-feedback` and `is-invalid`. Counting them inflated both figures by one the first
     * time it ran. The project met the same trap counting `confirm(` in P2-01-19, where a fifth
     * match lived inside a comment describing that very defect.
     */
    private function source(string $view): string
    {
        return (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(base_path($view))
        );
    }

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        foreach (['Manage Order', 'Show Order', 'Create Order', 'Manage Customer', 'Create Customer'] as $permission) {
            $this->grantPermission(User::find($this->merchant->id), $permission);
        }

        $this->merchant = User::find($this->merchant->id);
    }

    // =============================================================================================
    //  1. THE STYLESHEET'S OWN RULE, measured — the premise everything below rests on
    // =============================================================================================

    /**
     * If the theme ever stops hiding `.invalid-feedback`, the whole premise of this file changes
     * and the structural assertions below would be guarding nothing. So the rule is measured here
     * rather than quoted in a docblock.
     */
    public function test_the_theme_hides_invalid_feedback_unless_a_sibling_is_marked(): void
    {
        $css = (string) file_get_contents(base_path('public/assets/css/style.css'));

        $this->assertMatchesRegularExpression(
            '/\.invalid-feedback\s*\{\s*display:\s*none/',
            $css,
            'the theme no longer hides .invalid-feedback, so an unmarked field may now show its '
            . 'message and this file is reasoning about a stylesheet that has changed'
        );

        $this->assertMatchesRegularExpression(
            '/\.is-invalid\s*~\s*\.invalid-feedback/',
            $css,
            'the theme no longer reveals .invalid-feedback for an .is-invalid sibling, so adding '
            . 'that class is no longer the fix and this file must be rewritten'
        );
    }

    // =============================================================================================
    //  2. THE STRUCTURE EVERY AFFECTED VIEW MUST HAVE
    // =============================================================================================

    /**
     * Every `invalid-feedback` must have an `is-invalid` to be revealed by.
     *
     * Counted per view rather than repo-wide: a view that renders N messages and marks N-1 fields
     * leaves one merchant permanently in the dark, and a repo total would hide it.
     */
    public function test_every_hidden_message_now_has_a_marked_field_to_reveal_it(): void
    {
        foreach (self::AFFECTED_VIEWS as $view => $expectedMessages) {
            $source = $this->source($view);

            $messages = substr_count($source, 'invalid-feedback');
            $marks = preg_match_all('/is-invalid/', $source);

            $this->assertSame($expectedMessages, $messages,
                "{$view} renders {$messages} validation messages, not the {$expectedMessages} "
                . 'this file was written against. Re-census before trusting the count below.');

            $this->assertGreaterThanOrEqual($messages, $marks,
                "{$view} renders {$messages} `invalid-feedback` messages but marks only {$marks} "
                . 'fields with `is-invalid`. The theme hides an unmarked message outright '
                . '(style.css:3297), so a merchant on this screen is refused with no reason '
                . 'given at all.');
        }
    }

    /**
     * AND THE MARK MUST BE CONDITIONAL. A permanently-applied `is-invalid` would satisfy the count
     * above while painting every field red on a form nobody has submitted yet.
     */
    public function test_the_mark_is_conditional_on_that_field_having_an_error(): void
    {
        foreach (array_keys(self::AFFECTED_VIEWS) as $view) {
            $source = $this->source($view);

            /*
             * THE TWO IDIOMS THIS CODEBASE USES, and they have to be told apart from a literal:
             *
             *   raw markup:  class="form-control @error('city') is-invalid @enderror"
             *   Form:: call: 'class' => 'form-control' . ($errors->has('city') ? ' is-invalid' : '')
             *
             * Both put the text `is-invalid` inside what looks like a class string, so a regex
             * that merely forbade that would fail against the fix itself — which is what the
             * first version of this assertion did. What distinguishes a literal is that nothing
             * conditional precedes it on that line.
             */
            /*
             * AND THE LOOP HAS TO HAVE SOMETHING TO LOOK AT. Without this, a view carrying no
             * `is-invalid` at all runs the loop zero times and the test passes having asserted
             * NOTHING -- PHPUnit reported it as "risky" against the unfixed views, which is the
             * only reason it was caught. An undeclared risky outcome also breaks the
             * known-outcome gate, so the premise is asserted rather than assumed.
             */
            $this->assertStringContainsString('is-invalid', $source,
                "{$view} marks no field at all, so this test has nothing to check and the "
                . 'conditionality it claims to guard is unmeasured');

            foreach (preg_split('/\R/', $source) as $number => $line) {
                if (! str_contains($line, 'is-invalid')) {
                    continue;
                }

                $before = substr($line, 0, strpos($line, 'is-invalid'));

                $this->assertTrue(
                    str_contains($before, '@error(') || str_contains($before, "errors->has('"),
                    "{$view} line " . ($number + 1) . ' applies `is-invalid` unconditionally, so '
                    . 'the field renders as failed before the merchant has submitted anything: '
                    . trim($line)
                );
            }
        }
    }

    /**
     * AND EVERY AFFECTED FORM CARRIES THE SUMMARY, so a merchant whose failing field is scrolled
     * out of view still learns that the submit was refused. `product/create` and `product/edit`
     * already had it; the other three had nothing by any route.
     */
    public function test_every_affected_screen_renders_the_error_summary(): void
    {
        foreach (array_keys(self::AFFECTED_VIEWS) as $view) {
            $source = $this->source($view);

            $this->assertStringContainsString('x-ss.error-summary', $source,
                "{$view} renders no error summary, so a merchant whose failing field is below the "
                . 'fold is given no indication that the form was refused at all');
        }
    }

    /**
     * F272, FROZEN RATHER THAN FIXED. The six static placeholders carry no `@error`, so there is
     * no server-side message behind them and nothing to reveal; they are Bootstrap's client-side
     * idiom in a codebase that never sets `was-validated`. Counting them as the same defect is
     * what made the first census wrong.
     *
     * The count is held as an EQUALITY so a new one cannot appear unnoticed and so a future pass
     * that deletes them has to come here and say so.
     */
    public function test_the_dead_client_side_placeholders_are_still_exactly_six(): void
    {
        foreach (self::DEAD_PLACEHOLDERS as $view => $expected) {
            $source = $this->source($view);

            $this->assertSame($expected, substr_count($source, 'invalid-feedback'),
                "{$view} no longer holds {$expected} static `invalid-feedback` placeholders. If "
                . 'they were removed, update this list; if one was ADDED, it is dead markup that '
                . 'can never display.');

            $this->assertSame(0, preg_match_all('/@error\(/', $source),
                "{$view} now renders a real `@error` message, so it has joined AFFECTED_VIEWS "
                . 'and needs an `is-invalid` mark and a summary rather than this freeze');
        }
    }

    // =============================================================================================
    //  3. DRIVEN, not just read — the manual order form really does come back marked
    // =============================================================================================

    /**
     * The whole round trip: submit an order with no customer and no products, follow the redirect,
     * and read what the merchant is actually shown.
     */
    public function test_a_refused_manual_order_comes_back_with_a_visible_reason(): void
    {
        /* THE PREMISE. A 403 or a 500 satisfies "the form does not create an order" for free. */
        $form = $this->actingAs($this->merchant)->get(route('order.create'));
        $this->assertSame(200, $form->getStatusCode(),
            'the manual order form did not render for this fixture, so nothing below measures it: '
            . substr((string) $form->getContent(), 0, 300));

        $refused = $this->actingAs($this->merchant)
            ->from(route('order.create'))
            ->post(route('order.store'), [
                'customer_id' => '',
                'products' => [],
                'payment_type' => '',
            ]);

        $refused->assertRedirect(route('order.create'));
        $refused->assertSessionHasErrors('customer_id');

        $body = (string) $this->actingAs($this->merchant)->get(route('order.create'))->getContent();

        $this->assertStringContainsString('ss-errorsummary', $body,
            'the refused submit renders no error summary, so the merchant sees a form that simply '
            . 'refuses to move');

        /* THE STRUCTURAL ASSERTION. The message is in the HTML either way; what decides whether
           the merchant can SEE it is whether the control that failed carries `is-invalid`. */
        $this->assertMatchesRegularExpression(
            '/<select[^>]*\bname="customer_id"[^>]*\bclass="[^"]*\bis-invalid\b/',
            $body,
            'the customer field was not marked `is-invalid`, so its `invalid-feedback` message is '
            . 'display:none (style.css:3297) and the merchant is told nothing'
        );
    }

    /**
     * AND A FORM NOBODY HAS SUBMITTED IS NOT PAINTED RED. The mark has to be conditional in the
     * rendered output, not only in the source.
     */
    public function test_a_fresh_form_carries_no_failure_marks(): void
    {
        $body = (string) $this->actingAs($this->merchant)->get(route('order.create'))->getContent();

        $this->assertStringNotContainsString('is-invalid', $body,
            'a form the merchant has not submitted yet is rendering fields as failed');
        $this->assertStringNotContainsString('ss-errorsummary', $body,
            'a form the merchant has not submitted yet is showing an error summary');
    }

    // =============================================================================================
    //  4. P2.5 finance-validation-failure-is-silent — THE SAME DEFECT ON THE MONEY SCREEN
    // =============================================================================================

    /*
     * WHAT WAS MEASURED. `grep -E '@error|old\(|error-summary|\$errors'` over the 647 lines of
     * resources/views/finance/index.blade.php returned ZERO matches, while
     * StoreFinanceController::addBalance (:671), deductBalance (:791) and requestWithdrawal (:916)
     * each call `$request->validate()`. A ValidationException redirects back to /finance with the
     * bag populated, and the view reads none of it. The layout's only flash renderers are
     * `Session::has('success')` (layouts/app.blade.php:760) and `Session::has('error')` (:773) --
     * a ValidationException populates NEITHER -- and resources/views/layouts/includes/alerts.blade.php,
     * the one partial that would toast `session('errors')`, is included nowhere (repo-wide grep:
     * zero hits, and it must stay that way: it interpolates each message UNESCAPED into a
     * single-quoted JS literal at :8, the P1-SEC-22(b) sink that app.blade.php:762-767 records as
     * deliberately fixed).
     *
     * WHAT LANDS HERE, AND WHAT DOES NOT. The full repair -- validateWithBag in three handlers,
     * nine rewritten controls, three bag-scoped summaries, a modal-reopen script -- is a four-part
     * change on the money surface and is DEFERRED to its own slice. Two measurements decided that:
     *
     *   (a) THE REACHABLE SET IS FOUR FIELDS. Every server rule on these three forms except four
     *       string `max:` rules is already browser-enforced: `amount` is
     *       `type="number" required min="0.01" step="0.01"` (and `max` on two of the three) against
     *       `required|numeric|min:0.01`; `payment_method` is a required select with three fixed
     *       values against `required|string|max:100`; the free-text controls are all `required`.
     *       So the only demonstrated trigger is an over-length paste into `description` (max:255),
     *       `payment_details` (max:2000) or `store_note` (max:500) -- and `maxlength` closes all
     *       four, which is WCAG 3.3.3 (Error Suggestion) rather than a workaround.
     *
     *   (b) THE OBVIOUS @error REPAIR DEFEATS ITSELF IN DARK MODE, so it must not be rushed.
     *       public/css/store-syria-ds.css:3902-3905 already restyles `.invalid-feedback`
     *       unconfined, and under `[data-ss-theme="dark"]` `--ss-danger` is `#f3a9a4` (:428) --
     *       1.91:1 on the WHITE modal the host keeps in dark mode. That is pinned, not assumed, by
     *       DarkModalSurfaceContrastTest (`--bs-modal-bg: #ffffff` in both dark sheets,
     *       MODAL_BG = [255,255,255]). So nine `.invalid-feedback` messages inside those three
     *       modals would still tell a dark-mode merchant nothing, and NO test would have gone red.
     *       `data-ss-theme="light"` on each `.modal-content` is the one-attribute cure, with ten
     *       in-repo precedents (category/create:49, users/create:23, users/edit:14, users/reset:14,
     *       attributes/create:19, attribute_option/create:19 ...), and it lands NOW so the deferred
     *       slice cannot ship the 1.91:1 version by default.
     *
     * finance/index.blade.php is deliberately NOT added to AFFECTED_VIEWS: :184 holds the
     * `invalid-feedback` count as an EQUALITY, a literal standing in for a property, and this
     * change renders no `invalid-feedback` at all. The silence is pinned explicitly instead, so the
     * bag slice inherits a FAILING test to turn green rather than a passing one.
     */

    /** The four free-text controls, their server rule, and the cap the markup must carry. */
    private const FINANCE_TEXT_CAPS = [
        'addBalanceDescription' => 255,
        'deductBalanceDescription' => 255,
        'withdrawalPaymentDetails' => 2000,
        'withdrawalNote' => 500,
    ];

    private function financeMerchant(): User
    {
        [$merchant] = $this->makeTenant();

        foreach ([
            'View Finance Dashboard', 'Add Finance Balance',
            'Deduct Finance Balance', 'Request Finance Withdrawal',
        ] as $name) {
            \App\Models\Permission::firstOrCreate(['name' => $name], ['display_name' => $name]);
            $this->grantPermission(User::find($merchant->id), $name);
        }

        \Illuminate\Support\Facades\Cache::forget('admin_details');

        return User::find($merchant->id);
    }

    /**
     * WCAG 2.x contrast, computed rather than quoted — the same shape DarkModalSurfaceContrastTest
     * uses, repeated here because that file is not this chain's to edit.
     */
    private function contrast(array $a, array $b): float
    {
        $luminance = function (array $rgb): float {
            $channel = function (int $v): float {
                $c = $v / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
        };

        $la = $luminance($a);
        $lb = $luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * STEP 1 — the 3.3.3 caps, copied from the rules and not chosen.
     *
     * With these the only demonstrated route to the silent redirect is unreachable from the UI.
     */
    public function test_the_finance_money_modals_cap_free_text_at_the_server_limit(): void
    {
        $source = $this->source('resources/views/finance/index.blade.php');
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/StoreFinanceController.php'));

        /* THE PREMISE: the caps really are the server's own numbers. */
        $this->assertStringContainsString("'required|string|max:255'", $controller,
            'the description rule is no longer max:255, so the maxlength asserted below no longer '
            . 'mirrors the server and would truncate at the wrong length');
        $this->assertStringContainsString("'required|string|max:2000'", $controller,
            'the payment_details rule is no longer max:2000');
        $this->assertStringContainsString("'nullable|string|max:500'", $controller,
            'the store_note rule is no longer max:500');

        foreach (self::FINANCE_TEXT_CAPS as $id => $cap) {
            $this->assertMatchesRegularExpression(
                '/<textarea[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*\bmaxlength="' . $cap . '"/',
                $source,
                "the {$id} textarea carries no maxlength=\"{$cap}\", so a longer paste is accepted "
                . 'by the browser, refused by the server, and the merchant is redirected back to '
                . 'a form that looks exactly as they left it and says nothing (WCAG 2.1 A 3.3.1, '
                . 'AA 3.3.3)'
            );
        }
    }

    /**
     * STEP 2 — name the surface. The host keeps `.modal-content` WHITE in dark mode, so every
     * design-system token inside one resolves its DARK value onto white.
     */
    public function test_the_three_finance_money_modals_name_the_white_modal_surface(): void
    {
        $source = $this->source('resources/views/finance/index.blade.php');

        $this->assertSame(3, substr_count($source, '<div class="modal-content" data-ss-theme="light">'),
            'the three finance money modals do not all carry data-ss-theme="light" on their '
            . '.modal-content. The host paints that surface WHITE even in dark mode (pinned by '
            . 'DarkModalSurfaceContrastTest), so --ss-danger resolves to #f3a9a4 at 1.91:1 on it '
            . 'and any validation message placed inside would be unreadable. The attribute is '
            . 'anchored on the closing `>` here on purpose: a `[^>]*` match would be satisfied by '
            . 'the attribute landing on some other element.');

        /* And no bare `.modal-content` is left behind in this file. */
        $this->assertSame(0, substr_count($source, '<div class="modal-content">'),
            'a finance modal still has an unnamed .modal-content surface');
    }

    /**
     * AND THE REASON FOR STEP 2, MEASURED. Without this the attribute above is a decoration
     * nobody can justify; with it, a reviewer who deletes it has a number to argue against.
     */
    public function test_the_dark_danger_token_is_below_aa_on_the_white_modal_and_the_light_one_is_not(): void
    {
        $ds = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        $this->assertStringContainsString('--ss-danger:     #f3a9a4', $ds,
            'the dark --ss-danger is no longer #f3a9a4, so the ratio below describes a token that '
            . 'has moved');
        $this->assertStringContainsString('--ss-red-700:   #b3261e', $ds,
            'the light --ss-red-700 is no longer #b3261e');
        $this->assertStringContainsString('.invalid-feedback,', $ds,
            'the design system no longer restyles .invalid-feedback, so the dark-mode hazard this '
            . 'attribute guards against may no longer exist — re-measure before trusting it');

        /* The host modal really is white in dark mode. */
        foreach (['public/assets/css/style-dark.css', 'public/assets/css/rtl-style-dark.css'] as $sheet) {
            $this->assertMatchesRegularExpression('/--bs-modal-bg:\s*#ffffff/i',
                (string) file_get_contents(base_path($sheet)),
                "{$sheet} no longer keeps the modal surface white in dark mode");
        }

        $white = [255, 255, 255];

        /* Measured here: 1.91:1. */
        $darkDanger = $this->contrast([243, 169, 164], $white);
        $this->assertLessThan(4.5, $darkDanger,
            'the dark --ss-danger now passes AA on the white modal, so data-ss-theme="light" is '
            . 'no longer needed on these three modals (measured: ' . round($darkDanger, 2) . ':1)');

        /* Measured here: 6.54:1 — what the attribute restores. */
        $lightDanger = $this->contrast([179, 38, 30], $white);
        $this->assertGreaterThanOrEqual(4.5, $lightDanger,
            'the light --ss-red-700 does not pass AA on the white modal, so the guard does not '
            . 'fix anything (measured: ' . round($lightDanger, 2) . ':1)');

        /* And the focus ring, which the deferred slice will move into these modals.
           Measured: dark --ss-cyan-400 1.87:1, below SC 1.4.11's 3:1; light --ss-blue-500 4.44:1. */
        $this->assertLessThan(3.0, $this->contrast([104, 203, 225], $white),
            'the dark focus ring now clears 3:1 on white');
        $this->assertGreaterThanOrEqual(3.0, $this->contrast([79, 119, 192], $white),
            'the light focus ring no longer clears 3:1 on white');
    }

    /**
     * STEP 3(b) — THE SILENCE, PINNED. This is the test the deferred bag slice must turn green.
     *
     * It drives the one trigger that is reachable without a hand-crafted POST and asserts what the
     * merchant is shown today: nothing. Written as an assertion rather than a comment so the slice
     * cannot inherit a passing test, and so nobody can claim the defect was fixed while /finance
     * still renders no summary and no mark.
     */
    public function test_a_refused_finance_description_is_still_answered_with_silence(): void
    {
        $merchant = $this->financeMerchant();

        /* THE PREMISE. A 403 or a 302 to login satisfies "no balance was added" for free. */
        $form = $this->actingAs($merchant)->get(route('finance.index'));
        $this->assertSame(200, $form->getStatusCode(),
            'the finance dashboard did not render for this fixture: '
            . substr((string) $form->getContent(), 0, 300));

        $refused = $this->actingAs($merchant)
            ->from(route('finance.index'))
            ->post(route('finance.add.balance'), [
                'amount' => '10.00',
                'description' => str_repeat('a', 300),   // server rule is max:255
            ]);

        $refused->assertSessionHasErrors('description');

        $body = (string) $this->actingAs($merchant)->get(route('finance.index'))->getContent();

        $this->assertStringNotContainsString('ss-errorsummary', $body,
            'POSITIVE NEWS, AND THIS ASSERTION MUST THEN BE INVERTED: /finance now renders an '
            . 'error summary, so the deferred bag slice has landed. Rewrite this case to assert '
            . 'the summary is scoped to the addBalance bag and that the DEDUCT modal carries '
            . 'neither a summary nor an is-invalid mark nor a prefilled amount.');
        $this->assertStringNotContainsString('is-invalid', $body,
            'POSITIVE NEWS, AND THIS ASSERTION MUST THEN BE INVERTED: a finance control now '
            . 'carries is-invalid. See the message above.');

        /* AND THE MAXLENGTH IS WHAT MAKES THAT SILENCE UNREACHABLE FROM THE UI. */
        $this->assertStringContainsString('maxlength="255"', $body,
            'the rendered Add Balance modal does not cap the description, so a merchant can still '
            . 'reach the silent redirect this case pins by pasting 300 characters');
    }

    /**
     * NEGATIVE CONTROL — THE GATES AND THE API ARE UNTOUCHED.
     *
     * A merchant lacking a money permission must receive zero markup for that modal, including
     * zero `data-ss-theme` attribute and zero maxlength; and the two Flutter wallet endpoints keep
     * their OWN plain `->validate()` calls and the ApiResponser shape (the shipped app force-logs
     * out on any 401, so a status change there is a user-visible regression).
     */
    public function test_the_permission_gates_and_the_flutter_wallet_validators_are_untouched(): void
    {
        [$merchant] = $this->makeTenant();
        \App\Models\Permission::firstOrCreate(
            ['name' => 'View Finance Dashboard'], ['display_name' => 'View Finance Dashboard']
        );
        $this->grantPermission(User::find($merchant->id), 'View Finance Dashboard');
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $body = (string) $this->actingAs(User::find($merchant->id))
            ->get(route('finance.index'))->getContent();

        $this->assertStringNotContainsString('data-ss-theme="light"', $body,
            'a merchant holding none of the money permissions received a finance modal surface');
        $this->assertStringNotContainsString('maxlength="2000"', $body,
            'a merchant holding none of the money permissions received the withdrawal modal');

        $controller = (string) file_get_contents(base_path('app/Http/Controllers/StoreFinanceController.php'));

        $this->assertSame(0, substr_count($controller, 'validateWithBag'),
            'validateWithBag has landed in StoreFinanceController, which is the DEFERRED slice. '
            . 'When it does, it must leave addBalanceApi and deductBalanceApi on plain '
            . '->validate() and on the ApiResponser 200-with-status-0 shape, and it must pass '
            . 'fieldIds for EVERY key in ALL THREE bags — the withdrawal bag has four.');

        /* The orphaned partial must stay orphaned: it interpolates messages unescaped into a
           single-quoted JS literal (P1-SEC-22(b)). */
        $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));
        $this->assertStringNotContainsString('includes.alerts', $layout,
            'resources/views/layouts/includes/alerts.blade.php has been wired into the layout. It '
            . 'double-reports on the four views that already render x-ss.error-summary AND it is '
            . 'the unescaped-single-quote injection sink app.blade.php:762-767 records as fixed.');
    }
}
