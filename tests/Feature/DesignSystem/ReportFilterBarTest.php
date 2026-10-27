<?php

namespace Tests\Feature\DesignSystem;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P2-05-6 — the period bar four report screens each had a broken copy of.
 * =============================================================================================
 *
 * Six screens — `reports/index`, the three `sale_by_*`, `order_report` and
 * `bar_chart_order_report` — each carried the same hand-written filter bar, and each carried
 * the same four defects:
 *
 *   - `<ul role="tablist">` whose buttons had NO `role="tab"`, no `aria-selected` and no
 *     `aria-controls`. The selection lived in an `active` CSS class and nowhere else, so a
 *     screen-reader user could not tell which of four periods they were looking at, while
 *     `role="tablist"` promised tab behaviour that was not there.
 *   - Two `<div>`s as DIRECT CHILDREN of that `<ul>`. Only `<li>` may be a child of a list.
 *   - No label on the date input.
 *   - The duplication: fixing one screen fixed one screen. Two of the six also carried an
 *     empty `#deals-staff-report` div inside the `<ul>`, with `height` and `width` ATTRIBUTES on
 *     a `<div>` (which do nothing) and no code that ever drew into it.
 *
 * THE DUPLICATION IS WHY THIS FILE EXISTS AS WELL AS THE PER-SCREEN TESTS. A component test
 * proves the markup is right once; the per-screen cases below prove each screen actually USES
 * it, which is the half that rots — a screen can quietly grow its own bar back.
 */
class ReportFilterBarTest extends TestCase
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
            'name' => 'Filter Store', 'email' => 'filter@example.test',
            'slug' => 'filter-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();
    }

    /** The component rendered through a route, because the layout needs a request. */
    private function bar(array $props = []): string
    {
        Route::get('/__filter_bar', fn () => view('components.ss.report-filter', $props + [
            'periods' => null, 'selected' => 'year', 'date' => '2026-03-12',
            'id' => 'report', 'label' => null,
            'generateValue' => '', 'generateDisabled' => false,
            'attributes' => new \Illuminate\View\ComponentAttributeBag([]),
            'slot' => new \Illuminate\Support\HtmlString(''),
        ]))->middleware(['web', 'auth']);

        return $this->actingAs($this->merchant)->get('/__filter_bar')->assertOk()->getContent();
    }

    public function test_exactly_one_period_is_pressed_and_the_rest_say_they_are_not(): void
    {
        $html = $this->bar();

        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
        $this->assertSame(3, substr_count($html, 'aria-pressed="false"'),
            'an unselected period must SAY so; absence of a class is not a state');
    }

    public function test_the_pressed_one_is_the_one_asked_for(): void
    {
        $html = $this->bar(['selected' => 'seven-day']);

        $this->assertMatchesRegularExpression(
            '/value="seven-day"\s+aria-pressed="true"/',
            preg_replace('/\s+/', ' ', $html),
            'the pressed button is not the selected period'
        );
    }

    public function test_it_is_not_a_tablist(): void
    {
        $html = $this->bar();

        $this->assertStringNotContainsString('role="tablist"', $html,
            'these refetch rather than reveal a panel already in the page, so they are not tabs');
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-label="Report period"', $html);
    }

    public function test_the_date_field_is_labelled_and_its_id_follows_the_prefix(): void
    {
        $html = $this->bar(['id' => 'brand']);

        $this->assertStringContainsString('<label class="ss-label" for="brand-date">', $html);
        $this->assertStringContainsString('id="brand-date"', $html);
        $this->assertStringContainsString('value="2026-03-12"', $html);
    }

    /**
     * TWO BARS ON ONE PAGE MUST NOT COLLIDE. `id` must be unique and matching is CASE-SENSITIVE,
     * so a shared component with a fixed id is a dangling `for` waiting to happen.
     */
    public function test_two_bars_do_not_share_an_id(): void
    {
        $this->assertStringContainsString('id="report-date"', $this->bar(['id' => 'report']));
        $this->assertStringContainsString('id="category-date"', $this->bar(['id' => 'category']));
    }

    public function test_generate_can_be_given_a_value_and_can_start_disabled(): void
    {
        $plain = $this->bar();
        $this->assertStringContainsString('class="ss-btn ss-btn--primary ss-btn--sm generate_button"', $plain);
        $this->assertStringNotContainsString('disabled', $plain);

        $gated = $this->bar(['generateValue' => 'date-wise', 'generateDisabled' => true]);
        $this->assertStringContainsString('value="date-wise"', $gated);
        $this->assertStringContainsString('disabled', $gated,
            'a Generate that needs a date first must be disabled, not merely styled as such — '
            . 'a keyboard user has to be told');
    }

    /**
     * EACH SCREEN ACTUALLY USES IT. This is the half that rots: the component can be perfect
     * while a screen quietly grows its own bar back.
     *
     * @dataProvider screens
     */
    public function test_each_report_screen_uses_the_shared_bar(string $view, string $idPrefix): void
    {
        /* BLADE COMMENTS ARE STRIPPED FIRST, and that is not a nicety.
           These screens now carry a comment EXPLAINING the `role="tablist"` defect they used to
           have, so a raw source scan matches its own documentation and reports the defect as
           present. The hub passed only because its wording happened to differ. This is the third
           time in this phase that a source-text check has matched something other than code —
           after the `@php` inside a Blade comment and the `<x-ss.bar-chart>` inside a JavaScript
           comment. What the check actually asks is "does this screen RENDER a tablist", so the
           prose has to go before the scan. */
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path($view)));

        $this->assertStringContainsString('<x-ss.report-filter', $source,
            $view . ' no longer uses the shared period bar');
        $this->assertStringContainsString('id="' . $idPrefix . '"', $source,
            $view . ' must give its bar a distinct id prefix, or its date field collides');

        $this->assertStringNotContainsString('role="tablist"', $source,
            $view . ' has grown a tablist back');
        $this->assertStringNotContainsString('nav nav-pills', $source,
            $view . ' has grown its own pill bar back');
        $this->assertStringNotContainsString('addClass("active")', $source,
            $view . ' is tracking the selection in a class again, where assistive technology '
            . 'cannot see it');
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function screens(): array
    {
        return [
            'reports hub' => ['resources/views/reports/index.blade.php', 'report'],
            'sale by product' => ['resources/views/reports/sale_by_product.blade.php', 'product'],
            'sale by category' => ['resources/views/reports/sale_by_category.blade.php', 'category'],
            'sale by brand' => ['resources/views/reports/sale_by_brand.blade.php', 'brand'],
            'order report' => ['resources/views/reports/order_report.blade.php', 'order'],
            'order bar chart' => ['resources/views/reports/bar_chart_order_report.blade.php', 'orderbar'],
        ];
    }
}
