<?php

namespace Tests\Feature\Saas;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-231 — `Total Plan Orders` KEEPS ITS FIGURE AND GAINS THE SENTENCE IT WAS MISSING.
 * =================================================================================================
 *
 * THE THIRD CARD GAP-231 NAMES WAS NOT FIXED THE WAY THE OTHER TWO WERE, and the asymmetry is the
 * point. `Popular Plan` and `Popular Coupon` could be led by rows representing NO MONEY — a plan
 * whose every purchase failed could win — so they were given a settled predicate, because a
 * ranking that can be led by failures is a defect and not a definition.
 *
 * `Total Plan Orders` is `PlanOrder::count()`, every attempt included, and that is a DEFENSIBLE
 * operational number: "how many purchase attempts exist" is a real question an operator asks, and
 * it is the only card that can answer it. Adding a predicate would have silently redefined a
 * figure the owner reads, to answer a question nobody asked. What was actually wrong was that the
 * card said nothing about which it meant.
 *
 * This is this view's own precedent, applied in the mirror. `Total Orders` was RELABELLED to
 * `Total Plan Orders` rather than having its figure changed, because the figure was right and the
 * word was wrong. Here the figure is right and the SILENCE was wrong.
 *
 * NOT A `:hint` ON A CARD, and the reason is mechanical: `<x-dashboard-card>` has no hint slot, and
 * `DashboardCardIconLinkHasANameTest` asserts this dashboard renders EXACTLY SIX of them, so a
 * disclosure can be neither a prop nor a seventh card. A standalone `ss-stat__hint` beside the row
 * is the shape the analytics screens already use for a disclosure belonging to a group rather than
 * to one tile — and it is invisible to `StatHintIsVisibleTest` by construction, which counts
 * `:hint=` on `x-ss.stat`, so it moves no ratchet.
 */
final class OperatorDashboardAttemptCountDisclosureTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The three claims the sentence has to make, as words that can only come from it. */
    private const CLAIMS = [
        'every purchase attempt',          // the count includes unpaid attempts
        'actually paid for',               // the rankings do not
        'whose order can be found',        // and the coupon ranking needs a matchable order
    ];

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->operator = $this->makePlatformAdmin();
        $this->makeStore($this->operator, ['default_language' => 'en']);
        (new User)->flushCache();
        Cache::forget('admin_details');
    }

    private function dashboard(?string $language = null): string
    {
        if ($language !== null) {
            $this->operator->language = $language;
            $this->operator->save();
            (new User)->flushCache();
        }

        $response = $this->actingAs(User::find($this->operator->id))->get('/dashboard');

        $response->assertOk();

        return (string) $response->getContent();
    }

    #[Test]
    public function test_the_disclosure_states_all_three_of_its_claims_in_english(): void
    {
        $body = $this->dashboard('en');

        foreach (self::CLAIMS as $claim) {
            $this->assertStringContainsString($claim, $body,
                'the operator dashboard no longer discloses "' . $claim . '", so a reader cannot '
                . 'tell which of the three cards counts attempts and which count payments.');
        }
    }

    /**
     * AND IN ARABIC, because `__()` returns its key: an undeclared sentence renders correctly in
     * English by accident and IN ENGLISH on the Arabic page, and no "is it non-empty" check can
     * tell those apart.
     */
    #[Test]
    public function test_the_disclosure_renders_in_arabic(): void
    {
        $body = $this->dashboard('ar');

        $this->assertStringContainsString('كل محاولة شراء', $body,
            'the disclosure rendered in English on the Arabic dashboard, so its key is undeclared '
            . 'in ar.json.');

        $this->assertStringNotContainsString('every purchase attempt', $body);
    }

    /** It is a visible hint, not a `title` attribute or a screen-reader-only aside. */
    #[Test]
    public function test_the_disclosure_is_a_visible_design_system_hint(): void
    {
        $body = $this->dashboard('en');

        $this->assertMatchesRegularExpression(
            '/<p[^>]*class="[^"]*\bss-stat__hint\b[^"]*"[^>]*>\s*[^<]*every purchase attempt/s',
            $body,
            'the disclosure is not rendered as a visible ss-stat__hint paragraph, so it is either '
            . 'invisible or it is not carrying the design system\'s hint styling.'
        );

        /* AND NOT SCREEN-READER-ONLY. `ss-visually-hidden` on this paragraph would satisfy the
           regex above while hiding the sentence from the operator reading the cards. */
        $this->assertDoesNotMatchRegularExpression(
            '/<p[^>]*class="[^"]*\bss-visually-hidden\b[^"]*"[^>]*>\s*[^<]*every purchase attempt/s',
            $body,
            'the disclosure is visually hidden, so the reader who needs it cannot see it.'
        );
    }

    /**
     * THE FIGURE ITSELF IS UNCHANGED — the half of this fix that is a decision not to act.
     * Every plan order counts, settled or not, so the disclosure describes the card rather than
     * excusing it.
     */
    #[Test]
    public function test_the_count_still_includes_unpaid_attempts(): void
    {
        $plan = \App\Models\Plan::create([
            'name' => 'P-' . $this->uniqueFixtureRef(),
            'price' => 49, 'duration' => 'Month', 'themes' => '[]', 'storage_limit' => '100',
        ]);

        foreach (['succeeded', 'pending', 'failed'] as $status) {
            \App\Models\PlanOrder::create([
                'order_id' => 'PO-' . $this->uniqueFixtureRef(),
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'price' => 49,
                'price_currency' => 'USD',
                'txn_id' => 'TX-' . $this->uniqueFixtureRef(),
                'user_id' => $this->operator->id,
                'store_id' => $this->operator->current_store,
                'payment_status' => $status,
            ]);
        }

        $this->assertSame(3, \App\Models\PlanOrder::total_orders(),
            'Total Plan Orders was given a settled predicate. That was deliberately NOT done: the '
            . 'attempt count is the only card that can answer "how many attempts exist", and '
            . 'changing it silently redefines a figure the owner reads. If it should be filtered, '
            . 'that is an owner decision, not a defect fix.');
    }
}
