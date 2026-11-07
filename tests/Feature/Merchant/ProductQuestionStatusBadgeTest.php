<?php

namespace Tests\Feature\Merchant;

use App\Models\Customer;
use App\Models\ProductQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5 product-question-status-off-theme-badge — the Status column on the Product Question list.
 * =================================================================================================
 *
 * WHAT WAS WRONG. `ProductQuestionDataTable::dataTable()` built the Status cell as a raw string
 * wearing the vendored theme's own colour classes:
 *
 *     '<span class="badges fix_badges bg-success p-2 px-3">' . __('Answered') . '</span>'
 *     '<span class="badges fix_badges bg-danger  p-2 px-3">' . __('Pending')  . '</span>'
 *
 * `.badges` (public/css/custom.css:737-758) sets `--bs-badge-color:#ffffff` applied through
 * `color: var(--bs-badge-color)` (:749) at `font-size:0.75em` / `font-weight:500`, so the cell is
 * normal-weight text under 18.66px and the AA minimum (WCAG 2.2 SC 1.4.3) is 4.5:1. Both tones
 * then took their colour from the host:
 *
 *   - "Pending" / `.bg-danger` (public/assets/css/style.css:9514-9518, byte-identical at
 *     style-dark.css:9518-9522) is `background-color: rgba(255,174,189,0.41) !important` with
 *     `color: #d80027 !important`. The fill is TRANSLUCENT, so it composites with the card it sits
 *     on: over the light card `--bs-card-bg:#ffffff` the effective background is #ffdee4 and the
 *     reading is 4.26:1 (fails 4.5:1); over the dark card `#292A33` (style-dark.css:5006) it is
 *     #81606c and the reading is 1.03:1 — effectively invisible. It fails in BOTH themes.
 *
 *   - "Answered" / `.bg-success` (style.css:9488-9492, identical at style-dark.css:9492-9496) is
 *     `background-color: var(--theme-color) !important` with `color: var(--bs-white) !important`.
 *     The label colour is hard-wired white while the fill is the MERCHANT'S OWN brand colour — ten
 *     presets plus a free picker. On today's platform default (#335da8, StoreSyriaBrand.php:37,53
 *     via layouts/app.blade.php:42-44) white reads 6.42:1 and passes; on the still-selectable
 *     theme-3 preset #10B77F it reads 2.59:1, and this repository's own recorded measurement at
 *     public/css/custom.css:4874-4887 puts six of the ten presets below 4.5:1 with white text.
 *     So this half is latent on the shipped default and live for a merchant who picks their brand.
 *
 * THE REPAIR IS PHP ONLY — three lines in one file, routed through `App\Support\DsBadge`, which is
 * what the already-migrated ProductDataTable, UserDataTable and CategoryDataTable use.
 * `.ss-badge--success` / `.ss-badge--danger` (public/css/store-syria-ds.css:1169-1173) paint their
 * own OPAQUE background from the design system's theme-aware tokens, so the card colour cannot
 * enter the calculation and `--theme-color` is never read. Measured below, by this file, from the
 * stylesheets rather than quoted: 7.10:1 and 8.79:1 (success light/dark), 8.09:1 and 9.06:1
 * (danger light/dark).
 *
 * NO STYLESHEET IS TOUCHED, and that is the point of the RTL control below: `.ss-badge` is laid
 * out with `gap` and `padding-inline` (store-syria-ds.css:1157,1160), so it mirrors in Arabic with
 * no second rule and `public/css/rtl-custom.css` is owed no twin edit. A repair that hand-built the
 * span with `p-2 px-3` or physical padding would have looked right in LTR and wrong for every
 * Arabic merchant.
 *
 * WHAT IS DELIBERATELY NOT CLAIMED. `DsBadge::render()` escapes its label, but that is NOT a
 * hardening benefit here: `escapeColumns()` is called nowhere in this application, so yajra escapes
 * nothing by default and the `rawColumns()` list at :40 is decorative — and `__('Answered')` /
 * `__('Pending')` are translation strings, never user input. The REAL escaping defect in this same
 * method is `editColumn('customer_id')` at :29-31, which returns `$question->users->name` (a
 * customer-supplied name, `hasOne(Customer)`) unescaped into the merchant admin inside the same
 * `rawColumns` array. That is a separate stored-XSS finding with its own fix (`e(...)`); it is
 * asserted below as STILL PRESENT so this cosmetic change cannot be mistaken for having hardened
 * the cell, and so the day it is fixed this test says so instead of going quiet.
 *
 * WHAT IS DELIBERATELY NOT CHANGED, and asserted below: the permission gate at
 * ProductQuestionController:20, the tenant predicate at ProductQuestionDataTable::query():49, the
 * column set, and DataTables sorting/searching — which still run against the real `answers` DB
 * column, not against the rendered word.
 */
class ProductQuestionStatusBadgeTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    /** 4.5:1 — SC 1.4.3 for text below 18.66px bold / 24px. The badge is 0.75rem/500. */
    private const AA_TEXT = 4.5;

    /** The host classes that must not survive on this cell. */
    private const HOST_CLASSES = ['bg-success', 'bg-danger', 'badges', 'fix_badges'];

    /* The exact markup both themes and both directions must produce, written out LITERALLY rather
       than by calling DsBadge::render() -- an assertion against the helper's own output would pass
       whatever the helper emitted. */
    private const EXPECTED_SUCCESS_CELL =
        '<span class="ss-badge ss-badge--success"><span class="ss-badge__dot" aria-hidden="true"></span>Answered</span>';

    private const EXPECTED_DANGER_CELL =
        '<span class="ss-badge ss-badge--danger"><span class="ss-badge__dot" aria-hidden="true"></span>Pending</span>';

    // =============================================================================================
    // Contrast — computed from the stylesheets, never quoted
    // =============================================================================================

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $c = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function ratio(string $foreground, string $background): float
    {
        $a = $this->luminance($foreground);
        $b = $this->luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** `rgba(r,g,b,a)` painted over an opaque hex, the way a browser composites it. */
    private function over(array $rgba, string $background): string
    {
        [$r, $g, $b, $alpha] = $rgba;
        $background = ltrim($background, '#');

        $out = '';

        foreach ([[$r, 0], [$g, 2], [$b, 4]] as [$channel, $offset]) {
            $under = hexdec(substr($background, $offset, 2));
            $out .= sprintf('%02x', (int) round($alpha * $channel + (1 - $alpha) * $under));
        }

        return '#' . $out;
    }

    private function designSystem(): string
    {
        return (string) file_get_contents(base_path('public/css/store-syria-ds.css'));
    }

    /**
     * A design-system token's value, resolved through one level of `var()` indirection, read out of
     * the block that starts at `$blockStart` (`:root` for light, the dark override for dark).
     */
    private function token(string $name, int $blockStart): string
    {
        $css = substr($this->designSystem(), $blockStart);

        $value = null;

        if (preg_match('/--' . preg_quote($name, '/') . '\s*:\s*([^;]+);/', $css, $m)) {
            $value = trim($m[1]);
        }

        $this->assertNotNull($value, "the design system no longer declares --{$name} after offset {$blockStart}");

        if (preg_match('/^var\(\s*(--[\w-]+)\s*\)$/', (string) $value, $m)) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($m[1], '/') . '\s*:\s*(#[0-9a-fA-F]{6})\s*;/',
                $this->designSystem(),
                "the indirect token {$m[1]} no longer resolves to a hex"
            );
            preg_match('/' . preg_quote($m[1], '/') . '\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $this->designSystem(), $hit);
            $value = $hit[1];
        }

        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', (string) $value,
            "--{$name} is not a plain hex, so the ratio below would be fiction");

        return strtolower((string) $value);
    }

    // =============================================================================================
    // Driving the real screen
    // =============================================================================================

    private function question(int $storeId, int $customerId, string $text, ?string $answer): ProductQuestion
    {
        return ProductQuestion::forceCreate([
            'question' => $text,
            'answers' => $answer,
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'product_id' => null,
            'created_by' => 1,
        ]);
    }

    private function customer(int $storeId, string $name): Customer
    {
        /* `customers` has no `name` column: Customer::getNameAttribute() appends it from
           first_name + last_name (app/Models/Customer.php:83,92). */
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        return Customer::withoutGlobalScopes()->forceCreate([
            'first_name' => $first,
            'last_name' => $last,
            'email' => 'q' . uniqid() . '@example.test',
            'password' => bcrypt('secret-' . uniqid()),
            'store_id' => $storeId,
            'created_by' => 1,
        ]);
    }

    /**
     * The full HTML page. Same singleton hazard as `rows()`, and WORSE here: `$dataTable->render()`
     * decides JSON-vs-view from yajra's request object, so a plain page GET that follows an AJAX
     * call in the same test method was served the DataTables JSON instead of the shell. Measured.
     */
    private function page(User $user)
    {
        app()->forgetInstance('datatables.request');

        return $this->actingAs($user)->get('/product-question');
    }

    /** The AJAX leg of the real list, as `question text => raw answers cell`. */
    private function rows(User $user, array $filters = []): array
    {
        $query = array_merge($filters, ['draw' => 1, 'start' => 0, 'length' => 100]);

        foreach (['customer_id', 'question', 'answers'] as $i => $column) {
            $query["columns[{$i}][data]"] = $column;
            $query["columns[{$i}][name]"] = $column;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 1;
        $query['order[0][dir]'] = 'asc';
        $query['search[regex]'] = 'false';
        $query['search[value]'] = $query['search[value]'] ?? '';

        /* TEST-HARNESS HAZARD, measured: `datatables.request` is a container SINGLETON
           (yajra DataTablesServiceProvider:28) whose constructor captures `app('request')` once.
           A real HTTP request gets a fresh container; two `$this->getJson()` calls inside one test
           method do not, so without this the SECOND call is served with the FIRST call's
           DataTables input -- a search for one term returned the previous term's rows. Verified by
           probe: dropping the instance makes the second search filter correctly, and neither
           Cache::flush() nor flushCache() touched it, so it was never the model cache. */
        app()->forgetInstance('datatables.request');

        $response = $this->actingAs($user)
            ->withHeaders(self::AJAX)
            ->getJson('/product-question?' . http_build_query($query));

        $this->assertNotSame(404, $response->getStatusCode(), 'premise: the product-question route must exist');
        $this->assertNotSame(500, $response->getStatusCode(),
            'premise: the list must not 500 -- ' . substr(strip_tags($response->getContent()), 0, 400));

        $payload = $response->json();
        $this->assertIsArray($payload, 'premise: the AJAX leg must answer with JSON, not HTML');
        $this->assertArrayHasKey('draw', $payload, 'premise: yajra served the AJAX leg');

        $out = [];

        foreach ($payload['data'] ?? [] as $row) {
            $out[strip_tags((string) ($row['question'] ?? ''))] = (string) ($row['answers'] ?? '');
        }

        return $out;
    }

    /** A merchant with the permission, a store, and one answered + one unanswered question. */
    private function scenario(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Product Question');

        $customer = $this->customer($store->id, 'Hiba Q');

        $this->question($store->id, $customer->id, 'Is it waterproof?', 'Yes, fully.');
        $this->question($store->id, $customer->id, 'Does it ship to Aleppo?', null);

        return [$merchant, $store, $customer];
    }

    // =============================================================================================
    // 1. Reachability first — IconNamesExistTest:100-110's pattern
    // =============================================================================================

    public function test_the_premise_both_status_cells_are_reachable_and_carry_their_label(): void
    {
        [$merchant] = $this->scenario();

        $rows = $this->rows($merchant);

        $this->assertCount(2, $rows, 'premise: both questions must come back, or every assertion below is about nothing');

        foreach (['Is it waterproof?' => 'Answered', 'Does it ship to Aleppo?' => 'Pending'] as $question => $label) {
            $this->assertArrayHasKey($question, $rows, "premise: the row for \"{$question}\" was not served");
            $this->assertNotSame('', trim($rows[$question]), "the status cell for \"{$question}\" is EMPTY");
            $this->assertStringContainsString($label, $rows[$question],
                "the status cell for \"{$question}\" no longer says \"{$label}\"");
            $this->assertStringContainsString('<span', $rows[$question],
                'premise: the cell must still be markup -- `answers` is in rawColumns() at :40');
        }
    }

    // =============================================================================================
    // 2. The repair — the design system's badge, and none of the host's classes
    // =============================================================================================

    public function test_both_status_cells_wear_the_design_system_badge(): void
    {
        [$merchant] = $this->scenario();

        $rows = $this->rows($merchant);

        $expected = [
            'Is it waterproof?' => ['Answered', 'ss-badge--success'],
            'Does it ship to Aleppo?' => ['Pending', 'ss-badge--danger'],
        ];

        foreach ($expected as $question => [$label, $toneClass]) {
            $cell = $rows[$question];

            // Reachability again, locally: assert the label BEFORE the class.
            $this->assertStringContainsString($label, $cell, "premise: \"{$label}\" missing from the cell");

            $this->assertStringContainsString('class="ss-badge ' . $toneClass . '"', $cell,
                "the status cell for \"{$question}\" does not wear the design system badge -- it is still "
                . 'taking its colour from the vendored theme (measured 4.26:1 light / 1.03:1 dark for '
                . '.bg-danger, and white on the merchant-chosen --theme-color for .bg-success)');

            $this->assertStringContainsString('ss-badge__dot', $cell,
                'the badge must come from DsBadge::render(), which is what guarantees the dot -- a '
                . 'hand-built span omits it, which is exactly how the first CategoryDataTable attempt failed');
        }
    }

    public function test_the_status_cells_carry_none_of_the_host_colour_classes(): void
    {
        [$merchant] = $this->scenario();

        $rows = $this->rows($merchant);

        foreach ($rows as $question => $cell) {
            $this->assertNotSame('', trim($cell), "premise: the cell for \"{$question}\" is empty");

            foreach (self::HOST_CLASSES as $class) {
                $this->assertStringNotContainsString($class, $cell,
                    "the status cell for \"{$question}\" still carries `{$class}`. .ss-badge--danger sets "
                    . 'background and colour with NO !important (store-syria-ds.css:1171) while .bg-danger '
                    . 'sets both WITH !important (style.css:9514-9518), so leaving the old class in place '
                    . 'would look fixed in the source and unfixed on screen.');
            }
        }
    }

    /**
     * THE MERCHANT'S BRAND MUST STAY THE MERCHANT'S BRAND. The whole reason this repair is PHP and
     * not CSS: a patch to `.bg-success { color }` would have repainted every other `.bg-success`
     * on the admin, and a patch to its `background` would have overwritten the merchant's own brand
     * colour. The new cell must not read `--theme-color` at all.
     */
    public function test_the_status_cells_never_read_the_merchant_brand_colour(): void
    {
        [$merchant] = $this->scenario();

        $rows = $this->rows($merchant);

        foreach ($rows as $question => $cell) {
            $this->assertStringContainsString('ss-badge', $cell, "premise: the cell for \"{$question}\" is not a badge");

            foreach (['--theme-color', 'theme-color', 'bs-white', 'style=', 'px-3', 'p-2'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $cell,
                    "the status cell for \"{$question}\" references `{$forbidden}` -- the badge must take "
                    . 'its colour from the design system tokens only, never from the merchant-chosen brand.');
            }
        }

        // And nothing in this repair may have reached a stylesheet: `.ss-badge` is declared in the
        // design system alone, so no host `!important` competes with it and no RTL twin is owed.
        foreach (glob(base_path('public/css/*.css')) ?: [] as $sheet) {
            if (basename($sheet) === 'store-syria-ds.css') {
                continue;
            }

            $this->assertStringNotContainsString('ss-badge', (string) file_get_contents($sheet),
                basename($sheet) . ' now declares .ss-badge. The badge must stay owned by the design '
                . 'system: a rule in custom.css would need the identical rule in rtl-custom.css, and '
                . 'fixing one reaches half the merchants.');
        }
    }

    /**
     * AND THE BRAND SURFACE ITSELF STILL WORKS. The over-reach this repair had to avoid was patching
     * `.bg-success` in a stylesheet: that would have repainted every other `.bg-success` on the
     * admin AND overwritten the merchant's own brand colour. This drives a store that HAS picked a
     * colour -- `color_flag = 'true'`, the branch at layouts/app.blade.php:45-47 -- and asserts the
     * page still publishes that colour for the sidebar and `.btn-primary` to use, while the badge
     * cell is byte-identical to the default-brand render.
     */
    public function test_a_merchant_who_picked_a_brand_colour_keeps_it(): void
    {
        [$merchant, $store] = $this->scenario();

        foreach (['color' => '#10B77F', 'color_flag' => 'true'] as $name => $value) {
            \App\Models\Setting::updateOrCreate(
                ['name' => $name, 'store_id' => $store->id],
                ['value' => $value, 'created_by' => $merchant->id]
            );
        }

        $page = $this->page($merchant);
        $page->assertStatus(200);

        $html = $page->getContent();

        $this->assertStringContainsString('--color-customColor: #10B77F', $html,
            'the merchant\'s own brand colour is no longer published -- custom-color.css drives 39 '
            . 'selectors off it, the active sidebar item among them');
        $this->assertStringContainsString('custom-color', $html,
            'premise: the custom-color body class carries the merchant brand');

        // The badge is unmoved by the brand: same markup as the default-brand render above.
        $rows = $this->rows($merchant);
        $this->assertSame(self::EXPECTED_SUCCESS_CELL, $rows['Is it waterproof?'],
            'the badge changed with the merchant brand colour -- it must not read --theme-color at all');
        $this->assertSame(self::EXPECTED_DANGER_CELL, $rows['Does it ship to Aleppo?']);
    }

    // =============================================================================================
    // 3. NEGATIVE CONTROL — the other theme, and the other direction
    // =============================================================================================

    /**
     * BOTH THEMES. The cell is theme-independent BY CONSTRUCTION after the repair — the tones come
     * from CSS custom properties redefined under `[data-ss-theme="dark"]` — so the proof is twofold:
     * the markup is byte-identical in both configurations (asserted here), and both token pairs
     * clear AA (asserted in the contrast test below). A fix verified only in light would leave the
     * 1.03:1 dark reading, the worst of the two, unexamined.
     */
    public function test_the_status_cells_are_the_design_system_badge_in_the_light_theme(): void
    {
        [$merchant] = $this->scenario();

        // Premise: the shell really is in the light theme.
        $page = $this->page($merchant);
        $page->assertStatus(200);
        $this->assertStringNotContainsString('data-ss-theme="dark"', $page->getContent(),
            'premise: with no cust_darklayout setting the shell must be light (layouts/app.blade.php:86)');
        $this->assertStringContainsString('store-syria-ds.css', $page->getContent(),
            'premise: the design system stylesheet must be linked, or .ss-badge has no colours at all');

        $rows = $this->rows($merchant);

        $this->assertSame(self::EXPECTED_SUCCESS_CELL, $rows['Is it waterproof?']);
        $this->assertSame(self::EXPECTED_DANGER_CELL, $rows['Does it ship to Aleppo?']);
    }

    /**
     * THE OTHER THEME, in its own test method ON PURPOSE. A fix verified only in light would leave
     * the 1.03:1 dark reading -- the worse of the two -- unexamined. It is a separate method
     * because `cust_darklayout` must be set BEFORE the first render of the process: with the light
     * page rendered first, the settings read stayed stale for the rest of the method even after
     * `Cache::flush()` and an Eloquent write, which is the `Cachable` hazard CLAUDE.md records.
     * Both methods assert the SAME literal markup, which is what proves theme-independence.
     */
    public function test_the_status_cells_are_the_design_system_badge_in_the_dark_theme(): void
    {
        [$merchant, $store] = $this->scenario();

        // Switched the way the merchant switches it: the `cust_darklayout` settings row that
        // `getAdminAllSetting()` reads (helper.php:740, layouts/app.blade.php:3) and that
        // `/customize-settings` writes.
        \App\Models\Setting::updateOrCreate(
            ['name' => 'cust_darklayout', 'store_id' => $store->id],
            ['value' => 'on', 'created_by' => $merchant->id]
        );

        $page = $this->page($merchant);
        $page->assertStatus(200);
        $this->assertStringContainsString('data-ss-theme="dark"', $page->getContent(),
            'premise: the dark theme never activated, so this test would be a second light-theme run');

        $rows = $this->rows($merchant);

        $this->assertSame(self::EXPECTED_SUCCESS_CELL, $rows['Is it waterproof?'],
            'the dark render does not match the light one -- the cell must be theme-independent, with '
            . 'the colours coming from tokens redefined under [data-ss-theme="dark"]');
        $this->assertSame(self::EXPECTED_DANGER_CELL, $rows['Does it ship to Aleppo?']);

        foreach ($rows as $cell) {
            $this->assertStringNotContainsString('bg-danger', $cell, 'the dark render kept the host class');
            $this->assertStringNotContainsString('bg-success', $cell, 'the dark render kept the host class');
        }
    }

    /**
     * BOTH DIRECTIONS. Arabic forces SITE_RTL and loads `rtl-custom.css` INSTEAD of `custom.css`
     * (layouts/app.blade.php:65-67), so a repair that hand-built physical padding would be right in
     * English and wrong in Arabic. This asserts the two things that make an RTL twin unnecessary:
     * the label translates, and the badge's inline spacing is LOGICAL (`padding-inline`, `gap`), so
     * one rule mirrors itself.
     */
    public function test_the_labels_translate_and_the_badge_spacing_is_logical_so_no_rtl_twin_is_owed(): void
    {
        [$merchant] = $this->scenario();

        /* The locale comes from the REQUEST, not from app()->setLocale(): SetLocate middleware
           reads Session::get('LANGUAGE') on every request (app/Http/Middleware/SetLocate.php:27,95)
           and would reset a locale set in the test process. Driven the way the language switcher
           drives it. */
        $this->withSession(['LANGUAGE' => 'en']);
        $english = $this->rows($merchant);
        $this->assertStringContainsString('Pending', $english['Does it ship to Aleppo?'],
            'premise: the English label must be present');

        $this->withSession(['LANGUAGE' => 'ar']);
        $arabic = $this->rows($merchant);

        $this->assertNotSame($english, $arabic,
            'premise: the Arabic render is byte-identical to the English one, so the locale never '
            . 'changed and the assertions below would be about the English render');

        $this->assertStringContainsString('ss-badge--danger', $arabic['Does it ship to Aleppo?'],
            'the Arabic render lost the badge tone');
        $this->assertStringContainsString('معلق', $arabic['Does it ship to Aleppo?'],
            'the label must stay inside __() -- inlining English would break every Arabic merchant '
            . '(resources/lang/ar.json:35 "Pending", :1993 "Answered")');
        $this->assertStringContainsString('أجبت', $arabic['Is it waterproof?'],
            'the answered label must stay inside __() too');

        // The CSS half of the same control: logical properties, and no physical padding.
        $css = $this->designSystem();
        $this->assertMatchesRegularExpression('/\.ss-badge\s*\{[^}]*padding-inline\s*:/', $css,
            '.ss-badge no longer uses padding-inline, so Arabic would need a second rule in rtl-custom.css');
        $this->assertDoesNotMatchRegularExpression('/\.ss-badge\s*\{[^}]*padding-(left|right)\s*:/', $css,
            '.ss-badge has gained physical padding -- it would look right in LTR and wrong in Arabic');
    }

    // =============================================================================================
    // 4. The measured contrast — the repaired tones pass, the classes removed did not
    // =============================================================================================

    public function test_the_tokens_this_badge_uses_clear_aa_in_both_themes(): void
    {
        $css = $this->designSystem();

        $darkBlock = strpos($css, '[data-ss-theme="dark"]');
        $this->assertNotFalse($darkBlock, 'premise: the design system no longer has a dark override block');

        $readings = [
            'success light' => [$this->token('ss-success-fg', 0), $this->token('ss-success-bg', 0), 7.10],
            'danger light' => [$this->token('ss-danger-fg', 0), $this->token('ss-danger-bg', 0), 8.09],
            'success dark' => [$this->token('ss-success-fg', $darkBlock), $this->token('ss-success-bg', $darkBlock), 8.79],
            'danger dark' => [$this->token('ss-danger-fg', $darkBlock), $this->token('ss-danger-bg', $darkBlock), 9.06],
        ];

        foreach ($readings as $name => [$fg, $bg, $expected]) {
            $measured = $this->ratio($fg, $bg);

            $this->assertGreaterThanOrEqual(self::AA_TEXT, $measured, sprintf(
                '%s is %.2f:1 (%s on %s) -- below the %.1f:1 AA minimum for 0.75rem/500 text',
                $name, $measured, $fg, $bg, self::AA_TEXT
            ));

            $this->assertEqualsWithDelta($expected, $measured, 0.02, sprintf(
                '%s measured %.2f:1, not the %.2f:1 recorded in this file and in the ledger -- a token '
                . 'moved and the recorded figure is now fiction', $name, $measured, $expected
            ));
        }
    }

    /**
     * The other half of the same measurement: the classes the repair removed REALLY DID fail, so
     * this file does not assert an improvement over a baseline it has not computed. Both figures
     * are read out of the shipped stylesheets, not quoted.
     */
    public function test_the_host_classes_this_repair_removed_really_did_fail_aa(): void
    {
        $light = (string) file_get_contents(base_path('public/assets/css/style.css'));
        $dark = (string) file_get_contents(base_path('public/assets/css/style-dark.css'));

        // .bg-danger: a translucent fill, so it composites with the card beneath it.
        $this->assertMatchesRegularExpression(
            '/\.bg-danger\s*\{[^}]*background-color:\s*rgba\(255,\s*174,\s*189,\s*0?\.41\)\s*!important/',
            $light,
            'premise: style.css no longer declares .bg-danger as the translucent pink this measures'
        );
        $this->assertMatchesRegularExpression('/\.bg-danger\s*\{[^}]*color:\s*#d80027\s*!important/', $light,
            'premise: .bg-danger no longer uses #d80027');
        $this->assertMatchesRegularExpression(
            '/\.bg-danger\s*\{[^}]*background-color:\s*rgba\(255,\s*174,\s*189,\s*0?\.41\)\s*!important/',
            $dark,
            'premise: the dark sheet no longer repeats the rule unchanged, so the dark figure would be wrong'
        );

        $this->assertMatchesRegularExpression('/--bs-card-bg:\s*#292A33/i', $dark,
            'premise: the dark card colour this composites over has moved');

        $onLightCard = $this->ratio('#d80027', $this->over([255, 174, 189, 0.41], '#ffffff'));
        $onDarkCard = $this->ratio('#d80027', $this->over([255, 174, 189, 0.41], '#292A33'));

        $this->assertLessThan(self::AA_TEXT, $onLightCard, sprintf(
            '.bg-danger measures %.2f:1 on the light card, which would mean the defect is gone', $onLightCard));
        $this->assertEqualsWithDelta(4.26, $onLightCard, 0.02, 'the recorded 4.26:1 light figure no longer reproduces');
        $this->assertEqualsWithDelta(1.03, $onDarkCard, 0.02, 'the recorded 1.03:1 dark figure no longer reproduces');

        // .bg-success: white hard-wired over the merchant-chosen brand colour.
        $this->assertMatchesRegularExpression(
            '/\.bg-success\s*\{[^}]*background-color:\s*var\(--theme-color\)\s*!important/', $light,
            'premise: .bg-success no longer fills with the merchant brand colour');
        $this->assertMatchesRegularExpression('/\.bg-success\s*\{[^}]*color:\s*var\(--bs-white\)\s*!important/', $light,
            'premise: .bg-success no longer hard-wires white');

        // Today's platform default passes; a still-selectable preset does not. That is the defect:
        // the label colour cannot be chosen once for a fill the merchant picks.
        $this->assertGreaterThan(self::AA_TEXT, $this->ratio('#ffffff', '#335da8'),
            'premise: white on the shipped default brand #335da8 should pass -- that half was latent');
        $this->assertLessThan(self::AA_TEXT, $this->ratio('#ffffff', '#10B77F'),
            'premise: white on the theme-3 preset #10B77F should fail -- that is the live half');
        $this->assertEqualsWithDelta(2.59, $this->ratio('#ffffff', '#10B77F'), 0.02,
            'the recorded 2.59:1 theme-3 figure no longer reproduces');
    }

    // =============================================================================================
    // 5. NEGATIVE CONTROLS — nothing a caller relies on may have moved
    // =============================================================================================

    /**
     * THE PERMISSION GATE MUST NOT MOVE. ProductQuestionController:20 gates index on
     * `isAbleTo('Manage Product Question')` with NO operator-first branch, so the platform operator
     * is refused today — Laratrust has no super-admin bypass. That is a separate pre-existing issue
     * and is asserted here AS IT STANDS, so this repair cannot have quietly changed it either way.
     */
    public function test_the_permission_gate_is_unchanged(): void
    {
        [$merchant] = $this->scenario();

        $this->page($merchant)->assertStatus(200);

        $ungranted = $this->makeMerchant();
        $store = $this->makeStore($ungranted);
        $ungranted->current_store = $store->id;
        $ungranted->save();
        $ungranted->flushCache();

        $this->page($ungranted->fresh())
            ->assertRedirect()
            ->assertSessionHas('error');

        // The operator is still refused -- recorded, not endorsed.
        $operator = $this->makePlatformAdmin();
        $operator->flushCache();
        $this->assertSame('super admin', strtolower(trim((string) $operator->type)),
            'premise: makePlatformAdmin() must really make a super admin');
        $this->assertFalse($operator->isAbleTo('Manage Product Question'),
            'the gate at ProductQuestionController:20 has changed shape -- it tests isAbleTo() only, '
            . 'with no operator-first branch, and that is tracked as its own finding');
    }

    /**
     * THE TENANT PREDICATE MUST NOT MOVE. `query():49` is `where('store_id', getCurrentStore())`.
     * The store-B side is built and read through `withoutGlobalScopes()` so the absence assertion
     * answers against the TABLE, not against whichever tenant happens to be resolved.
     */
    public function test_store_b_questions_stay_out_of_store_a_payload(): void
    {
        [$merchantA] = $this->scenario();

        [$merchantB, $storeB] = $this->makeTenant();
        $customerB = $this->customer($storeB->id, 'Other Tenant');
        $this->question($storeB->id, $customerB->id, 'Store B secret question', 'Store B secret answer');

        // Premise, against the raw table: store B's row really exists.
        $this->assertSame(1, ProductQuestion::withoutGlobalScopes()->where('store_id', $storeB->id)->count(),
            'premise: store B must really have a question, or the absence below proves nothing');

        $rows = $this->rows($merchantA);

        $this->assertArrayNotHasKey('Store B secret question', $rows,
            'store B\'s question reached store A\'s list -- the predicate at query():49 has moved');
        $this->assertArrayHasKey('Is it waterproof?', $rows, 'store A lost its own rows');

        foreach ($rows as $cell) {
            $this->assertStringNotContainsString('Store B secret', $cell, 'store B data leaked into a cell');
        }
    }

    /**
     * SORT AND SEARCH MUST NOT CHANGE. `editColumn('answers')` rewrites DISPLAY only; DataTables
     * still searches the real `answers` DB column. A repair that moved the branch into `query()` or
     * added a computed column would change which rows a search returns.
     */
    public function test_search_still_runs_against_the_answers_column_and_not_the_rendered_word(): void
    {
        [$merchant] = $this->scenario();

        $byStoredAnswer = $this->rows($merchant, ['search[value]' => 'Yes, fully']);
        $this->assertArrayHasKey('Is it waterproof?', $byStoredAnswer,
            'a search for the stored answer text no longer finds the row -- search has stopped reading '
            . 'the real `answers` column');
        $this->assertArrayNotHasKey('Does it ship to Aleppo?', $byStoredAnswer,
            'the search matched a row whose answers column does not contain the term');

        // The rendered label is display-only and was never searchable. Unchanged by this repair.
        $byRenderedLabel = $this->rows($merchant, ['search[value]' => 'Answered']);
        $this->assertArrayNotHasKey('Is it waterproof?', $byRenderedLabel,
            'the rendered badge word has become searchable, so the branch moved out of editColumn()');
    }

    /**
     * THE COLUMN SET MUST NOT CHANGE — the AJAX JSON shape is a contract for the page's own script.
     */
    public function test_the_column_set_is_unchanged(): void
    {
        $columns = (new \App\DataTables\ProductQuestionDataTable())->getColumns();

        $names = array_map(static fn ($column) => $column->toArray()['data'] ?? null, $columns);

        foreach (['customer_id', 'question', 'answers'] as $expected) {
            $this->assertContains($expected, $names, "the `{$expected}` column has disappeared");
        }
    }

    /**
     * THE ESCAPING CLAIM IS NOT MADE, AND THE REAL BUG IS RECORDED. `DsBadge::render()` escapes its
     * label, but `escapeColumns()` is called nowhere in this application and the labels are
     * translation strings, so nothing was hardened here. The genuine stored-XSS shape in the same
     * method — `editColumn('customer_id')` returning `$question->users->name` unescaped inside
     * `rawColumns` — is asserted as STILL PRESENT, so this change cannot be read as having fixed it
     * and so the day it IS fixed this assertion says so rather than going quiet.
     */
    public function test_the_customer_name_column_is_still_unescaped_and_that_is_a_separate_finding(): void
    {
        $source = (string) file_get_contents(base_path('app/DataTables/ProductQuestionDataTable.php'));

        $this->assertStringContainsString("rawColumns(['action','customer_id','answers'])", $source,
            'the rawColumns list has changed, so the note below needs rewriting');

        $this->assertMatchesRegularExpression(
            '/editColumn\(\'customer_id\'.*?\$question->users->name/s', $source,
            'editColumn(customer_id) no longer returns $question->users->name raw. If it was fixed with '
            . 'e(...), DELETE THIS ASSERTION and close the separate stored-XSS finding; if the column was '
            . 'removed, the finding is moot. Either way this badge repair did not do it, and must not be '
            . 'recorded as having done it.'
        );

        // And the badge repair itself claims nothing about escaping.
        $this->assertStringNotContainsString('escap', strtolower($source),
            'this file must not claim an escaping benefit for the badge substitution -- escapeColumns() '
            . 'is called nowhere in this application and the labels are translation strings');
    }
}
