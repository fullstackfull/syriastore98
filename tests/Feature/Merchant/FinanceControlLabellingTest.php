<?php

namespace Tests\Feature\Merchant;

use App\Models\Permission;
use App\Models\StoreWallet;
use App\Models\StoreWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5 finance-controls-have-no-labels — 15 labelled controls, 0 programmatic associations.
 * =================================================================================================
 *
 * MEASURED BEFORE THE REPAIR. `resources/views/finance/index.blade.php` carried 14 form controls
 * under 14 `<label class="form-label">` elements and not one `for`/`id` pair; the only `id` in the
 * whole form was `searchField` (:214), which the inline script needs and no label points at.
 * `resources/views/finance/withdrawals.blade.php:293` labelled the reject-reason textarea at :297
 * the same way. `name=` is not an accessible-name source, so the two money modals announced
 * "edit, required, blank" three times over for three different fields named `amount`, and the Type
 * and Status filters both announced "combo box, All" — indistinguishable, because only the
 * selected option's text is read. For a pointer user a `<label>` with neither `for` nor nesting
 * has no activation behaviour at all, so clicking the word "Amount" focused nothing.
 *
 * WHY THE IDS ARE PREFIXED AND NOT THE FIELD NAMES. `name="amount"` occurs three times on this one
 * page (:475, :509, :543) and `name="description"` twice (:479, :513). The design system's own
 * precedent (`resources/views/setting/stock_setting.blade.php:28-29`) uses `id == name`, which here
 * would emit duplicate ids — so `addBalanceAmount`, `deductBalanceAmount`, `withdrawalAmount`.
 * On `finance/withdrawals` the reject modal is inside `@forelse`, so its id MUST interpolate
 * `$withdrawal->id` or a page with two actionable rows carries the same id twice.
 *
 * WHY THE SEVEN INJECTED TEMPLATES EACH CARRY THE SAME LITERAL ID. `#searchInputContainer`
 * (index.blade.php:233-235) holds nothing but a comment server-side; the control is written by
 * `innerHTML` from one of seven mutually exclusive branches (:602-:628), and :597 clears the
 * container first, so only ever one is in the DOM. Seven copies in the SOURCE is not a duplicate-id
 * bug. The alternative considered and rejected was one `querySelector(...).id = 'searchValue'`
 * statement after the branch chain: strictly smaller, but invisible to every test this repository
 * can run, whereas the count equality asserted below goes red the moment an eighth branch is added
 * without an id.
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT ASSERT. "Zero dangling `for` over the whole document", the
 * shape `tests/Feature/Security/UserInfoDisclosureTest.php:294-303` uses, is not available here:
 * measured on a baseline /finance render, the shared merchant layout already emits a dangling
 * `for="choice_attributes"` plus two `for="... ' + safe_i + '"` values built inside a JS template.
 * Those are outside this finding, so the resolution assertion is scoped to the labels this repair
 * owns. `index.blade.php:426`'s per-row `payment_status` select is also excluded on purpose: it has
 * no visible label at all and needs an `aria-label`, not a `for`/`id` pair, and it must not take a
 * static id because it renders inside `@forelse`. That is a separate tracked row.
 */
class FinanceControlLabellingTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The six seeded finance names (App\Models\Utility::addNewData). */
    private const FINANCE_PERMISSIONS = [
        'View Finance Dashboard',
        'Add Finance Balance',
        'Deduct Finance Balance',
        'Request Finance Withdrawal',
        'View Finance Withdrawal Requests',
        'Edit Finance Payment Status',
    ];

    /**
     * Every pair this repair owns on /finance: label `for` => control `id`.
     *
     * Keyed by id so a typo in either half cannot silently satisfy the other.
     */
    private const PAIRS = [
        'searchField',
        'financeFromDate',
        'financeToDate',
        'financeType',
        'financeStatus',
        'addBalanceAmount',
        'addBalanceDescription',
        'deductBalanceAmount',
        'deductBalanceDescription',
        'withdrawalAmount',
        'withdrawalPaymentMethod',
        'withdrawalPaymentDetails',
        'withdrawalNote',
    ];

    /** The `name=` attributes the controllers validate and the GET filter contract depends on. */
    private const NAMES = [
        'search_field', 'search_value', 'from_date', 'to_date', 'type', 'status',
        'amount', 'description', 'payment_method', 'payment_details', 'store_note',
    ];

    /** index.blade.php:577-580 resolves these four by getElementById. */
    private const SCRIPT_IDS = [
        'searchField', 'searchValueContainer', 'searchInputContainer', 'searchButtonContainer',
    ];

    private function merchantWithFinancePermissions(): User
    {
        [$merchant] = $this->makeTenant();

        foreach (self::FINANCE_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name], ['display_name' => $name]);
            $this->grantPermission(User::find($merchant->id), $name);
        }

        /* Several handlers read the platform admin through Cache::remember('admin_details'). */
        Cache::forget('admin_details');

        return User::find($merchant->id);
    }

    private function financeHtml(?User $merchant = null): string
    {
        $merchant = $merchant ?: $this->merchantWithFinancePermissions();

        $response = $this->actingAs($merchant)->get('/finance');

        $this->assertSame(200, $response->getStatusCode(),
            'the finance dashboard did not render for this fixture, so nothing below measures '
            . 'anything: ' . substr((string) $response->getContent(), 0, 400));

        return (string) $response->getContent();
    }

    /** @return array<string,int> id => how many times it occurs in the document */
    private function idCounts(string $html): array
    {
        preg_match_all('/\bid="([^"]*)"/', $html, $m);

        return array_count_values($m[1]);
    }

    // =============================================================================================
    //  THE PREMISE. Without the three modals in the HTML, 8 of the 13 pairs below are vacuous.
    // =============================================================================================

    /**
     * Laratrust has NO super-admin bypass and the view gates on `isAbleTo()` (:462, :493, :527),
     * while the route and controller gate on `Gate::allows('finance', ...)` which DOES have an
     * operator path. So the platform operator gets HTTP 200 on /finance and ZERO modals. A test
     * driving the operator would assert nothing at all; this drives a merchant holding the six
     * seeded names, and proves the modals arrived before reading anything inside them.
     */
    public function test_the_three_money_modals_really_are_in_the_html(): void
    {
        $html = $this->financeHtml();

        foreach (['addBalanceModal', 'deductBalanceModal', 'withdrawalModal'] as $modal) {
            $this->assertStringContainsString('id="' . $modal . '"', $html,
                "the {$modal} did not render for this fixture, so every label assertion inside "
                . 'it would pass without measuring anything');
        }
    }

    // =============================================================================================
    //  THE REPAIR ITSELF
    // =============================================================================================

    public function test_every_finance_dashboard_label_names_the_control_it_labels(): void
    {
        $html = $this->financeHtml();

        foreach (self::PAIRS as $id) {
            $this->assertMatchesRegularExpression(
                '/<label[^>]*\bfor="' . preg_quote($id, '/') . '"/',
                $html,
                "no <label for=\"{$id}\"> on /finance, so that control has no accessible name "
                . 'and clicking its label focuses nothing (WCAG 2.1 A 1.3.1 / 4.1.2)'
            );

            $this->assertMatchesRegularExpression(
                '/<(?:input|select|textarea)[^>]*\bid="' . preg_quote($id, '/') . '"/',
                $html,
                "the label for=\"{$id}\" resolves to no input, select or textarea on /finance"
            );
        }
    }

    public function test_the_reject_reason_textarea_is_labelled_once_per_row(): void
    {
        $merchant = $this->merchantWithFinancePermissions();
        [$first, $second] = $this->seedTwoActionableWithdrawals($merchant);

        $html = $this->withdrawalsHtml($merchant);

        foreach ([$first, $second] as $withdrawal) {
            $id = 'rejectAdminNote' . $withdrawal->id;

            $this->assertMatchesRegularExpression(
                '/<label[^>]*\bfor="' . preg_quote($id, '/') . '"/',
                $html,
                "the reject modal for withdrawal #{$withdrawal->id} has no <label for=\"{$id}\">"
            );
            $this->assertMatchesRegularExpression(
                '/<textarea[^>]*\bid="' . preg_quote($id, '/') . '"/',
                $html,
                "no textarea carries id=\"{$id}\", so that label dangles"
            );
        }
    }

    /**
     * UNIQUENESS UNDER THE LOOP. With ONE withdrawal row the duplicate-id bug is invisible — which
     * is exactly how a naive `id="admin_note"` would have passed for the wrong reason.
     */
    public function test_two_actionable_withdrawal_rows_produce_no_duplicate_id(): void
    {
        $merchant = $this->merchantWithFinancePermissions();
        $this->seedTwoActionableWithdrawals($merchant);

        $html = $this->withdrawalsHtml($merchant);

        $duplicates = array_keys(array_filter($this->idCounts($html), fn ($n) => $n > 1));

        $this->assertSame([], $duplicates,
            'these ids occur more than once on /finance/withdrawals, so a `for` pointing at one of '
            . 'them is ambiguous and the browser resolves only the first: '
            . implode(', ', $duplicates));

        /* And every `for` this view emits resolves to one of those ids. */
        preg_match_all('/<label[^>]*\bfor="([^"]*)"/', $html, $labels);
        $ids = array_keys($this->idCounts($html));

        foreach (array_unique($labels[1]) as $for) {
            if (! str_starts_with($for, 'rejectAdminNote')) {
                continue;   // labels from the shared merchant layout are outside this finding
            }
            $this->assertContains($for, $ids,
                "<label for=\"{$for}\"> on /finance/withdrawals names an element that does not exist");
        }
    }

    /**
     * `searchValue` IS EXCLUDED BY NAME, AND THE EXCLUSION IS MEASURED, NOT ASSUMED. The seven
     * injected templates are JavaScript STRING LITERALS sitting in the response body, so a regex
     * over the document counts seven `id="searchValue"` while the DOM only ever holds one (:599
     * clears the container before each write). Excluding it here is not a hole: the count equality
     * in test_every_injected_search_value_template_carries_the_id_its_label_points_at is the guard
     * that keeps all seven honest, and it is an EQUALITY, which a duplicate check could not be.
     */
    public function test_the_finance_dashboard_carries_no_duplicate_id(): void
    {
        $counts = $this->idCounts($this->financeHtml());
        unset($counts['searchValue']);

        $duplicates = array_keys(array_filter($counts, fn ($n) => $n > 1));

        $this->assertSame([], $duplicates,
            'these ids occur more than once on /finance: ' . implode(', ', $duplicates)
            . ' — three controls are named `amount` and two `description`, so an `id == name` '
            . 'repair produces exactly this');
    }

    /**
     * THE DRIFT GUARD the `querySelector` alternative could never have given.
     *
     * The Search Value control is written by `innerHTML` from seven mutually exclusive branches.
     * An eighth branch added without `id="searchValue"` breaks the equality here and nothing else
     * in this repository would see it.
     */
    public function test_every_injected_search_value_template_carries_the_id_its_label_points_at(): void
    {
        $html = $this->financeHtml();

        $names = substr_count($html, 'name="search_value"');
        $ids = substr_count($html, 'id="searchValue"');

        $this->assertSame(7, $names,
            "the Search Value branch chain emits {$names} controls, not the 7 this guard was "
            . 'measured against; re-census index.blade.php:602-628 before trusting the equality');

        $this->assertSame($names, $ids,
            "{$names} injected `search_value` controls but only {$ids} carry id=\"searchValue\", "
            . 'so at least one branch renders a control the label at :230 cannot name');
    }

    // =============================================================================================
    //  NEGATIVE CONTROLS
    // =============================================================================================

    /**
     * NO `name=` MAY CHANGE. A find/replace that renamed instead of adding would break every
     * finance form and the whole GET filter contract silently.
     */
    public function test_no_control_was_renamed(): void
    {
        $html = $this->financeHtml();

        foreach (self::NAMES as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html,
                "name=\"{$name}\" is gone from /finance — the controllers validate it by that key");
        }

        $merchant = $this->merchantWithFinancePermissions();
        $this->seedTwoActionableWithdrawals($merchant);
        $this->assertStringContainsString('name="admin_note"', $this->withdrawalsHtml($merchant),
            'name="admin_note" is gone from the reject modal — rejectWithdrawal() reads that key');
    }

    /**
     * THE FOUR SCRIPT IDS MUST SURVIVE VERBATIM. index.blade.php:577-580 resolves them by
     * getElementById; rename any and `searchField.addEventListener` throws on null, the :638
     * change dispatch never runs, and the Search Value field never appears at all — a dead feature
     * wearing the repair's clothes.
     */
    public function test_the_four_ids_the_inline_script_resolves_are_untouched(): void
    {
        $html = $this->financeHtml();

        foreach (self::SCRIPT_IDS as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html,
                "id=\"{$id}\" is gone, so the smart-search script throws on null and the Search "
                . 'Value field never renders');
            $this->assertStringContainsString("getElementById('" . $id . "')", $html,
                "the script no longer resolves {$id}, so this control is no longer a control");
        }
    }

    /**
     * PERMISSION GATES STILL DECIDE VISIBILITY. A merchant holding only 'View Finance Dashboard'
     * must receive neither the modals nor any of their ids.
     */
    public function test_a_merchant_without_the_money_permissions_receives_no_modal_ids(): void
    {
        [$merchant] = $this->makeTenant();
        Permission::firstOrCreate(['name' => 'View Finance Dashboard'], ['display_name' => 'View Finance Dashboard']);
        $this->grantPermission(User::find($merchant->id), 'View Finance Dashboard');
        Cache::forget('admin_details');

        $html = $this->financeHtml(User::find($merchant->id));

        foreach (['addBalanceModal', 'deductBalanceModal', 'withdrawalModal'] as $modal) {
            $this->assertStringNotContainsString('id="' . $modal . '"', $html,
                "{$modal} rendered for a merchant who may not use it");
        }

        foreach ([
            'addBalanceAmount', 'addBalanceDescription', 'deductBalanceAmount',
            'deductBalanceDescription', 'withdrawalAmount', 'withdrawalPaymentMethod',
            'withdrawalPaymentDetails', 'withdrawalNote',
        ] as $id) {
            $this->assertStringNotContainsString('"' . $id . '"', $html,
                "id/for \"{$id}\" leaked to a merchant who holds none of the money permissions");
        }

        /* The filter bar is not gated, so those five pairs must still be there. */
        $this->assertStringContainsString('for="financeFromDate"', $html);
    }

    /**
     * THE EXCLUSION, ASSERTED RATHER THAN DESCRIBED — and the separately-tracked half is now done.
     *
     * The per-row `payment_status` select renders inside `@forelse`, so a static `id` would
     * duplicate once per row and every `label[for]` pointing at it would resolve to the first
     * one. That is the real constraint and it still holds: **no id**.
     *
     * WHY THIS WAS REWRITTEN (P2.5-CLOSURE-AUDIT, 2026-11-11). The assertion used to be a single
     * regex over the view's source:
     *
     *     /<select\s+name="payment_status"\s+class="form-control form-control-sm"/
     *
     * which required `class` to follow `name` IMMEDIATELY. Its message said the control's
     * "missing accessible name needs an aria-label and is tracked separately" — so when that
     * aria-label was finally added, between those two attributes, this test failed. It failed on
     * the remedy its own message prescribed, because it was matching incidental attribute ORDER
     * rather than the two things it meant.
     *
     * It now asserts both halves separately, which is a tightening: the `id` ban is checked
     * directly rather than implied by a character position, and the accessible name it used to
     * merely describe is now REQUIRED. A future edit that removes the aria-label fails here
     * instead of quietly regressing a control that decides whether money is recorded as received.
     */
    public function test_the_per_row_payment_status_select_has_a_name_and_no_id(): void
    {
        $source = (string) file_get_contents(base_path('resources/views/finance/index.blade.php'));

        if (! preg_match('/<select\s+name="payment_status"(?P<attrs>[^>]*)>/', $source, $m)) {
            $this->fail('the per-row payment_status select is no longer in finance/index.blade.php '
                . 'in a recognisable form; this test can no longer speak for it');
        }

        $attrs = $m['attrs'];

        /* NO ID. The constraint this test was written for: inside `@forelse` a static id is
           duplicated once per rendered row, and a `label[for]` would address only the first. */
        $this->assertDoesNotMatchRegularExpression(
            '/\sid\s*=/',
            $attrs,
            'the per-row payment_status select has been given an id. It renders inside @forelse, '
            . 'so one static id duplicates across every row and any label[for] pointing at it '
            . 'resolves to the first row only. Name it with aria-label, which needs no id.'
        );

        /* AND IT MUST STILL HAVE A NAME. Before this was added the accessible name was the empty
           string: a combobox announced as "combobox", once per row, on the control that records
           whether a customer's money was received. */
        $this->assertStringContainsString(
            'aria-label=',
            $attrs,
            'the per-row payment_status select has lost its accessible name. Without it the '
            . 'control is announced as "combobox" and nothing else, identically on every row, and '
            . 'it is the control that records whether money was received.'
        );

        /* The name identifies the ORDER, because a screen-reader user arriving by column has no
           row context. It must NOT carry the current status: the selected option already
           announces that, and a name repeating it would contradict itself on change. */
        $this->assertStringContainsString(
            'Payment status for order',
            $attrs,
            'the accessible name no longer identifies which order the select governs'
        );

        foreach (['Received', 'Not Received'] as $state) {
            $this->assertStringNotContainsString(
                $state . "')",
                $attrs,
                'the accessible name carries the payment status, which the selected option '
                . 'already announces and which would contradict itself the moment it changes'
            );
        }
    }

    /**
     * NO STYLESHEET IS INVOLVED. `public/css/custom.css` serves LTR and `rtl-custom.css` serves
     * Arabic and they are mirrored duplicates, so a CSS change in one reaches half the merchants.
     * `for`/`id` are unstyleable; this asserts neither sheet acquired a `label[for` selector.
     */
    public function test_neither_stylesheet_was_touched_for_this(): void
    {
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css', 'public/css/store-syria-ds.css'] as $sheet) {
            $this->assertStringNotContainsString('label[for', (string) file_get_contents(base_path($sheet)),
                "{$sheet} now carries a label[for selector; this repair is attribute-only and a "
                . 'CSS change in only one of the mirrored sheets reaches half the merchants');
        }
    }

    /**
     * NO VISUAL CHANGE. Bootstrap's `form-label` / `form-control` / `form-select` stay; converting
     * to `ss-label` / `ss-input` would restyle under a 25-sheet cascade — a Phase 2.5 visual
     * change smuggled in under an accessibility finding.
     */
    public function test_the_bootstrap_classes_were_not_converted_to_design_system_classes(): void
    {
        foreach (['resources/views/finance/index.blade.php', 'resources/views/finance/withdrawals.blade.php'] as $view) {
            $source = (string) file_get_contents(base_path($view));

            $this->assertStringNotContainsString('class="ss-label"', $source,
                "{$view} converted a label to ss-label; this finding is programmatic association "
                . 'only, not a restyle');
            $this->assertStringContainsString('class="form-label"', $source,
                "{$view} no longer uses Bootstrap's form-label");
        }
    }

    // =============================================================================================

    /** @return array{0: StoreWithdrawal, 1: StoreWithdrawal} */
    private function seedTwoActionableWithdrawals(User $merchant): array
    {
        $storeId = $merchant->current_store;

        /* `withdrawals()` eager-loads `wallet`, and fk_withdrawal_wallet requires the row. */
        $wallet = StoreWallet::firstOrCreate(
            ['store_id' => $storeId],
            [
                'currency_code' => 'USD',
                'available_balance' => 1000,
                'pending_balance' => 0,
                'withdrawal_pending_balance' => 0,
                'total_income' => 0,
                'total_withdrawn' => 0,
            ]
        );

        $rows = [];
        foreach (['pending', 'approved'] as $status) {
            $rows[] = StoreWithdrawal::create([
                'store_id' => $storeId,
                'wallet_id' => $wallet->id,
                'amount' => 125.50,
                'status' => $status,
                'payment_method' => 'bank',
                'payment_details' => 'IBAN SY00 0000',
                'requested_by' => $merchant->id,
                'requested_at' => now(),
            ]);
        }

        return $rows;
    }

    private function withdrawalsHtml(User $merchant): string
    {
        $response = $this->actingAs($merchant)->get('/finance/withdrawals');

        $this->assertSame(200, $response->getStatusCode(),
            'the withdrawals queue did not render: ' . substr((string) $response->getContent(), 0, 400));

        return (string) $response->getContent();
    }
}
