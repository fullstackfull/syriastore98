<?php

namespace Tests\Feature\Merchant;

use App\Models\OrderRefund;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5 refund-status-badges-below-aa -- the refund list's status column, driven for real.
 * =============================================================================================
 *
 * WHAT WAS WRONG, MEASURED WITH THE WCAG 1.4.3 FORMULA (the numbers below were computed in this
 * repository by compositing the shipped rules over the DataTables card surface
 * `--ss-dt-card-bg`, #ffffff light / #292a33 dark, public/css/store-syria-ds.css:3316-3318).
 * `OrderRefundDataTable` emitted `badge badge-80 rounded p-2 f-w-600 bg-light-*`, so the winning
 * declarations were `.badge.bg-light-{success,info,warning,danger}` in
 * public/assets/css/style.css:19579/19585/19591/19597 -- byte-identical in style-dark.css, so
 * BOTH themes read the same:
 *
 *     success  #0bb755 on rgba(11,183,85,.22)  2.13:1 light   3.80:1 dark
 *     info     #3ec9d6 on #d8f4f7              1.73:1 both
 *     warning  #ffa21d on #ffecd2              1.74:1 both
 *     danger   #ff3a6e on #ffd8e2              2.66:1 both
 *
 * Eight readings, all eight below the 4.5:1 minimum. The design system's own badge tones are
 * 7.10-8.83:1 light and 8.25-9.81:1 dark, from tokens (store-syria-ds.css:287-297 and :423-427),
 * which is why the repair converts the CALL SITE to `ss-badge` and touches no stylesheet.
 *
 * WHY THE ASSERTIONS NAME CLASSES AND NEVER A HEX. The dark values come from
 * `[data-ss-theme="dark"]` and the `prefers-color-scheme` block, not from PHP. A test that pinned
 * a hex would pin one theme and would pass while the other regressed.
 *
 * AND IT IS A SEARCH TEST, NOT ONLY A CONTRAST ONE. `order_refunds.refund_status` is a string
 * holding the English literal (migration 2024_02_07_071800:17) and it is exactly what the column
 * used to display, so yajra's global search compiled `refund_status like %kw%` and typing what
 * was on screen worked. Translating the display without a twin `filterColumn` would hand an
 * Arabic merchant a label they cannot search for. Both directions are asserted here.
 */
class RefundStatusBadgeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Both headers together, or yajra's render() silently serves the HTML leg. */
    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    /** The contrast ratios computed above, kept as data so the docblock cannot drift. */
    private const SHIPPED_RATIOS = [
        'success light' => 2.13, 'success dark' => 3.80,
        'info'          => 1.73, 'warning'      => 1.74,
        'danger'        => 2.66,
    ];

    private const DS_RATIOS = [
        'success light' => 7.10, 'warning light' => 8.83, 'danger light' => 8.09,
        'info light'    => 8.28, 'neutral light' => 8.12,
        'success dark'  => 8.79, 'warning dark'  => 9.81, 'danger dark'  => 9.06,
        'info dark'     => 8.25, 'neutral dark'  => 8.98,
    ];

    private function dataTableQuery(array $columns, string $search = ''): array
    {
        $query = ['draw' => 1, 'start' => 0, 'length' => 10];

        foreach (array_values($columns) as $i => $name) {
            $query["columns[{$i}][data]"] = $name;
            $query["columns[{$i}][name]"] = $name;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'asc';
        $query['search[value]'] = $search;
        $query['search[regex]'] = 'false';

        return $query;
    }

    /**
     * `order_id` is left NULL on purpose. It is nullable in the migration and the table's own
     * `order_id` column already handles a missing relation (`$refund_request->order ? ... :
     * '#N/A'`), so no Order row is needed -- which keeps this file off `makeOrder()`'s skip for
     * the status-column tests. The one test that posts a state transition makes a real order.
     */
    private function makeRefund(int $storeId, string $status, ?int $orderId = null): OrderRefund
    {
        return OrderRefund::forceCreate([
            'order_id' => $orderId,
            'refund_status' => $status,
            'refund_reason' => 'test',
            'product_refund_id' => json_encode([
                ['product_refund_id' => 1, 'quantity' => 1, 'return_price' => 10],
            ]),
            'product_refund_price' => 10,
            'refund_amount' => 0,
            'store_id' => $storeId,
        ]);
    }

    /**
     * `forgetInstance('datatables.request')` IS LOAD-BEARING AND COST THIS FILE A FALSE READING.
     *
     * `DataTablesServiceProvider` registers `datatables.request` as a SINGLETON whose constructor
     * snapshots `app('request')` once (`Utilities\Request::__construct`). A feature test does not
     * refresh the application between `$this->getJson()` calls inside one test method, so the
     * SECOND and later drives were served the FIRST drive's `search[value]` -- which was empty.
     * Both search tests below passed with the search box doing nothing at all, and kept passing
     * with the whole filterColumn deleted. Measured: with the stale singleton, `search[value]`
     * of `ZZZNOMATCH` still returned the row. Forgetting the instance makes it reconstruct during
     * the new request, so it reads the new input. This is the memoised-container-binding
     * pollution `root-cause-tracing.md` names, and it is a TEST artifact only -- production
     * builds a fresh application per request.
     */
    private function driveTable(\App\Models\User $merchant, string $search = '')
    {
        $this->app->forgetInstance('datatables.request');

        return $this->actingAs($merchant->fresh())
            ->withHeaders(self::AJAX)
            ->getJson('/refund-request?' . http_build_query(
                $this->dataTableQuery(['order_id', 'created_at', 'refund_status'], $search)
            ));
    }

    private function permittedMerchant(?string $language = null): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order Refund Request');

        if ($language !== null) {
            $merchant->language = $language;
            $merchant->save();
        }

        return [$merchant->fresh(), $store];
    }

    // =========================================================================================
    // (a) THE REPAIRED BEHAVIOUR -- the DS badge is what reaches the merchant
    // =========================================================================================

    public function test_a_refunded_row_renders_the_accessible_design_system_badge(): void
    {
        [$merchant, $store] = $this->permittedMerchant();
        $this->makeRefund($store->id, 'Refunded');

        $response = $this->driveTable($merchant);

        $this->assertNotSame(404, $response->getStatusCode(), 'the refund-request route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the refund list must not 500');
        $response->assertOk();

        $payload = $response->json();

        // PREMISE before any claim about the markup: yajra served the AJAX leg and there is a row
        // in it. An empty table satisfies every "does not contain" assertion for free.
        $this->assertArrayHasKey('draw', $payload, 'yajra served the HTML leg, not the AJAX leg');
        $this->assertSame(1, (int) $payload['recordsTotal'], 'premise: the caller sees their refund');

        $body = json_encode($payload['data']);

        $this->assertStringContainsString('ss-badge ss-badge--success', $body,
            'the settled refund must wear the design system success tone (measured '
            . self::DS_RATIOS['success light'] . ':1 light / '
            . self::DS_RATIOS['success dark'] . ':1 dark)');

        // NEGATIVE CONTROL: the failing vendored classes are gone, not merely joined.
        $this->assertStringNotContainsString('bg-light-', $body,
            'the sub-AA vendored soft badge classes must no longer be emitted ('
            . self::SHIPPED_RATIOS['info'] . ':1 to '
            . self::SHIPPED_RATIOS['danger'] . ':1, all below 4.5:1)');
        $this->assertStringNotContainsString('badge-80', $body);

        // WCAG 1.4.1: colour is not the only channel. DsBadge's aria-hidden dot is the second.
        // Read off the CELL, not off `json_encode($payload['data'])` -- the encoder escapes the
        // attribute's quotes, so `aria-hidden="true"` is absent from the encoded string whether
        // the markup carries it or not. A first draft of this assertion failed for exactly that.
        $cell = (string) ($payload['data'][0]['refund_status'] ?? '');
        $this->assertStringContainsString('ss-badge__dot', $cell);
        $this->assertStringContainsString('aria-hidden="true"', $cell);
    }

    /**
     * EVERY STATE, not just the one. `$badge_class = 'bg-light-success'` was the DEFAULT, so an
     * out-of-vocabulary value claimed the settled hue; the repair's map is what closes that, and
     * this is where the declared hue reassignment is pinned.
     */
    public function test_each_refund_state_gets_its_declared_tone(): void
    {
        $expected = [
            'Processing' => 'ss-badge--warning',
            'Accept'     => 'ss-badge--info',
            'Refunded'   => 'ss-badge--success',
            'Cancel'     => 'ss-badge--danger',
        ];

        foreach ($expected as $status => $toneClass) {
            [$merchant, $store] = $this->permittedMerchant();
            $this->makeRefund($store->id, $status);

            $payload = $this->driveTable($merchant)->json();

            $this->assertArrayHasKey('draw', $payload, "premise: AJAX leg served for {$status}");
            $this->assertSame(1, (int) $payload['recordsTotal'], "premise: the {$status} row is listed");

            $body = json_encode($payload['data']);

            $this->assertStringContainsString($toneClass, $body, "{$status} must render {$toneClass}");
            $this->assertStringNotContainsString('bg-light-', $body, "{$status} must not render a sub-AA class");
        }
    }

    // =========================================================================================
    // (b) THE ENGLISH LITERAL MATCHES THE SEARCH BOX, IN BOTH LOCALES
    // =========================================================================================

    /**
     * MEASURED CORRECTION TO THE FINDING. The brief argued that translating the column would
     * BREAK a search that worked, "where today -- with the English literal on screen -- typing it
     * works". It did not work. `refund_status` is declared with `addColumn()`, which blacklists
     * it, and `globalSearch()` rejects a blacklisted column unless it `hasFilterColumn()`
     * (QueryDataTable::globalSearch). With no filterColumn, the only limb yajra compiled was
     * `order_id`'s `whereHas`. Driven against the shipped file, `search[value]=Processing`
     * returned recordsFiltered=0 -- the row was NOT found. So the twin filterColumn does not
     * preserve a working search; it makes this column searchable for the first time, in English
     * AND in Arabic. That is a better outcome than the brief claimed, and it is why the raw
     * `like` limb still has to come first.
     */
    public function test_the_english_literal_matches_the_search_box_in_both_locales(): void
    {
        foreach ([null, 'ar'] as $language) {
            [$merchant, $store] = $this->permittedMerchant($language);
            $this->makeRefund($store->id, 'Processing');

            // Premise: without a search term the row is there, so a zero below is the filter.
            $this->assertSame(1, (int) $this->driveTable($merchant)->json()['recordsTotal'],
                'premise: the unfiltered table lists the row');

            $payload = $this->driveTable($merchant, 'Processing')->json();

            $this->assertArrayHasKey('draw', $payload, 'premise: the AJAX leg was served');
            $this->assertSame(1, (int) $payload['recordsFiltered'],
                'searching the English DB literal must work (language: '
                . ($language ?? 'default') . ') -- an English merchant, a saved search and a '
                . 'partial word all depend on the raw `like` limb coming first');

            // NEGATIVE CONTROL, AND THE ONE THAT MAKES THE TWO ABOVE MEAN ANYTHING. A stale
            // `datatables.request` singleton served the row for every keyword, so an assertion
            // that a term MATCHES cannot distinguish a working filter from no filter at all.
            // A term that must NOT match can.
            $miss = $this->driveTable($merchant, 'ZZZ-NO-SUCH-STATUS')->json();
            $this->assertSame(0, (int) $miss['recordsFiltered'],
                'the search box must really filter, or every match assertion here is vacuous');
            $this->assertSame(1, (int) $miss['recordsTotal'],
                'and the row is filtered out, not absent');
        }
    }

    // =========================================================================================
    // (c) THE REPAIRED BEHAVIOUR -- an Arabic merchant can search what is on their screen
    // =========================================================================================

    public function test_an_arabic_merchant_can_search_the_label_they_can_see(): void
    {
        [$merchant, $store] = $this->permittedMerchant('ar');
        $this->makeRefund($store->id, 'Processing');

        $arabic = 'المعالجة'; // resources/lang/ar.json, key "Processing"

        $unfiltered = $this->driveTable($merchant)->json();
        $this->assertSame(1, (int) $unfiltered['recordsTotal'], 'premise: the row is listed');
        $this->assertStringContainsString($arabic, json_encode($unfiltered['data'], JSON_UNESCAPED_UNICODE),
            'premise: the column really does display the Arabic label, so searching it is the right test');

        $payload = $this->driveTable($merchant, $arabic)->json();

        $this->assertArrayHasKey('draw', $payload, 'premise: the AJAX leg was served');
        $this->assertSame(1, (int) $payload['recordsFiltered'],
            'the label on screen must be searchable: the twin filterColumn is what maps the '
            . 'Arabic label back onto the English DB literal (measured SQL: `refund_status like '
            . '%keyword% or refund_status in (?)` with the binding "Processing")');

        // NEGATIVE CONTROL: an Arabic string that is not a label must not match, or the
        // assertion above would be satisfied by a filter that matches everything.
        $this->assertSame(0,
            (int) $this->driveTable($merchant, 'لاشيء')->json()['recordsFiltered'],
            'an Arabic term that is not a status label must not match');
    }

    // =========================================================================================
    // (d) ESCAPING -- the fallback echoes the DB value into a rawColumns cell
    // =========================================================================================

    public function test_an_out_of_vocabulary_status_is_escaped_and_neutral_toned(): void
    {
        [$merchant, $store] = $this->permittedMerchant();
        $this->makeRefund($store->id, '<script>alert(1)</script>');

        $payload = $this->driveTable($merchant)->json();

        $this->assertArrayHasKey('draw', $payload, 'premise: the AJAX leg was served');
        $this->assertSame(1, (int) $payload['recordsTotal'], 'premise: the row is listed');

        $cell = (string) ($payload['data'][0]['refund_status'] ?? '');

        $this->assertStringContainsString('&lt;script&gt;', $cell,
            'refund_status is in rawColumns, so nothing downstream escapes it');
        $this->assertStringNotContainsString('<script>', $cell);

        // Neutral, not the old success default: an unknown value must not claim "settled".
        $this->assertStringContainsString('ss-badge', $cell);
        $this->assertStringNotContainsString('ss-badge--', $cell,
            'an unknown status must carry no tone class at all, least of all success');
    }

    /**
     * refund_status MUST STAY IN rawColumns. If it were dropped the merchant would read a literal
     * `<span class="ss-badge ...` instead of a badge, and the escaping test above would still
     * pass -- so this is asserted on the payload, not on the source.
     */
    public function test_the_badge_markup_reaches_the_merchant_as_markup(): void
    {
        [$merchant, $store] = $this->permittedMerchant();
        $this->makeRefund($store->id, 'Refunded');

        $cell = (string) ($this->driveTable($merchant)->json()['data'][0]['refund_status'] ?? '');

        $this->assertStringStartsWith('<span class="ss-badge', $cell,
            'refund_status must remain in rawColumns or yajra escapes the badge markup');
    }

    // =========================================================================================
    // (e) ISOLATION -- and it pins line 74, not the global scope
    // =========================================================================================

    /**
     * `App\Models\OrderRefund` IS in config/tenancy.php's scoped_models (:216), so the payload
     * assertion alone would pass on the global scope and would keep passing if the DataTable's
     * own predicate were deleted. The source assertion is what pins the predicate.
     */
    public function test_the_refund_table_does_not_serve_another_tenants_refunds(): void
    {
        [$merchantA, $storeA] = $this->permittedMerchant();
        [, $storeB] = $this->makeTenant();

        $this->makeRefund($storeA->id, 'Refunded');
        $foreign = $this->makeRefund($storeB->id, 'Cancel');

        // PREMISE through the raw table: OrderRefund is Cachable and scoped, so an Eloquent read
        // answers against whichever tenant resolved rather than against the table.
        $this->assertSame(2, \DB::table('order_refunds')->count(),
            'premise: both rows are in the table, so exclusion is the predicate doing work');

        $payload = $this->driveTable($merchantA)->json();

        $this->assertArrayHasKey('draw', $payload, 'premise: the AJAX leg was served');
        $this->assertSame(1, (int) $payload['recordsTotal'], "store A sees only its own refund");

        $body = json_encode($payload['data']);
        $this->assertStringContainsString('ss-badge--success', $body);
        $this->assertStringNotContainsString('ss-badge--danger', $body,
            "store B's Cancel row must not appear in store A's payload");

        // Excluded, not absent -- read back without the scopes, never with a plain find().
        $this->assertNotNull(
            OrderRefund::withoutGlobalScopes()->find($foreign->id),
            'premise: the foreign row still exists'
        );

        // AND THE DATATABLE'S OWN PREDICATE IS STILL THERE. This is the assertion the payload
        // cannot make, because the global scope would satisfy it on its own.
        //
        // READ OFF THE `query()` METHOD BODY, NOT THE WHOLE FILE, AND WITH COMMENTS STRIPPED.
        // The first version grepped the raw file and PASSED with the predicate deleted, because
        // the filterColumn's own docblock quotes `where('store_id', getCurrentStore())` while
        // explaining why isolation is unaffected. A comment satisfied an isolation assertion --
        // measured, by deleting the predicate and watching the test stay green.
        $source = file_get_contents(base_path('app/DataTables/OrderRefundDataTable.php'));

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertMatchesRegularExpression(
            '/function\s+query\s*\(.*?getCurrentStore\(\).*?\n    \}/s',
            $code,
            'OrderRefundDataTable::query() must keep its own tenant predicate -- OrderRefund is '
            . 'in scoped_models (config/tenancy.php:216), so the payload assertion above would '
            . 'pass on the global scope alone and cannot pin this line'
        );
        $this->assertStringContainsString("where('store_id', getCurrentStore())", $code,
            'and it is the store_id predicate specifically');
    }

    // =========================================================================================
    // (f) NEGATIVE CONTROL -- the detail page's AJAX contract keeps the RAW ENGLISH literal
    // =========================================================================================

    /**
     * resources/views/order/refund_details.blade.php compares `data.status === 'Accept'` and
     * `initialStatus === 'Processing'`. Translating refund_status anywhere but the presentation
     * point would leave every one of those branches false and the detail page with no buttons.
     */
    public function test_the_update_status_endpoint_still_returns_the_untranslated_literal(): void
    {
        [$merchant, $store] = $this->permittedMerchant('ar');

        // Skips if orders.currency_code / currency_symbol are undeclared.
        $order = $this->makeOrder($store);
        $refund = $this->makeRefund($store->id, 'Processing', $order->id);

        $response = $this->actingAs($merchant->fresh())
            ->postJson('/update-refund-status', ['refund_id' => $refund->id]);

        $response->assertOk();

        $this->assertSame('Accept', $response->json('status'),
            'the detail page compares this against the English literal; a translation here '
            . 'would silently disable its Accept, Cancel, Manage Stock and Refund Amount buttons');

        $this->assertSame('Accept',
            OrderRefund::withoutGlobalScopes()->find($refund->id)->refund_status,
            'and the stored value stays the English literal the state machine keys on');
    }

    // =========================================================================================
    // (g) NEGATIVE CONTROL -- the permission gate is untouched
    // =========================================================================================

    public function test_a_merchant_without_the_refund_permission_is_still_refused(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->makeRefund($store->id, 'Refunded');

        $response = $this->actingAs($staff->fresh())
            ->withHeaders(self::AJAX)
            ->get('/refund-request?' . http_build_query(
                $this->dataTableQuery(['order_id', 'created_at', 'refund_status'])
            ));

        // `get`, not `getJson`: OrderRefundController::index() answers redirect()->back(), so the
        // refusal is an HTML 302 and ->json() on it would fail the test for the wrong reason.
        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the refusal must not be a crash');
        $this->assertSame(302, $response->getStatusCode(), 'the refusal is a redirect back');

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('"recordsTotal"', $body);
        $this->assertStringNotContainsString('ss-badge', $body);
    }

    // =========================================================================================
    // NO CSS OVER-REACH -- the tempting shortcut is asserted absent
    // =========================================================================================

    /**
     * Overriding `.badge.bg-light-*` in a stylesheet would recolour 8 other host files and 33
     * vendored ones, all unmeasured, and in public/css/custom.css it would reach LTR merchants
     * only unless mirrored into rtl-custom.css. The repair is at the call site, so BOTH
     * stylesheets must stay free of such a rule.
     */
    public function test_neither_directional_stylesheet_overrides_the_vendored_soft_badges(): void
    {
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $css = file_get_contents(base_path($sheet));

            $this->assertIsString($css);
            $this->assertStringNotContainsString('.badge.bg-light-', $css,
                "{$sheet} must not override the vendored soft badges -- the repair is at the call site");

            // Positive control that this file is the one we think it is: badge-80 is still
            // declared here (it simply no longer applies to the refund column).
            $this->assertStringContainsString('badge-80', $css,
                "premise: {$sheet} is the stylesheet that declares badge-80");
        }
    }

    // =========================================================================================
    // THE MEASUREMENT ITSELF, so the claim "this fixes contrast" is a number and not a hope
    // =========================================================================================

    public function test_the_shipped_classes_fail_aa_and_the_design_system_tones_pass(): void
    {
        // WCAG 2.x relative luminance and contrast ratio, implemented here rather than trusted.
        $luminance = function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channel = function (int $c): float {
                $c /= 255;
                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };
            return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
                 + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
                 + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
        };
        $ratio = function (string $a, string $b) use ($luminance): float {
            $la = $luminance($a);
            $lb = $luminance($b);
            return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
        };

        // What shipped. #c9efda / #22493a are rgba(11,183,85,.22) composited over the card
        // surface (#ffffff light, #292a33 dark); the other three fills are opaque already.
        $shipped = [
            'success light' => ['#0bb755', '#c9efda'],
            'success dark'  => ['#0bb755', '#22493a'],
            'info'          => ['#3ec9d6', '#d8f4f7'],
            'warning'       => ['#ffa21d', '#ffecd2'],
            'danger'        => ['#ff3a6e', '#ffd8e2'],
        ];

        foreach ($shipped as $name => [$fg, $bg]) {
            $measured = $ratio($fg, $bg);
            $this->assertEqualsWithDelta(self::SHIPPED_RATIOS[$name], $measured, 0.01,
                "the recorded ratio for the shipped {$name} badge must be the computed one");
            $this->assertLessThan(4.5, $measured,
                "premise of this whole finding: the shipped {$name} badge is below AA");
        }

        // What replaces it, read from the design system's own tokens.
        $ds = [
            'success light' => ['#165e34', '#eaf7ef'], 'warning light' => ['#5d3f04', '#fdf4e3'],
            'danger light'  => ['#8c1d17', '#fdeeee'], 'info light'    => ['#3f3a8f', '#f0eefb'],
            'neutral light' => ['#43474d', '#eeeff1'],
            'success dark'  => ['#7fd6a0', '#102a1b'], 'warning dark'  => ['#f0c674', '#2c2109'],
            'danger dark'   => ['#f3a9a4', '#2c1413'], 'info dark'     => ['#b6b1ef', '#1d1b3d'],
            'neutral dark'  => ['#c3c6cc', '#21252c'],
        ];

        foreach ($ds as $name => [$fg, $bg]) {
            $measured = $ratio($fg, $bg);
            $this->assertEqualsWithDelta(self::DS_RATIOS[$name], $measured, 0.01,
                "the recorded ratio for ss-badge--{$name} must be the computed one");
            $this->assertGreaterThanOrEqual(4.5, $measured,
                "ss-badge--{$name} must clear AA");
        }

        // AND THE TOKENS THOSE HEXES CAME FROM ARE STILL THE ONES THE PAGE USES. The hexes above
        // are only evidence; the badge takes its colours from these declarations, so if one is
        // renamed the numbers stop describing the screen and this fails rather than lying.
        $ds_css = file_get_contents(base_path('public/css/store-syria-ds.css'));
        foreach (['--ss-success-bg', '--ss-success-fg', '--ss-warning-fg', '--ss-danger-fg',
                  '--ss-info-fg', '--ss-neutral-fg'] as $token) {
            $this->assertStringContainsString($token, $ds_css, "the badge reads {$token}");
        }
        $this->assertStringContainsString('.ss-badge--success', $ds_css);
        $this->assertStringContainsString('[data-ss-theme="dark"]', $ds_css,
            'the dark readings come from the theme block, not from PHP');
    }

    /**
     * A premise for every store-scoped assertion above: the fixtures really are distinct tenants.
     */
    public function test_the_fixtures_are_two_distinct_tenants(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->assertNotSame($storeA->id, $storeB->id);
        $this->assertSame(2, Store::withoutGlobalScopes()->whereIn('id', [$storeA->id, $storeB->id])->count());
    }
}
