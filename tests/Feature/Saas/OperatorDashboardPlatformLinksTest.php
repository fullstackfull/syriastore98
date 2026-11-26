<?php

namespace Tests\Feature\Saas;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE THREE PLATFORM SCREENS ARE REACHABLE FROM THE OPERATOR'S DASHBOARD.
 * =================================================================================================
 *
 * A BUILT SCREEN NOBODY CAN FIND IS NOT DELIVERED, and all three of these were in that state:
 * `grep -rn 'platform.analytics' resources/views/` found exactly ONE hit before this change — the
 * export button inside the analytics screen itself — so the cross-tenant analytics screen (P5-7)
 * has been reachable only by typing its URL since it shipped, and the two screens this workstream
 * added for GAP-195 and GAP-192 would have shipped the same way.
 *
 * WHY THE DASHBOARD AND NOT THE SIDEBAR. The operator's navigation is built by
 * `app/Listeners/SuperAdminMenuListener.php` and `app/Services/MerchantPageDirectory.php`, neither
 * of which belongs to this workstream in this phase. The operator dashboard view does, so the
 * links go where they can be put correctly today; a sidebar entry is recorded as a cross-agent
 * dependency rather than smuggled into somebody else's file.
 *
 * WHY NOT THREE MORE `<x-dashboard-card>`s, which is what the screen above them uses:
 *
 *   1. A dashboard card's shape is `label` + `count`, and these are not figures. Passing a
 *      borrowed number — or a zero — would state something false in the loudest typography on the
 *      page.
 *   2. `tests/Unit/Ui/DashboardCardIconLinkHasANameTest` asserts the operator dashboard renders
 *      EXACTLY SIX `x-dashboard-card`s, by count, as the premise for its accessible-name
 *      assertions. A seventh would redden that test whether or not it carried a name — so using
 *      the component here would have meant re-pointing another workstream's ratchet to add a
 *      navigation link, which is the wrong trade.
 *
 * Each link carries a sentence saying what the screen measures, because "Platform revenue" and
 * "Platform analytics" are one word apart and measure different money from different payers —
 * which is the whole of SAAS-007.
 */
final class OperatorDashboardPlatformLinksTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** route name => a word that must appear in its description, so the links cannot be swapped. */
    private const LINKS = [
        'platform.revenue' => 'merchants pay',
        'platform.analytics' => 'shoppers',
        'platform.resources' => 'machine',
        /*
         * OPS-001. "Platform resources" and "Platform operations" are one word apart and measure
         * different things — one host's load and disk against the shared database, the shared
         * queue and the integrations — so the word that has to be in the sentence is the one that
         * tells them apart, exactly as for the two money screens above.
         */
        'platform.operations' => 'queues',
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

    private function body(?string $language = null): string
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

    /**
     * The panel this slice adds, so no assertion can be satisfied by the rest of the page.
     *
     * Bounded by two markers the view emits — the wrapper's class and an HTML comment after its
     * closing tag — rather than by counting `</div>`s: the dashboard is 269 lines of nested
     * Bootstrap columns and a brace-counting slice would be its own source of false readings. An
     * HTML comment and not a Blade one, because a Blade comment never reaches the response.
     */
    private function panel(?string $body = null): string
    {
        $body = $body ?? $this->body();

        $from = strpos($body, 'ss-platform-links');
        $to = $from === false ? false : strpos($body, 'ss-platform-links-end', $from);

        if ($from === false || $to === false) {
            return '';
        }

        return substr($body, $from, $to - $from);
    }

    public function test_the_operator_dashboard_links_to_all_three_platform_screens(): void
    {
        $panel = $this->panel();

        $this->assertNotSame('', $panel,
            'the platform-links panel is not on the operator dashboard, so the three screens are '
            . 'reachable only by typing their URLs');

        foreach (array_keys(self::LINKS) as $name) {
            $this->assertStringContainsString('href="' . route($name) . '"', $panel,
                "the operator dashboard does not link to {$name}");
        }
    }

    /**
     * EACH LINK SAYS WHAT ITS SCREEN MEASURES, and the words are the ones that tell the two money
     * screens apart. "Platform revenue" and "Platform analytics" differ by one word in the label
     * and by payer, currency and table in the figures.
     */
    public function test_each_link_says_what_its_screen_measures(): void
    {
        $panel = $this->panel();

        foreach (self::LINKS as $name => $word) {
            $this->assertStringContainsString($word, $panel,
                "the link to {$name} carries no description containing '{$word}', so an operator "
                . 'cannot tell the platform-revenue screen from the merchant-sales one');
        }
    }

    /** The links are real anchors with the design system's own link class, not bare text. */
    public function test_the_links_are_anchors_on_the_design_systems_link_class(): void
    {
        $panel = $this->panel();

        foreach (array_keys(self::LINKS) as $name) {
            $this->assertMatchesRegularExpression(
                '/<a[^>]*class="[^"]*\bss-link\b[^"]*"[^>]*href="' . preg_quote(route($name), '/') . '"'
                . '|<a[^>]*href="' . preg_quote(route($name), '/') . '"[^>]*class="[^"]*\bss-link\b[^"]*"/',
                $panel,
                "the {$name} link does not carry the design system's `ss-link` class, so it is "
                . 'styled by whatever the host theme does to a bare anchor'
            );
        }
    }

    /**
     * A MERCHANT NEVER SEES THIS PANEL, and the reason it needs asserting is that the panel lives
     * in a view, not behind a gate: `HomeController::index()` dispatches a `super admin` to
     * `superadmin.dashboard` and everyone else to the merchant dashboard, so the separation is the
     * dispatch. If that dispatch ever widened, a merchant would be shown links to three screens
     * that would then refuse them with a 403 — a dead end presented as a feature.
     */
    public function test_a_merchant_dashboard_does_not_show_the_platform_links(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant->fresh())->get('/dashboard');

        $this->assertNotSame(500, $response->status(), 'the merchant dashboard did not render');

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('ss-platform-links', $body,
            'a merchant was shown the operator\'s platform links');
        $this->assertStringNotContainsString(route('platform.revenue'), $body,
            'a merchant was shown a link to the platform revenue screen, which refuses them 403');
    }

    public function test_the_panel_renders_in_arabic(): void
    {
        $panel = $this->panel($this->body('ar'));

        $this->assertNotSame('', $panel, 'the panel is absent on the Arabic dashboard');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $panel,
            'the platform-links panel rendered with no Arabic in it for an Arabic operator');
        $this->assertStringContainsString('إيرادات المنصة', $panel,
            'the revenue link rendered in English for an Arabic operator');
        $this->assertStringContainsString('موارد المنصة', $panel,
            'the resources link rendered in English for an Arabic operator');
    }

    public function test_the_panel_renders_in_english(): void
    {
        $panel = $this->panel($this->body('en'));

        $this->assertStringContainsString('Platform revenue', $panel);
        $this->assertStringContainsString('Platform resources', $panel);
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $panel,
            'the panel rendered Arabic for an English operator');
    }
}
