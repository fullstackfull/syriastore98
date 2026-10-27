<?php

namespace Tests\Feature\DesignSystem;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-05-6 — all three stock tabs claimed to be the selected one.
 * =============================================================================================
 *
 * Unlike the period bars on the other report screens, this IS a real tablist: three panes are
 * already in the page and the rail switches between them. What it got wrong is the other half —
 * every one of the three `<a role="tab">` carried a hardcoded `aria-selected="true"`.
 *
 * A tablist has exactly one selected tab. Three saying so means a screen reader announces all
 * three as current, and the one that is actually showing is distinguishable only by the `active`
 * CSS class — the same visible-but-not-announced split the period bars had, arrived at from the
 * opposite direction: there the state was missing, here it was asserted three times.
 *
 * The rail is a loop now, so `$ssActive` drives BOTH the class and the attribute and they cannot
 * be edited apart again. These cases assert the pairing, not just the count: "exactly one true"
 * would still pass if the true one were the wrong tab.
 */
class StockReportTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'name' => 'Stock Store', 'email' => 'stock@example.test',
            'slug' => 'stock-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();
    }

    private function page(?string $activeTab = null): string
    {
        if ($activeTab !== null) {
            session(['stock_active_tab' => $activeTab]);
        }

        return $this->actingAs($this->merchant)->get('/stock-reports')->assertOk()->getContent();
    }

    /** The rail's tabs as id => aria-selected. */
    private function tabs(string $html): array
    {
        preg_match_all(
            '/id="(pills-[a-z-]+-tab)"[^>]*?aria-selected="(true|false)"/s',
            preg_replace('/\s+/', ' ', $html), $m, PREG_SET_ORDER
        );

        $out = [];
        foreach ($m as $t) {
            $out[$t[1]] = $t[2];
        }

        return $out;
    }

    public function test_exactly_one_tab_is_selected(): void
    {
        $tabs = $this->tabs($this->page());

        $this->assertCount(3, $tabs, 'Premise: the three stock tabs did not render.');
        $this->assertSame(1, count(array_filter($tabs, fn ($v) => $v === 'true')),
            'a tablist has exactly one selected tab; this says ' . json_encode($tabs));
    }

    /**
     * AND IT IS THE RIGHT ONE. "Exactly one true" would still pass if the selected tab were not
     * the tab whose pane is showing.
     */
    public function test_the_selected_tab_is_the_one_whose_pane_is_showing(): void
    {
        foreach ([
            'pills-low-stock-tab' => 'pills-low-stock',
            'pills-out-of-stock-tab' => 'pills-out-of-stock',
            'pills-most-stocked-tab' => 'pills-most-stocked',
        ] as $tabId => $pane) {
            $html = $this->page($tabId);
            $tabs = $this->tabs($html);

            $this->assertSame('true', $tabs[$tabId] ?? null,
                "{$tabId} is showing but does not say it is selected: " . json_encode($tabs));
            $this->assertSame(1, count(array_filter($tabs, fn ($v) => $v === 'true')),
                'more than one tab claims to be selected: ' . json_encode($tabs));

            /* The pane really is the one showing, so the tab and the content agree. */
            $flat = preg_replace('/\s+/', ' ', $html);
            $this->assertMatchesRegularExpression(
                '/class="tab-pane fade show active\s*" id="' . preg_quote($pane, '/') . '"/',
                $flat,
                "{$pane} is not the pane that is showing"
            );
        }
    }

    /** With nothing in the session the handler defaults to the first tab, and so must the rail. */
    public function test_the_default_tab_is_selected_when_the_session_is_empty(): void
    {
        $tabs = $this->tabs($this->page());

        $this->assertSame('true', $tabs['pills-low-stock-tab'] ?? null);
        $this->assertSame('false', $tabs['pills-out-of-stock-tab'] ?? null);
        $this->assertSame('false', $tabs['pills-most-stocked-tab'] ?? null);
    }

    /** Each tab points at a pane that exists — a dangling `aria-controls` names nothing. */
    public function test_every_tab_controls_a_pane_that_exists(): void
    {
        $html = preg_replace('/\s+/', ' ', $this->page());

        preg_match_all('/aria-controls="([^"]+)"/', $html, $m);
        $this->assertNotSame([], $m[1] ?? [], 'Premise: no aria-controls at all.');

        foreach (array_unique($m[1]) as $pane) {
            $this->assertStringContainsString('id="' . $pane . '"', $html,
                "a tab controls `{$pane}`, which is not in the page");
        }
    }
}
