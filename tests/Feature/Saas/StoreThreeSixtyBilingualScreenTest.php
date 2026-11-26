<?php

namespace Tests\Feature\Saas;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * ADM-002 / GAP-232 — STORE 360° RENDERS, IN BOTH LANGUAGES, AND PINS NO DIRECTION.
 * =================================================================================================
 *
 * A SCREEN THAT ANSWERS 200 IS NOT A SCREEN THAT RENDERED. CLAUDE.md records the exact way this
 * codebase lies about that: `layouts/app.blade.php` contains ZERO `$slot` references, so a view in
 * the `<x-*-layout>` component shape renders perfect chrome, HTTP 200 and an EMPTY content area —
 * measured, in all four language/theme combinations. `SlotLayoutViewsRenderTest` forbids the shape
 * repo-wide; these cases assert the positive for these two screens, by looking for content that
 * can only come from the view's own body.
 *
 * BOTH LANGUAGES, BECAUSE `__()` RETURNS ITS KEY. An undeclared sentence renders correctly in
 * English by accident and in English on the Arabic page.
 * `StoreThreeSixtyMessagesAreDeclaredTest` is the static half — every key declared in both files.
 * This is the dynamic half: the rendered page really carries Arabic, and really carries no Arabic
 * when the operator's language is English.
 *
 * `APP_DEBUG` IS OFF. CLAUDE.md: with it on, the debug bar injects its own badges and a contrast
 * harness measures `span.phpdebugbar-badge`, reporting findings that are not in the product — and
 * the COUNT of those badges is however many the bar happens to show, so the control is the
 * setting and never a number.
 */
final class StoreThreeSixtyBilingualScreenTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const VIEWS = [
        'resources/views/superadmin/store_360.blade.php',
        'resources/views/superadmin/store_360_index.blade.php',
    ];

    private User $operator;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->operator = $this->makePlatformAdmin();
        $this->makeStore($this->operator, ['default_language' => 'en']);

        [, $this->store] = $this->makeTenant();
        $this->makeOrder($this->store, ['final_price' => 25, 'currency_code' => 'USD']);

        (new User)->flushCache();
        Cache::forget('admin_details');
    }

    private function body(string $uri, string $language): string
    {
        $this->operator->language = $language;
        $this->operator->save();
        (new User)->flushCache();

        $response = $this->actingAs(User::find($this->operator->id))->get($uri);

        $response->assertOk();

        return (string) $response->getContent();
    }

    #[Test]
    public function test_the_roster_renders_its_own_body_in_english(): void
    {
        $body = $this->body('/platform/stores', 'en');

        /* Sentences that exist ONLY in this view, so the chrome cannot satisfy them. */
        $this->assertStringContainsString('This is a list of stores, not of merchants', $body);
        $this->assertStringContainsString($this->store->slug, $body,
            'the roster rendered without the store it is supposed to list.');
        $this->assertStringContainsString('href="' . route('platform.stores.show', $this->store->id) . '"', $body,
            'the roster does not link to the 360 screen, so the list is a dead end.');
    }

    #[Test]
    public function test_the_roster_renders_its_own_body_in_arabic(): void
    {
        $body = $this->body('/platform/stores', 'ar');

        $this->assertStringContainsString('هذه قائمة متاجر لا قائمة تجار', $body,
            'the roster sentence rendered in English on the Arabic page, so its key is not declared.');
        $this->assertStringNotContainsString('This is a list of stores, not of merchants', $body);
    }

    #[Test]
    public function test_the_360_screen_renders_every_block_in_english(): void
    {
        $body = $this->body('/platform/stores/' . $this->store->id, 'en');

        foreach ([
            'This store',
            'Subscription',
            'Order value',
            'Catalogue and shoppers',
            'Manage this store from its own systems',
        ] as $heading) {
            $this->assertStringContainsString($heading, $body,
                'the 360 screen rendered without its "' . $heading . '" block.');
        }

        /* THE MONEY ROW IS REALLY THERE AND IS REALLY THIS STORE'S. */
        $this->assertStringContainsString('25.00', $body,
            "the store's own order value did not reach the screen.");
    }

    #[Test]
    public function test_the_360_screen_renders_in_arabic_including_the_clause_the_decision_turns_on(): void
    {
        $body = $this->body('/platform/stores/' . $this->store->id, 'ar');

        /* `من أنظمته الأصلية` IS THE RECOVERED DECISION'S OWN CLAUSE. Asserting it in Arabic is
           asserting that the requirement's load-bearing phrase reached the Arabic screen, not just
           that some Arabic did. */
        $this->assertStringContainsString('إدارة هذا المتجر من أنظمته الأصلية', $body,
            'the door list heading — the clause ADM-002 turns on — rendered in English on the '
            . 'Arabic page.');

        $this->assertStringContainsString('الاشتراك', $body);
        $this->assertStringNotContainsString('Manage this store from its own systems', $body);
    }

    /**
     * NEITHER VIEW PINS A SIDE. A hard-coded `ltr`/`rtl`, or a one-sided margin or padding utility,
     * is what makes a screen correct in one language and wrong in the other — and the layout
     * already mirrors, so a view that pins a side fights it.
     *
     * COMMENTS ARE STRIPPED FIRST, and here that is not precautionary: both views' headers use the
     * words `direction` and `dir` while explaining that they contain neither. CLAUDE.md records a
     * census broken by exactly that — a CSS comment recording that a draft had used
     * `position: relative`.
     */
    #[Test]
    public function test_neither_screen_hard_codes_a_direction_or_a_one_sided_spacing_utility(): void
    {
        $offenders = [];

        foreach (self::VIEWS as $relative) {
            $source = (string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                (string) file_get_contents(base_path($relative))
            );

            foreach ([
                'dir="ltr"',
                'dir="rtl"',
                'text-align: left',
                'text-align: right',
                'margin-left',
                'margin-right',
                'padding-left',
                'padding-right',
                'class="ml-',
                'class="mr-',
                'class="pl-',
                'class="pr-',
                ' ml-1',
                ' mr-1',
                ' text-left',
                ' text-right',
            ] as $pinned) {
                if (str_contains($source, $pinned)) {
                    $offenders[] = $relative . ': ' . $pinned;
                }
            }
        }

        $this->assertSame([], $offenders,
            "A Store 360 view pins a physical side, so it is correct in one language and wrong in "
            . "the other:\n  " . implode("\n  ", $offenders));
    }

    /**
     * PREMISE for the case above: the scan can actually see the files, and the strings it looks for
     * are the shape that would really appear. Without this it is green on an unreadable path.
     */
    #[Test]
    public function test_premise_the_direction_scan_reads_the_views(): void
    {
        foreach (self::VIEWS as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            $this->assertNotSame('', $source, $relative . ' is empty or unreadable');
            $this->assertStringContainsString('@extends(\'layouts.app\')', $source,
                $relative . ' is no longer an @extends view, so direction comes from somewhere '
                . 'this scan does not know about.');
        }
    }
}
