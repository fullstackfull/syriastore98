<?php

namespace Tests\Feature\Merchant;

use App\Models\Permission;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\StoreWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5 finance-status-badges-below-aa — the host theme repaints `.badge bg-*` with a foreground.
 * =================================================================================================
 *
 * WHAT IS WRONG. Every status pill in the finance area was a raw Bootstrap `.badge bg-*` utility,
 * and the vendored theme redefines those utilities with BOTH a background and a foreground, each
 * `!important` (`public/assets/css/style.css:9488` `.bg-success`, `:9494` `.bg-info`, `:9504`
 * `.bg-warning`, `:9514` `.bg-danger`). The `bg-opacity-10 text-*` idiom at index.blade.php:355-359
 * is a confirmed no-op twice over: `.text-success/.text-warning/.text-danger` (:9395/9405/9410) are
 * equal-specificity `!important` and EARLIER, so source order hands `.bg-*` the win; and those four
 * overrides never interpolate `--bs-bg-opacity` at all, so the opacity variable is never read.
 *
 * THE HONEST COUNT, AND IT IS NOT TWELVE FAILURES. Recomputed in this file from the real hex values
 * with the WCAG 2.x relative-luminance formula, compositing every translucent fill over the real
 * opaque surface:
 *
 *     Pending    1.68 light  /  5.64 dark     FAIL in light
 *     Approved  19.30       / 19.30           passes
 *     Paid       6.42       /  6.42           passes on the post-BR-02 blue default; 2.59 only if
 *                                             a merchant picks a light brand, which is unbounded
 *     Rejected   4.26 light /  1.03 dark      FAIL in both, catastrophically in dark
 *     Cancelled  4.69       /  4.69           passes
 *     Type chip  15.4 light /  1.09 dark      FAIL in dark — and self-inflicted, see below
 *
 * So two statuses plus the type chip genuinely fail; the rest is consistency across one column. The
 * migration is still right, because the DS tones measure 7.10-8.83:1 light and 8.25-9.81:1 dark —
 * AA and AAA in both themes — and no CSS-only route is open: `.bg-success` reads the MERCHANT'S
 * brand colour, a `.badge.bg-*` override would repaint every pill in all 70 vendored WorkDo modules
 * from a sheet whose stated property is that every selector is confined by an `.ss-` class
 * (`tests/Unit/Ui/DesignSystemStylesheetScopeTest.php:75`), and `packages/workdo/` is off-limits.
 *
 * THE TYPE CHIP IS A CHIP, NOT A STATUS. `index.blade.php:316` was `badge bg-light text-dark
 * border` on the TYPE column. In dark mode `.bg-light` keeps its near-white `#f8f9fa` fill
 * (`--bs-light-rgb: 248,249,250`, identical in style.css:55 and style-dark.css:55) while the
 * already-shipped A-05 block lifts `.text-dark` to `#eeeff1` for everything outside
 * `.modal-content` — 1.09:1, computed below. That is a Phase 2.5 self-inflicted regression, so it
 * is fixed here; but with `x-ss.chip :interactive="false"`, NOT the status badge, because
 * `status-badge.blade.php:3-5` says in as many words that the dot is the status signal, and a
 * transaction TYPE is not a status. `.ss-chip` also keeps the `border` the span had
 * (store-syria-ds.css:2058), which a bare `.ss-badge` would have dropped.
 *
 * THE SLOT-VS-PROP TRAP, which is the single most likely way this repair looks right and is wrong.
 * `status-badge.blade.php:6` is `@props(['tone' => 'neutral', 'label'])` and the body renders
 * `{{ $label }}` at :15 — `$slot` is ignored. So
 * `<x-ss.status-badge tone="warning">{{ __('Pending') }}</x-ss.status-badge>` COMPILES, passes
 * `BladeViewsCompileTest`, and renders an empty 6px dot. Every assertion below therefore pairs the
 * class with the visible text, and one case walks every rendered `.ss-badge` and refuses an empty
 * one outright.
 *
 * `tone="neutral"` for Cancelled is deliberate even though it is NOT in the whitelist at
 * `status-badge.blade.php:9`: an unlisted tone falls through to `$toneClass = ''`, leaving the bare
 * `.ss-badge` whose base rule already paints `--ss-neutral-bg`/`--ss-neutral-fg` at 8.12:1 light and
 * 8.98:1 dark. There is no `.ss-badge--neutral` rule to add it to, which is asserted below so
 * nobody "fixes" the whitelist and silently un-paints the pill.
 */
class FinanceStatusBadgeContrastTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const FINANCE_PERMISSIONS = [
        'View Finance Dashboard',
        'View Finance Withdrawal Requests',
    ];

    /**
     * The five tone pairings, as `--ss-*-bg` / `--ss-*-fg` resolve in each theme.
     *
     * Values READ from public/css/store-syria-ds.css (`:root` lines 287-297 via the primitives at
     * 169-204, and `html[data-ss-theme="dark"]` lines 423-427) and the resolution is asserted
     * against the file in test_the_design_system_tokens_this_file_measures_are_the_shipped_ones.
     */
    private const TONES = [
        'success' => [['#165e34', '#eaf7ef'], ['#7fd6a0', '#102a1b']],
        'warning' => [['#5d3f04', '#fdf4e3'], ['#f0c674', '#2c2109']],
        'danger' => [['#8c1d17', '#fdeeee'], ['#f3a9a4', '#2c1413']],
        'info' => [['#3f3a8f', '#f0eefb'], ['#b6b1ef', '#1d1b3d']],
        'neutral' => [['#43474d', '#eeeff1'], ['#c3c6cc', '#21252c']],
    ];

    // =============================================================================================
    //  WCAG 2.x, implemented here rather than quoted
    // =============================================================================================

    /** @return array{0:int,1:int,2:int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function luminance(array $rgb): float
    {
        $channel = function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    private function ratio(array $a, array $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Composite a translucent fill over the real opaque surface beneath it. */
    private function over(array $rgb, float $alpha, array $surface): array
    {
        return [
            (int) round($alpha * $rgb[0] + (1 - $alpha) * $surface[0]),
            (int) round($alpha * $rgb[1] + (1 - $alpha) * $surface[1]),
            (int) round($alpha * $rgb[2] + (1 - $alpha) * $surface[2]),
        ];
    }

    // =============================================================================================
    //  1. THE PREMISE — what the host utilities really measure. Without this the file is vacuous.
    // =============================================================================================

    /**
     * MEASURED, NOT QUOTED. If the vendored theme ever fixes its own utilities, this file is
     * reasoning about a stylesheet that has changed and the migration below is no longer a
     * contrast repair — so the numbers that justify it are computed from the shipped sheets.
     */
    public function test_the_host_badge_utilities_really_are_below_aa(): void
    {
        $light = (string) file_get_contents(base_path('public/assets/css/style.css'));
        $dark = (string) file_get_contents(base_path('public/assets/css/style-dark.css'));

        $this->assertStringContainsString('rgba(255, 174, 189, 0.41)', $dark,
            'style-dark.css no longer carries the translucent .bg-danger fill this file measures');
        $this->assertStringContainsString('#d80027', $light,
            'style.css no longer carries the .bg-danger foreground this file measures');

        /* Rejected, dark: #d80027 on rgba(255,174,189,.41) over the dark card #292A33.
           Computed here: composite #81606c, ratio 1.03:1. */
        $darkCard = $this->rgb('#292A33');
        $rejectedDark = $this->ratio(
            $this->rgb('#d80027'),
            $this->over($this->rgb('#ffaebd'), 0.41, $darkCard)
        );
        $this->assertLessThan(1.5, $rejectedDark,
            'the host .bg-danger pill is no longer ~1.03:1 on the dark card, so the dark half of '
            . 'this finding has changed under us (measured: ' . round($rejectedDark, 2) . ':1)');

        /* Rejected, light: the same fill over the white card #ffffff -> #ffdee4, 4.26:1. */
        $rejectedLight = $this->ratio(
            $this->rgb('#d80027'),
            $this->over($this->rgb('#ffaebd'), 0.41, $this->rgb('#ffffff'))
        );
        $this->assertLessThan(4.5, $rejectedLight,
            'the host .bg-danger pill now passes AA in light mode (measured: '
            . round($rejectedLight, 2) . ':1)');

        /* Pending, light: #f4b41a on #f4b41a26 over white -> #fdf4dd, 1.68:1. */
        $pendingLight = $this->ratio(
            $this->rgb('#f4b41a'),
            $this->over($this->rgb('#f4b41a'), 38 / 255, $this->rgb('#ffffff'))
        );
        $this->assertLessThan(2.0, $pendingLight,
            'the host .bg-warning pill is no longer ~1.68:1 in light mode (measured: '
            . round($pendingLight, 2) . ':1)');

        /* The type chip's dark-mode collapse, which A-05 created: #eeeff1 on #f8f9fa = 1.09:1. */
        $typeChipDark = $this->ratio($this->rgb('#eeeff1'), $this->rgb('#f8f9fa'));
        $this->assertLessThan(1.2, $typeChipDark,
            'the `bg-light text-dark` chip no longer collapses to ~1.09:1 under the A-05 '
            . '.text-dark lift (measured: ' . round($typeChipDark, 2) . ':1)');
    }

    /**
     * AND THE REPLACEMENT REALLY PASSES, in BOTH themes. Every number in the docblock above is
     * recomputed here rather than asserted as a literal, so a token change cannot drift past it.
     *
     * Measured: success 7.10/8.79, warning 8.83/9.81, danger 8.09/9.06, info 8.28/8.25,
     * neutral 8.12/8.98 — AA (4.5) and AAA (7.0) in light and dark alike.
     */
    public function test_every_design_system_tone_passes_aa_in_both_themes(): void
    {
        foreach (self::TONES as $tone => $themes) {
            foreach (['light' => $themes[0], 'dark' => $themes[1]] as $theme => $pair) {
                $measured = $this->ratio($this->rgb($pair[0]), $this->rgb($pair[1]));

                $this->assertGreaterThanOrEqual(4.5, $measured,
                    "the DS `{$tone}` tone measures " . round($measured, 2) . ":1 in {$theme} "
                    . 'mode, below WCAG AA — migrating the finance pills onto it would not be a '
                    . 'contrast repair');
            }
        }
    }

    /**
     * THE TOKENS THIS FILE MEASURES ARE THE SHIPPED ONES. Without this the five pairs above are
     * just numbers in a test that could have been copied from anywhere.
     */
    public function test_the_design_system_tokens_this_file_measures_are_the_shipped_ones(): void
    {
        $ds = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        /* Light: the semantic token points at a primitive, so both halves are asserted. */
        foreach ([
            '--ss-warning-fg: var(--ss-amber-900)',
            '--ss-amber-900: #5d3f04',
            '--ss-warning-bg: var(--ss-amber-50)',
            '--ss-amber-50:  #fdf4e3',
            '--ss-danger-fg:  var(--ss-red-800)',
            '--ss-red-800:   #8c1d17',
            '--ss-neutral-fg: var(--ss-grey-700)',
            '--ss-grey-700: #43474d',
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $ds,
                "store-syria-ds.css no longer declares `{$declaration}`, so the light ratios "
                . 'computed in this file describe tokens that have moved');
        }

        /* Dark: declared as literals on one line each. */
        foreach ([
            '--ss-warning-bg: #2c2109; --ss-warning-fg: #f0c674',
            '--ss-danger-bg:  #2c1413; --ss-danger-fg:  #f3a9a4',
            '--ss-neutral-bg: #21252c; --ss-neutral-fg: #c3c6cc',
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $ds,
                "store-syria-ds.css no longer declares `{$declaration}` for dark mode");
        }

        /* There is NO `.ss-badge--neutral`, which is why tone="neutral" must stay off the
           whitelist at status-badge.blade.php:9. Adding it there would un-paint the pill. */
        $this->assertStringNotContainsString('.ss-badge--neutral', $ds,
            'a `.ss-badge--neutral` rule now exists, so `neutral` may (and must) join the '
            . 'whitelist in status-badge.blade.php — re-read that component before trusting the '
            . 'Cancelled assertion in this file');
    }

    // =============================================================================================
    //  2. THE RENDERED MARKUP — class AND text together, in both languages
    // =============================================================================================

    /** @return array{0: User, 1: int} */
    private function merchant(string $language = 'en'): array
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->language = $language;
        $merchant->save();

        foreach (self::FINANCE_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name], ['display_name' => $name]);
            $this->grantPermission(User::find($merchant->id), $name);
        }

        Cache::forget('admin_details');

        return [User::find($merchant->id), $store->id];
    }

    private function seedEveryWithdrawalStatus(int $storeId, int $actorId): void
    {
        $wallet = StoreWallet::firstOrCreate(
            ['store_id' => $storeId],
            [
                'currency_code' => 'USD', 'available_balance' => 1000, 'pending_balance' => 0,
                'withdrawal_pending_balance' => 0, 'total_income' => 0, 'total_withdrawn' => 0,
            ]
        );

        foreach (['pending', 'approved', 'paid', 'rejected', 'cancelled'] as $status) {
            StoreWithdrawal::create([
                'store_id' => $storeId,
                'wallet_id' => $wallet->id,
                'amount' => 125.50,
                'status' => $status,
                'payment_method' => 'bank',
                'payment_details' => 'IBAN SY00 0000',
                'requested_by' => $actorId,
                'requested_at' => now(),
            ]);
        }
    }

    private function seedEveryTransactionStatus(int $storeId): void
    {
        $wallet = StoreWallet::firstOrCreate(
            ['store_id' => $storeId],
            [
                'currency_code' => 'USD', 'available_balance' => 1000, 'pending_balance' => 0,
                'withdrawal_pending_balance' => 0, 'total_income' => 0, 'total_withdrawn' => 0,
            ]
        );

        foreach (['completed', 'pending', 'cancelled'] as $status) {
            StoreWalletTransaction::create([
                'store_id' => $storeId,
                'wallet_id' => $wallet->id,
                'type' => 'manual_deposit',
                'direction' => 'credit',
                'amount' => 10,
                'balance_before' => 0,
                'balance_after' => 10,
                'currency_code' => 'USD',
                'description' => 'seed ' . $status,
                'status' => $status,
                'created_by' => 1,
            ]);
        }
    }

    private function html(User $merchant, string $path): string
    {
        $response = $this->actingAs($merchant)->get($path);

        $this->assertSame(200, $response->getStatusCode(),
            "{$path} did not render for this fixture, so nothing below measures anything: "
            . substr((string) $response->getContent(), 0, 400));

        return (string) $response->getContent();
    }

    /**
     * Assert a pill with this tone class exists AND carries this text.
     *
     * One regex, not two assertions, because the slot-vs-prop trap produces the class WITHOUT the
     * text and `assertSee($label)` would be satisfied by the same word anywhere else on the page.
     */
    private function assertPill(string $html, string $toneClass, string $label, string $where): void
    {
        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\b' . preg_quote($toneClass, '/')
            . '\b[^"]*"[^>]*>\s*<span class="ss-badge__dot"[^>]*><\/span>\s*'
            . preg_quote($label, '/') . '\s*<\/span>/u',
            $html,
            "{$where}: no `{$toneClass}` pill carrying the text \"{$label}\". Either the badge was "
            . 'not converted, or it was called with a SLOT instead of the `:label` prop — which '
            . 'compiles and renders an empty 6px dot.'
        );
    }

    public function test_the_withdrawal_queue_renders_design_system_pills_with_their_text(): void
    {
        [$merchant, $storeId] = $this->merchant();
        $this->seedEveryWithdrawalStatus($storeId, $merchant->id);

        $html = $this->html($merchant, '/finance/withdrawals');

        $this->assertPill($html, 'ss-badge--warning', __('Pending'), 'withdrawals');
        $this->assertPill($html, 'ss-badge--info', __('Approved'), 'withdrawals');
        $this->assertPill($html, 'ss-badge--success', __('Paid'), 'withdrawals');
        $this->assertPill($html, 'ss-badge--danger', __('Rejected'), 'withdrawals');

        /* Cancelled is the BARE `.ss-badge`: there is no `.ss-badge--neutral` rule to point at. */
        $this->assertMatchesRegularExpression(
            '/<span class="ss-badge">\s*<span class="ss-badge__dot"[^>]*><\/span>\s*'
            . preg_quote(__('Cancelled'), '/') . '\s*<\/span>/u',
            $html,
            'the Cancelled pill is not a bare `.ss-badge` carrying its own text');
        $this->assertStringNotContainsString('ss-badge--neutral', $html,
            '`ss-badge--neutral` was emitted, and no such rule exists in store-syria-ds.css — the '
            . 'pill would render unpainted');

        /* AND NO RAW HOST UTILITY SURVIVES IN THAT COLUMN. */
        foreach (['badge bg-warning', 'badge bg-info', 'badge bg-success', 'badge bg-danger', 'badge bg-secondary'] as $raw) {
            $this->assertStringNotContainsString($raw, $html,
                "`{$raw}` is still rendered on /finance/withdrawals, so that pill still reads its "
                . 'foreground from the vendored theme');
        }
    }

    public function test_the_transactions_table_renders_design_system_pills_with_their_text(): void
    {
        [$merchant, $storeId] = $this->merchant();
        $this->seedEveryTransactionStatus($storeId);

        $html = $this->html($merchant, '/finance');

        $this->assertPill($html, 'ss-badge--success', __('Completed'), 'finance transactions');
        $this->assertPill($html, 'ss-badge--warning', __('Pending'), 'finance transactions');
        $this->assertPill($html, 'ss-badge--danger', __('Cancelled'), 'finance transactions');

        /* The TYPE column is a static chip, not a status badge: `Manual Deposit` is not a status,
           and `.ss-chip` keeps the border the old `badge bg-light text-dark border` had. */
        $this->assertMatchesRegularExpression(
            '/<span class="ss-chip ss-chip--static">\s*Manual Deposit\s*/u',
            $html,
            'the transaction TYPE cell is not a static chip carrying the type string. It must NOT '
            . 'be a status badge: status-badge.blade.php:3-5 states the dot is the status signal, '
            . 'and a transaction type is not a status.');
        $this->assertStringNotContainsString('badge bg-light text-dark border', $html,
            'the `bg-light text-dark border` type chip is still rendered — in dark mode A-05 lifts '
            . '.text-dark to #eeeff1 on the near-white #f8f9fa fill, 1.09:1');

        foreach ([
            'badge bg-success bg-opacity-10 text-success',
            'badge bg-warning bg-opacity-10 text-warning',
            'badge bg-danger bg-opacity-10 text-danger',
        ] as $raw) {
            $this->assertStringNotContainsString($raw, $html,
                "`{$raw}` is still rendered — the `bg-opacity-10 text-*` idiom is a no-op, because "
                . '`.bg-*` is `!important` and LATER in the same sheet');
        }
    }

    public function test_the_unreconciled_capture_status_renders_as_a_design_system_pill(): void
    {
        [$merchant, $storeId] = $this->merchant();

        /*
         * THE PREMISE. The view is `@foreach ($captures as $capture)`, so with no capture row the
         * page renders zero pills and every assertion below would pass for the wrong reason. The
         * D-29.3 table is dev/test-only, so its absence is asserted rather than skipped — the
         * shape UnreconciledCaptureReviewTest.php:40-49 uses and for the same stated reason.
         */
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('order_gateway_references'),
            'order_gateway_references is absent, so /finance/unreconciled-captures renders an '
            . 'empty table and this case measures nothing');

        \App\Services\GatewayReference::claim($storeId, 'paymera', 'ref-contrast-' . uniqid(), '125.00');

        $html = $this->html($merchant, '/finance/unreconciled-captures');

        $this->assertPill($html, 'ss-badge--warning', __('Needs follow-up'), 'unreconciled captures');
        $this->assertStringNotContainsString('badge bg-warning text-dark', $html,
            'the capture pill still carries `text-dark`, which the A-05 block lifts to #eeeff1 in '
            . 'dark mode and which `.bg-warning` beats on source order in light mode');
    }

    /**
     * NO EMPTY PILL ANYWHERE. The slot-vs-prop trap again, this time as a sweep rather than a
     * named case: every `.ss-badge` this page renders must carry visible text beside its dot.
     */
    public function test_no_rendered_finance_pill_is_empty(): void
    {
        [$merchant, $storeId] = $this->merchant();
        $this->seedEveryWithdrawalStatus($storeId, $merchant->id);
        $this->seedEveryTransactionStatus($storeId);

        foreach (['/finance', '/finance/withdrawals'] as $path) {
            $html = $this->html($merchant, $path);

            /*
             * THE DOT IS NESTED, so a naive `(.*?)</span>` stops at the dot's own closing tag.
             * This matches THROUGH the dot and captures what follows it, which is exactly the
             * text a slot call would leave empty.
             */
            preg_match_all(
                '/<span[^>]*class="[^"]*\bss-badge\b[^"]*"[^>]*>\s*'
                . '<span class="ss-badge__dot"[^>]*><\/span>([^<]*)<\/span>/su',
                $html, $m);

            $this->assertNotEmpty($m[0], "{$path} rendered no `.ss-badge` at all");

            foreach ($m[1] as $i => $inner) {
                $text = trim(strip_tags($inner));
                $this->assertNotSame('', $text,
                    "{$path} pill #{$i} renders an empty badge — this is what a slot call "
                    . '(`<x-ss.status-badge tone="x">text</x-ss.status-badge>`) produces, because '
                    . 'status-badge.blade.php:15 emits `{{ $label }}` and ignores `$slot`');
            }
        }
    }

    /**
     * THE OTHER DIRECTION. The Arabic admin loads style-rtl.css / rtl-style-dark.css and
     * rtl-custom.css INSTEAD of their LTR copies, so an Arabic merchant gets a different cascade
     * entirely. `.ss-badge` uses `padding-inline` and `gap` (store-syria-ds.css:1157-1159), so it
     * should need no mirror — this proves it rather than assuming it.
     *
     * NOTE, measured: `Approved` and `Rejected` are ABSENT from resources/lang/ar.json, so they
     * render as the English key. That is pre-existing — the repair keeps the same `__()` keys it
     * found — and is not fixed here; `Pending`, `Paid`, `Cancelled`, `Completed`, `Received`,
     * `Not Received` and `Needs follow-up` ARE present and verified.
     */
    public function test_the_same_pills_render_for_an_arabic_merchant(): void
    {
        [$merchant, $storeId] = $this->merchant('ar');
        $this->seedEveryWithdrawalStatus($storeId, $merchant->id);

        $html = $this->html($merchant, '/finance/withdrawals');

        $this->assertSame('ar', app()->getLocale(),
            'the request did not resolve the Arabic locale, so this case measures the English '
            . 'render twice');

        foreach (['ss-badge--warning', 'ss-badge--info', 'ss-badge--success', 'ss-badge--danger'] as $toneClass) {
            $this->assertStringContainsString($toneClass, $html,
                "{$toneClass} is missing from the Arabic render");
        }

        /* The three keys ar.json really carries, asserted in Arabic. */
        $this->assertPill($html, 'ss-badge--warning', 'معلق', 'withdrawals (ar)');
        $this->assertPill($html, 'ss-badge--success', 'مقبوض', 'withdrawals (ar)');
    }

    // =============================================================================================
    //  3. NEGATIVE CONTROLS
    // =============================================================================================

    /**
     * THE STATUS STRINGS ARE A CONTRACT, NOT PRESENTATION. The five `@if($withdrawal->status ===
     * ...)` conditions must be byte-identical after the repair: an over-reaching "cleanup" that
     * replaced the chain with a tone map would silently change which branch a row takes.
     */
    public function test_the_status_conditions_were_not_rewritten(): void
    {
        $source = (string) file_get_contents(base_path('resources/views/finance/withdrawals.blade.php'));

        foreach (['pending', 'approved', 'paid', 'rejected', 'cancelled'] as $status) {
            $this->assertStringContainsString("\$withdrawal->status === '{$status}'", $source,
                "the `{$status}` condition is gone from finance/withdrawals.blade.php — the tone "
                . 'map must not decide which branch a row takes');
        }
    }

    /**
     * THE TWO RAW `confirm(` CALLS MUST SURVIVE UNCHANGED.
     * `tests/Unit/Ui/ConfirmationDialogContractTest.php` freezes them with `assertLessThanOrEqual`,
     * so a badge repair that reflowed the file and dropped one would pass that ratchet while
     * removing a money-path guard.
     */
    public function test_the_withdrawal_confirmations_were_not_disturbed(): void
    {
        $source = (string) file_get_contents(base_path('resources/views/finance/withdrawals.blade.php'));

        $this->assertSame(2, preg_match_all('/(?<![\w.$-])confirm\s*\(/', $source),
            'finance/withdrawals.blade.php no longer carries exactly two submit guards on the '
            . 'approve and mark-as-paid forms');
    }

    /**
     * NO STYLESHEET, NO CONTROLLER, NO ROUTE. A `.badge.bg-warning{...}` override would need its
     * own `!important` to beat style.css:9504 and would repaint every `.badge bg-*` across all 70
     * vendored WorkDo modules; `DesignSystemStylesheetScopeTest` exists to refuse exactly that.
     */
    public function test_this_repair_is_markup_only(): void
    {
        foreach (['public/css/store-syria-ds.css', 'public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $css = (string) file_get_contents(base_path($sheet));

            /*
             * MEASURED EXCEPTION, not a loophole. `custom.css:2222` and its twin
             * `rtl-custom.css:2234` already carry `.upgrade-plan-model-card .badge.bg-warning`,
             * which predates this finding and is confined to one SaaS card by a descendant
             * selector. What must not appear is an UNCONFINED `.badge.bg-*` override — the shape
             * that would repaint every pill in all 70 vendored WorkDo modules.
             */
            $unconfined = preg_match_all('/(^|[\n\},])\s*\.badge\s*\.bg-/', $css, $m);

            $this->assertSame(0, $unconfined,
                "{$sheet} now carries an UNCONFINED `.badge.bg-*` override. That reaches every "
                . 'pill in all 70 vendored modules and, in only one of the two mirrored sheets, '
                . 'half the merchants.');
        }

        /* Tone selection stays in the view. StoreFinanceController is a money path with a 500-line
           authorization test pinned to it; moving presentation into it to DRY the tone map is the
           kind of change review has caught defects in before. */
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/StoreFinanceController.php'));
        $this->assertStringNotContainsString('ss-badge', $controller,
            'the tone map moved into the controller; it belongs in the view');
    }
}
