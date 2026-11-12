<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * "Orders by status" — nine chips, nine lists.
 * =================================================================================================
 *
 * THE DEFECT. `resources/views/dashboard/overview.blade.php` built ONE href —
 * `$ssStatusHref = $ssMayOrders ? route('order.index') : null` — and handed the same value to all
 * seven fulfilment chips and both non-fulfilment chips, under a card whose own note promises
 * "All time. Select a status to open that list." Nine different counts, one destination.
 *
 * AND THE DESTINATION WAS WORSE THAN THE FULL LIST. `route('order.index')` emits an EMPTY query
 * string; `TableFilterMemory::shouldRestore()` is exactly `$request->query() === []` and
 * `OrderController::index()` redirects on it to the merchant's remembered filter set. So a
 * merchant who last filtered to `ss_payment=Unpaid` and then clicked "Cancel Order (12)" landed
 * on a list related to neither the status they clicked nor the full list — the one navigation
 * outcome a merchant cannot explain to themselves.
 *
 * WHY THE PAIRING IS ASSERTED AND NOT THE PRESENCE OF A PARAMETER. A test that asserts only
 * "every chip has an `ss_status`" passes with all nine pointing at `0`. Every test below that
 * touches a link follows that link's OWN url into the DataTables leg and asserts the list holds
 * exactly the order the chip counted and none of the other eight.
 *
 * WHY A PRECISE LINK IS SAFE HERE WHEN THE STOCK LINK IS NOT. `OrderAttentionCentreTest` pins the
 * out-of-stock link as deliberately UNFILTERED, because the dashboard's stock count includes
 * untracked products `ss_stock=out` cannot match: a precise link to a list that does not contain
 * the counted rows is worse than a vague one. The status counts have no such mismatch —
 * `HomeController::getOrderCounts()` is `where('store_id', getCurrentStore())` plus
 * `where('delivered_status', ORDER_STATUS_*)`, and `OrderDataTable` applies
 * `where('store_id', $storeId)` plus `where('delivered_status', (int) $status)` with no groupBy.
 * Same store source, same column, same constants, so the chip's figure and its list are the same
 * rows. That agreement is what `test_each_status_chip_lands_on_exactly_the_orders_it_counted`
 * measures, rather than assuming.
 *
 * WHAT THE REPAIR MUST NOT WIDEN OR LOSE, each with its own test below:
 *   - the permission gate (`$ssMayOrders`): an unpermitted principal must still get
 *     `<span class="ss-chip ss-chip--static">`, never a link and never a dead `<button>`;
 *   - no `store_id` in any href — `Order` is not in `config/tenancy.php`'s `scoped_models`, so
 *     `OrderDataTable::query()`'s predicate from the PRINCIPAL is the only isolation there is,
 *     and a caller-supplied `store_id` is this codebase's most repeated defect shape;
 *   - no `ss_apply` — `TableFilterMemory::remember()` writes only on `ss_apply`, so one dashboard
 *     click must not silently become the merchant's permanent default for the order screen;
 *   - the "Other" chip stays unlinked: `applyFilters()` adds NO predicate for an unrecognised
 *     value, so a link there lands on the unfiltered list wearing false precision;
 *   - the non-fulfilment region keeps rendering. Its guard used to read
 *     `$ssNotFulfilment[0]['count'] > 0 || $ssNotFulfilment[1]['count'] > 0`, which is positional:
 *     reshaping those rows is one keystroke away from making the whole "Recorded in the same
 *     field, but not fulfilment states" region vanish for every merchant, silently, and only on
 *     stores that actually have such orders.
 */
class DashboardStatusChipLinkTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    /**
     * The chip LABEL a status is given on the dashboard, from `$ssOrderLabels`. Written out here
     * rather than read from the blade, so a repair that silently renamed a chip (and so broke the
     * Arabic coverage the labels already have) would fail rather than be followed.
     *
     * @var array<string, int>
     */
    private const CHIP_LABEL_STATUS = [
        'Pending' => HomeController::ORDER_STATUS_PENDING,
        'Order Delivered' => HomeController::ORDER_STATUS_DELIVERED,
        'Cancel Order' => HomeController::ORDER_STATUS_CANCEL,
        'Order Return' => HomeController::ORDER_STATUS_RETURN,
        'Confirmed Order' => HomeController::ORDER_STATUS_CONFIRMED,
        'Picked Up' => HomeController::ORDER_STATUS_PICKED_UP,
        'Order Shipped' => HomeController::ORDER_STATUS_SHIPPED,
        'Partially Paid' => HomeController::ORDER_STATUS_PARTIALLY_PAID,
        'Pre Order' => HomeController::ORDER_STATUS_PRE_ORDER,
    ];

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');

        $this->merchant = User::find($this->merchant->id);
    }

    // =============================================================================================
    //  Harness
    // =============================================================================================

    private function dashboard(?User $as = null): string
    {
        $response = $this->actingAs($as ?? $this->merchant)->get('/dashboard');

        $this->assertSame(200, $response->getStatusCode(),
            'premise: the merchant dashboard must render before anything on it can be asserted');

        return (string) $response->getContent();
    }

    /** Only the markup INSIDE the `.ss-chips` containers — the chips card and nothing else. */
    private function chipsMarkup(string $body): string
    {
        preg_match_all('#<div class="ss-chips">(.*?)</div>#s', $body, $hits);

        $this->assertNotEmpty($hits[1],
            'premise: the dashboard rendered no .ss-chips region, so no chip claim below is '
            . 'measuring anything');

        return implode("\n", $hits[1]);
    }

    /**
     * Every chip rendered as a LINK, as `['label' => ..., 'count' => ..., 'href' => ...]`.
     *
     * @return list<array{label: string, count: string, href: string}>
     */
    private function chipLinks(string $chips): array
    {
        preg_match_all('#<a\s+href="([^"]*)"[^>]*>(.*?)</a>#s', $chips, $hits, PREG_SET_ORDER);

        $out = [];

        foreach ($hits as $hit) {
            $out[] = ['href' => html_entity_decode($hit[1], ENT_QUOTES)] + $this->chipText($hit[2]);
        }

        return $out;
    }

    /**
     * Every chip rendered as a STATIC span (no link, no toggle).
     *
     * @return list<array{label: string, count: string}>
     */
    private function chipStatics(string $chips): array
    {
        preg_match_all('#<span class="ss-chip ss-chip--static"[^>]*>(.*?)</span>\s*</span>#s',
            $chips, $hits, PREG_SET_ORDER);

        $out = [];

        foreach ($hits as $hit) {
            $out[] = $this->chipText($hit[1]);
        }

        return $out;
    }

    /** `Cancel Order <span class="ss-num">(12)</span>` -> label "Cancel Order", count "12". */
    private function chipText(string $inner): array
    {
        $text = trim((string) preg_replace('#\s+#', ' ', strip_tags($inner)));

        if (preg_match('#^(.*?)\s*\(([^()]*)\)$#', $text, $m) === 1) {
            return ['label' => trim($m[1]), 'count' => trim($m[2])];
        }

        return ['label' => $text, 'count' => ''];
    }

    /** The `ss_status` an href carries, or null when it carries none. */
    private function statusOf(string $href): ?string
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        $value = $query['ss_status'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The order references the list at `$href` really serves, driven through the SAME DataTables
     * AJAX leg the browser uses (yajra's `minifiedAjax()` makes the page url the data url, so the
     * chip's own query string is what the table sees).
     *
     * @return list<string>
     */
    private function referencesAt(string $href, ?User $as = null): array
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $filters);

        $query = array_merge($filters, ['draw' => 1, 'start' => 0, 'length' => 100]);

        foreach (['order_id', 'price'] as $i => $column) {
            $query["columns[{$i}][data]"] = $column;
            $query["columns[{$i}][name]"] = $column;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'asc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        $response = $this->actingAs($as ?? $this->merchant)
            ->withHeaders(self::AJAX)
            ->getJson('/order?' . http_build_query($query));

        $this->assertSame(200, $response->getStatusCode(),
            "the list a chip links to did not serve: {$href}");

        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('draw', $payload, 'premise: yajra served the AJAX leg');

        $refs = [];

        /* The column is RENDERED MARKUP, not the raw value: yajra hands back an invoice button
           wrapping `#ORD-...` and an encrypted order id. Measured, not assumed -- the first
           version of this test compared against the raw reference and the diff printed the whole
           anchor. The reference is what identifies the row, so it is extracted rather than
           matched loosely with `str_contains`, which would make "exactly these and no others"
           unprovable. */
        foreach ($payload['data'] ?? [] as $row) {
            $text = trim((string) preg_replace('#\s+#', ' ',
                strip_tags((string) ($row['product_order_id'] ?? ''))));

            $refs[] = ltrim($text, '#');
        }

        return $refs;
    }

    /** One order in each of the nine statuses, referenced by its status. */
    private function seedOneOrderPerStatus(Store $store, string $prefix = 'A'): void
    {
        foreach (self::CHIP_LABEL_STATUS as $status) {
            $this->makeOrder($store, [
                'product_order_id' => "ORD-{$prefix}-S{$status}",
                'delivered_status' => $status,
            ]);
        }
    }

    // =============================================================================================
    //  THE DEFECT: pairing, not presence
    // =============================================================================================

    /**
     * THE PRIMARY ASSERTION. For every linked chip: the href must carry the status whose label the
     * chip wears, AND the list at that href must contain exactly the one order that status has.
     *
     * The second half is what makes this a pairing test. Nine chips all pointing at `ss_status=0`
     * would satisfy "has a status parameter"; they cannot satisfy this.
     */
    public function test_each_status_chip_lands_on_exactly_the_orders_it_counted(): void
    {
        $this->seedOneOrderPerStatus($this->store);

        $links = $this->chipLinks($this->chipsMarkup($this->dashboard()));

        $this->assertCount(9, $links,
            'all nine status chips should be links for a merchant holding Manage Order; got '
            . count($links));

        $seen = [];

        foreach ($links as $link) {
            $this->assertArrayHasKey($link['label'], self::CHIP_LABEL_STATUS,
                "a chip is labelled '{$link['label']}', which is not one of the nine order "
                . 'statuses; a renamed label loses the Arabic translation it already has');

            $expected = (string) self::CHIP_LABEL_STATUS[$link['label']];

            $this->assertSame($expected, $this->statusOf($link['href']),
                "the '{$link['label']}' chip links to {$link['href']}, which does not select "
                . "status {$expected} — the merchant is shown a count and then a different list");

            $this->assertSame(
                ["ORD-A-S{$expected}"],
                $this->referencesAt($link['href']),
                "the list behind the '{$link['label']}' chip is not exactly the orders it counted"
            );

            $seen[] = $expected;
        }

        sort($seen);

        $this->assertSame(
            array_map('strval', range(0, 8)),
            $seen,
            'the nine chips must select nine DIFFERENT statuses; a repair that gave them all the '
            . 'same parameter would pass a presence check and fail this one'
        );
    }

    /**
     * NEGATIVE CONTROL — the defect itself, stated as a property. No chip may land on the
     * unfiltered list. This is the assertion that fails before the repair and is the reason the
     * card's own note ("Select a status to open that list") was untrue.
     */
    public function test_no_status_chip_lands_on_the_unfiltered_order_list(): void
    {
        $this->seedOneOrderPerStatus($this->store);

        $refs = [];

        foreach (self::CHIP_LABEL_STATUS as $status) {
            $refs[] = "ORD-A-S{$status}";
        }

        sort($refs);

        $this->assertSame($refs, $this->sorted($this->referencesAt('/order')),
            'premise: the UNFILTERED list serves all nine, so every chip below that serves all '
            . 'nine is serving the unfiltered list');

        foreach ($this->chipLinks($this->chipsMarkup($this->dashboard())) as $link) {
            $this->assertNotSame($refs, $this->sorted($this->referencesAt($link['href'])),
                "the '{$link['label']}' chip opens every order in the store, not the ones it "
                . 'counted');
        }
    }

    private function sorted(array $refs): array
    {
        sort($refs);

        return $refs;
    }

    // =============================================================================================
    //  THE PERMISSION GATE MUST NOT WIDEN
    // =============================================================================================

    /**
     * NEGATIVE CONTROL. A staff member whose role grants only Manage Blog must get static spans:
     * no href (a link the controller will refuse is a dead link) and no `<button>` (a toggle that
     * does nothing is worse than a label — this is what `:interactive="false"` is for, and it is
     * load-bearing precisely here, where `$href` is null).
     */
    public function test_a_principal_without_manage_order_gets_static_chips_and_no_link(): void
    {
        $staff = $this->makeStaff($this->merchant, ['current_store' => $this->store->id]);
        $this->grantPermission(User::find($staff->id), 'Manage Blog');
        $staff = User::find($staff->id);

        $chips = $this->chipsMarkup($this->dashboard($staff));

        $this->assertStringNotContainsString('ss_status', $chips,
            'a principal who may not open the order screen was given links into it');
        $this->assertStringNotContainsString('<button', $chips,
            'the unpermitted case rendered dead <button aria-pressed="false"> toggles; '
            . ':interactive="false" on the chips is what prevents that and must survive');
        $this->assertSame([], $this->chipLinks($chips),
            'no chip may be a link for a principal lacking Manage Order');

        $labels = array_column($this->chipStatics($chips), 'label');

        foreach (array_keys(self::CHIP_LABEL_STATUS) as $label) {
            if ($label === 'Partially Paid' || $label === 'Pre Order') {
                continue;   // those two render only above zero; this store has no orders
            }

            $this->assertContains($label, $labels,
                "the '{$label}' chip disappeared for an unpermitted principal; the count is not "
                . 'the secret, the list behind it is');
        }
    }

    // =============================================================================================
    //  WHAT THE HREF MUST NOT CARRY
    // =============================================================================================

    /**
     * NEGATIVE CONTROL. `Order` is not in `config/tenancy.php`'s `scoped_models`, so
     * `OrderDataTable::query()`'s `where('store_id', getCurrentStore())` — derived from the
     * PRINCIPAL — is the only isolation. A `store_id` in the href would be a tenant predicate
     * built from caller-supplied input, which is this codebase's most repeated defect shape.
     *
     * And no `ss_apply`: `TableFilterMemory::remember()` writes only on that key, so a chip
     * carrying it would make one dashboard click the merchant's permanent order-screen default.
     */
    public function test_no_chip_href_carries_a_store_id_or_an_apply_signal(): void
    {
        $this->seedOneOrderPerStatus($this->store);

        $links = $this->chipLinks($this->chipsMarkup($this->dashboard()));

        $this->assertNotEmpty($links, 'premise: there are chips to inspect');

        foreach ($links as $link) {
            parse_str((string) parse_url($link['href'], PHP_URL_QUERY), $query);

            $this->assertArrayNotHasKey('store_id', $query,
                "the '{$link['label']}' chip carries a caller-supplied store_id");
            $this->assertArrayNotHasKey('ss_apply', $query,
                "the '{$link['label']}' chip carries ss_apply, so clicking it would become the "
                . "merchant's permanent default for the order screen");
            $this->assertArrayNotHasKey('ss_clear', $query,
                "the '{$link['label']}' chip carries ss_clear, so clicking it would forget the "
                . 'filters the merchant had set');
            $this->assertSame(['ss_status'], array_keys($query),
                "the '{$link['label']}' chip's query string should be exactly ss_status");
        }
    }

    /** A chip click shows a filter. It must not STORE one. */
    public function test_a_chip_click_does_not_become_the_remembered_default(): void
    {
        $this->seedOneOrderPerStatus($this->store);

        $links = $this->chipLinks($this->chipsMarkup($this->dashboard()));
        $href = $links[0]['href'];

        $this->assertNotNull($this->statusOf($href),
            'premise: the chip followed here really carries a filter, so the "was it remembered?" '
            . 'assertion below is answering about a filter and not about an empty query string');

        $this->actingAs($this->merchant)->get($href)->assertStatus(200);

        $this->actingAs($this->merchant)->get(route('order.index'))->assertStatus(200);
    }

    /**
     * NEGATIVE CONTROL — tenancy. The list reached from store A's chip must not hold store B's
     * order of the same status, however precise the filter became.
     */
    public function test_a_chip_link_does_not_reach_another_stores_orders(): void
    {
        $this->seedOneOrderPerStatus($this->store);

        [$other, $otherStore] = $this->makeTenant();
        $this->seedOneOrderPerStatus($otherStore, 'B');

        foreach ($this->chipLinks($this->chipsMarkup($this->dashboard())) as $link) {
            $status = $this->statusOf($link['href']);

            $this->assertNotNull($status,
                "premise: the '{$link['label']}' chip carries a status, so the cross-tenant "
                . 'assertion below names a real order reference rather than ORD-B-S');

            $this->assertNotContains("ORD-B-S{$status}", $this->referencesAt($link['href']),
                "store A's '{$link['label']}' chip reached store B's order of the same status");
        }
    }

    // =============================================================================================
    //  THE PARTITION, AND THE REGIONS THAT MUST NOT VANISH
    // =============================================================================================

    /**
     * A zero-count status keeps its chip and its count, and gets no link: a knowably empty list is
     * not an affordance. The partition the card states ("These add up to :sum of :total") is only
     * readable if every status stays on screen.
     */
    public function test_a_status_with_no_orders_keeps_its_chip_and_gets_no_link(): void
    {
        $this->makeOrder($this->store, [
            'product_order_id' => 'ORD-A-S0',
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
        ]);

        $chips = $this->chipsMarkup($this->dashboard());

        $statics = [];

        foreach ($this->chipStatics($chips) as $chip) {
            $statics[$chip['label']] = $chip['count'];
        }

        $this->assertSame('0', $statics['Picked Up'] ?? null,
            'the Picked Up chip must still show its zero; hiding it would break the partition the '
            . 'card footer reconciles');

        foreach ($this->chipLinks($chips) as $link) {
            $this->assertSame('Pending', $link['label'],
                "the '{$link['label']}' chip has no orders, so its link opens a knowably empty "
                . 'list');
        }
    }

    /**
     * NEGATIVE CONTROL — the silent-regression hazard. The non-fulfilment region's guard used to
     * be POSITIONAL (`$ssNotFulfilment[0]['count']`), so reshaping those rows could delete the
     * whole "Recorded in the same field, but not fulfilment states" region for every merchant,
     * with no test to notice. Each of the three triggers is driven on its own.
     *
     * @dataProvider nonFulfilmentCases
     */
    public function test_the_non_fulfilment_region_renders_for_each_of_its_triggers(
        int $status,
        string $label
    ): void {
        $this->makeOrder($this->store, [
            'product_order_id' => 'ORD-A-S' . $status,
            'delivered_status' => $status,
        ]);

        $body = $this->dashboard();

        $this->assertStringContainsString('Recorded in the same field, but not fulfilment states',
            $body,
            "a store holding only a '{$label}' order lost the non-fulfilment region entirely");

        $labels = array_merge(
            array_column($this->chipLinks($this->chipsMarkup($body)), 'label'),
            array_column($this->chipStatics($this->chipsMarkup($body)), 'label')
        );

        $this->assertContains($label, $labels, "the '{$label}' chip is not on the page");
    }

    public static function nonFulfilmentCases(): array
    {
        return [
            'partially paid' => [HomeController::ORDER_STATUS_PARTIALLY_PAID, 'Partially Paid'],
            'pre order' => [HomeController::ORDER_STATUS_PRE_ORDER, 'Pre Order'],
        ];
    }

    /**
     * NEGATIVE CONTROL. "Other" is any `delivered_status` outside 0-8, and
     * `OrderDataTable::applyFilters()` adds NO predicate for a value outside the nine constants.
     * So a link there lands on the unfiltered list while looking precise — false precision, the
     * same failure the out-of-stock link is deliberately left vague to avoid.
     */
    public function test_the_other_chip_is_not_linked(): void
    {
        $this->makeOrder($this->store, [
            'product_order_id' => 'ORD-A-S99',
            'delivered_status' => 99,
        ]);

        $chips = $this->chipsMarkup($this->dashboard());

        $this->assertContains('Other', array_column($this->chipStatics($chips), 'label'),
            'premise: an out-of-range status renders the Other chip');

        foreach ($this->chipLinks($chips) as $link) {
            $this->assertNotSame('Other', $link['label'],
                'the Other chip was linked; an unrecognised ss_status narrows nothing, so that '
                . 'link opens the whole list wearing a precise-looking parameter');
            $this->assertNotSame('99', $this->statusOf($link['href']),
                'a chip selects a status the filter does not recognise');
        }
    }

    /**
     * NEGATIVE CONTROL — the decision next door, which this repair must not "finish". The
     * out-of-stock count includes untracked products `ss_stock=out` cannot match, so that link
     * stays unfiltered (pinned by `OrderAttentionCentreTest`). Asserted here too, because this is
     * the change most likely to tempt somebody into adding it.
     */
    public function test_the_out_of_stock_link_is_still_not_given_false_precision(): void
    {
        $overview = (string) file_get_contents(base_path('resources/views/dashboard/overview.blade.php'));

        /* ANCHORED ON THE ITEM'S OWN TEXT, the same way `OrderAttentionCentreTest` locates it —
           and NOT on a bare `ss_stock=out` search over the whole file, which the WS C comment a
           few lines below mentions in prose. The first version of this test did exactly that and
           failed before a line of the repair was written: the file was already "containing" the
           string it was asserting against, in a comment explaining why the link does not use it. */
        $anchor = strpos($overview, "trans_choice('product is out of stock");

        $this->assertNotFalse($anchor,
            'premise: the stock attention item was not found, so this test measures nothing');

        $block = substr($overview, $anchor, 400);

        $this->assertStringContainsString("__('Review stock')", $block,
            'premise: the located block really is the stock item');

        $this->assertStringNotContainsString('ss_stock', $block,
            'the out-of-stock link was given a filter its own count does not match. Adding '
            . 'precision to the STATUS chips is sound because their two predicates are '
            . 'measurably identical; the stock count includes untracked products ss_stock=out '
            . 'cannot match, so it must stay unfiltered.');
    }

    // =============================================================================================
    //  THE DELIBERATE EXTENSION: the two attention items beside the chips
    // =============================================================================================

    /**
     * A DELIBERATE EXTENSION of the chip repair, named rather than smuggled in. "N orders are
     * waiting to be confirmed" and "N orders have been returned" are a different component in a
     * different region, and both landed on the same unfiltered-then-restored list. The returns one
     * is the link `PHASE-02-TASK-LEDGER.md` row P2-04-13 already claimed existed; `git log -S
     * ss_status -- resources/views/dashboard/overview.blade.php` was empty, so it never had.
     *
     * `ss_status=3`, NOT `ss_return=1`: `$ssReturnOrders` is `delivered_status = 3`, the
     * fulfilment status, while `orders.return_status` is the separate return-REQUEST workflow. The
     * list is followed, so a repair that picked the wrong column fails here.
     */
    public function test_the_pending_and_returns_attention_items_land_on_their_own_lists(): void
    {
        $this->makeOrder($this->store, [
            'product_order_id' => 'ORD-A-S0',
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
        ]);
        $this->makeOrder($this->store, [
            'product_order_id' => 'ORD-A-S3',
            'delivered_status' => HomeController::ORDER_STATUS_RETURN,
        ]);
        $this->makeOrder($this->store, [
            'product_order_id' => 'ORD-A-S1',
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
        ]);

        $body = $this->dashboard();

        preg_match('#<[^>]*class="ss-attention.*?</(?:section|div|ul)>#s', $body, $region);

        $this->assertNotEmpty($region, 'premise: the attention region rendered');

        preg_match_all('#href="([^"]*order[^"]*)"#', $region[0], $hits);

        $hrefs = array_map(fn ($h) => html_entity_decode($h, ENT_QUOTES), $hits[1]);

        /* `$v !== null`, NOT a bare `array_filter`. Pending is status `'0'`, which is FALSY in
           PHP, so a default `array_filter` silently drops the one chip most likely to be wrong
           and the assertion below passes against a missing link. Measured: the first version of
           this test did exactly that. */
        $statuses = array_values(array_filter(
            array_map([$this, 'statusOf'], $hrefs),
            fn ($v) => $v !== null
        ));

        $this->assertContains((string) HomeController::ORDER_STATUS_PENDING, $statuses,
            'the "orders are waiting to be confirmed" item does not open the pending list');
        $this->assertContains((string) HomeController::ORDER_STATUS_RETURN, $statuses,
            'the "orders have been returned" item does not open the returned list; it counts '
            . 'delivered_status = 3, so ss_return=1 would be the wrong column');

        foreach ($hrefs as $href) {
            $status = $this->statusOf($href);

            if ($status === null) {
                continue;
            }

            $this->assertSame(["ORD-A-S{$status}"], $this->referencesAt($href),
                "the attention item linking to {$href} opens a list that is not the orders it "
                . 'counted');
        }
    }

    // =============================================================================================
    //  ARABIC
    // =============================================================================================

    /**
     * The landed page must name the status in the merchant's own language. `TableFilterMemory`'s
     * receipt derives its label from the constant NAMES, and all nine are already in `ar.json` —
     * so the repair adds no `__()` string, and an Arabic merchant sees a word rather than the raw
     * integer the url carries.
     */
    public function test_the_landed_page_names_the_status_in_arabic(): void
    {
        $this->merchant->language = 'ar';
        $this->merchant->save();
        $this->merchant = User::find($this->merchant->id);

        $this->seedOneOrderPerStatus($this->store);

        $links = $this->chipLinks($this->chipsMarkup($this->dashboard()));

        $byStatus = [];

        foreach ($links as $link) {
            $byStatus[(string) $this->statusOf($link['href'])] = $link['href'];
        }

        $href = $byStatus[(string) HomeController::ORDER_STATUS_CANCEL] ?? null;

        $this->assertNotNull($href, 'premise: the Cancel Order chip carries its status');

        $body = (string) $this->actingAs($this->merchant)->get($href)->getContent();

        $this->assertStringContainsString('إلغاء', $body,
            'the Arabic landing page does not name the status it was filtered to');
        $this->assertStringNotContainsString('Status: 2', $body,
            'the receipt shows the raw integer from the url instead of a status name');
    }
}
