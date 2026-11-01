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
}
