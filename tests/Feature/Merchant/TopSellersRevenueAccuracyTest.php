<?php

namespace Tests\Feature\Merchant;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Support\TopSellersByRevenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F242 — THE "BY SALES" CARDS REPORT THE REAL FIGURES, FOR ANY NUMBER OF PRODUCTS.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THESE CASES COULD NOT EXIST BEFORE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The old implementation was twelve queries built on `SUBSTRING_INDEX` and `CURDATE` — **neither of
 * which SQLite has** — so the Feature suite could not execute this card at all, and its only
 * coverage was a MariaDB script plus a browser harness that rendered the cards empty. The figures
 * on the two most prominent dashboard cards were therefore untested in the ordinary suite for the
 * whole of Phase 02.
 *
 * `TopSellersByRevenue` is portable PHP over `orders.product_json`, so these figures are testable
 * here now. The MariaDB half still exists and still matters — it proves the same numbers on the
 * engine production runs — but the arithmetic is no longer only provable there.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * EVERY EXPECTED TOTAL BELOW IS COMPUTED INDEPENDENTLY, which is the owner's condition
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The directive says *"compare rendered figures with independent expected totals"*. So no case here
 * asks the implementation what it thinks and then asserts that: each expected figure is written as
 * an arithmetic literal derived from the fixture by hand. A test that recomputed the total the same
 * way the code does would pass against the fan-out bug too.
 */
class TopSellersRevenueAccuracyTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    private $store;

    private $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->store] = $this->makeTenant();
        [, $this->otherStore] = $this->makeTenant();
    }

    private function category(string $name, $store = null): Category
    {
        return Category::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'image_path' => 'uploads/' . $name . '.png',
            'status' => 1,
            'store_id' => ($store ?? $this->store)->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(Category $category, $store = null): Product
    {
        $store = $store ?? $this->store;

        return Product::forceCreate([
            'name' => 'p-' . uniqid(), 'slug' => 'p-' . uniqid(),
            'category_id' => $category->id,
            'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock',
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /**
     * @param  array<int, array{0:Product, 1:int, 2:float}>  $lines  [product, qty, unit price]
     */
    private function order(array $lines, $store = null, ?string $createdAt = null, ?string $currency = null): Order
    {
        $store = $store ?? $this->store;
        $json = [];
        $ids = [];
        $total = 0.0;

        foreach ($lines as [$product, $qty, $unit]) {
            $json[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'qty' => $qty,
                'quantity' => $qty,
                'price' => $unit,
                'final_price' => $qty * $unit,
            ];
            $ids[] = $product->id;
            $total += $qty * $unit;
        }

        $order = Order::create([
            'product_order_id' => 'ORD-' . strtoupper(uniqid()),
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode($json),
            /* The comma-separated list the OLD query parsed. Written faithfully so a reader can see
               that the new computation does not consult it — and so a revert would have data. */
            'product_id' => implode(',', $ids),
            'product_price' => $total,
            'coupon_price' => 0,
            /* Order-level money that belongs to NO category. Deliberately non-zero, because an
               implementation that summed `final_price` per category would include it. */
            'delivery_price' => 25,
            'tax_price' => 15,
            'final_price' => $total + 40,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            /* ANA-TOP. `null` BY DEFAULT, which is what every pre-existing case here writes and
               what a lot of real rows carry -- `orders.currency_code` is nullable and P5-R14
               measured the storefront API writing a SYMBOL into it. Those rows must still be
               counted, folded into the store's own currency and disclosed, never dropped. */
            'currency_code' => $currency,
        ]);

        if ($createdAt !== null) {
            /* `timestamps = false` so an explicit past `created_at` is not overwritten, and through
               Eloquent so the Cachable read-back is flushed. */
            $order->timestamps = false;
            $order->created_at = $createdAt;
            $order->save();
        }

        return $order->fresh();
    }

    /** The store's configured currency, which is what the ranking is denominated in. */
    private function storeCurrency($store, string $code): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->insert([
            'store_id' => $store->id, 'name' => 'defult_currancy', 'value' => $code,
            'created_by' => $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, float> the other-currency map for a named row */
    private function othersFor(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if ($row->sale_name === $name) {
                return (array) $row->other_currencies;
            }
        }

        return ['MISSING' => -1.0];
    }

    // ------------------------------------------------------- ANA-TOP: ranking semantics

    public function test_every_row_states_that_this_is_order_value_and_not_money_received(): void
    {
        /*
         * THE RANKING-SEMANTICS HALF. The cards were labelled "By Sales" while counting every order
         * line in the window -- unpaid, cancelled and refunded at full value. Owner Option A makes
         * that permanent rather than fixable: `orders.payment_status` is the customer's settlement
         * record and a cancellation deliberately does not rewrite it, so no predicate on `orders`
         * could turn this into collected money. It is an order-DEMAND ranking; it must say so.
         *
         * ASSERTED AGAINST `ReportFigureNote::orderValue()` RATHER THAN A LITERAL, because §11
         * makes that method the single source a screen hint and an export cell both read "so the
         * two cannot drift". A literal here would be a fifth copy of the sentence and would pass
         * while the shared source changed underneath it.
         */
        $lamps = $this->category('Lamps');
        $this->order([[$this->product($lamps), 2, 50.0]]);

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertNotEmpty($rows, 'PREMISE: the fixture produced no ranked row.');
        $this->assertStringContainsString(
            \App\Support\Reports\ReportFigureNote::orderValue(),
            $rows[0]->caveat,
            'the ranking does not state that it is order value rather than payments received'
        );
    }

    public function test_an_unpaid_cancelled_order_still_counts_which_is_why_the_disclosure_matters(): void
    {
        /* Not a defect to fix here -- it is what the figure IS, and the point of the disclosure.
           Pinned so nobody "fixes" it into a payment figure without the owner, and so the
           disclosure cannot be dropped while the inclusion stays. */
        $lamps = $this->category('Lamps');
        $order = $this->order([[$this->product($lamps), 1, 400.0]]);

        /* `delivered_status = 2` IS CANCELLATION, not an `order_status` column -- there is no such
           column on `orders`. The migration's own comment is the authority:
           "0=>pending/1=>diliver/2=>cancel/3=>return". The first draft of this case invented the
           column name and died on a QueryException rather than asserting anything. */
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)
            ->update(['delivered_status' => 2, 'payment_status' => 'Unpaid']);
        (new \App\Models\Order)->flushCache();

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertSame(400.0, $this->totalFor($rows, 'Lamps'),
            'a cancelled, unpaid order is still order value; excluding it here would invent a '
            . 'payment predicate the owner has not approved');
        $this->assertStringContainsString(
            \App\Support\Reports\ReportFigureNote::orderValue(),
            $rows[0]->caveat,
            'it counts a cancelled unpaid order and does not say what the figure is'
        );
    }

    public function test_the_two_cards_are_labelled_order_value_and_not_sales(): void
    {
        /*
         * THE LABEL IS HALF OF THE CORRECTION. A caveat under a figure headed "By Sales" still
         * leaves the heading claiming money received, and the heading is what a merchant reads
         * first. `MetricThreshold` already labels `GrossMerchandiseValue` "Order value", so this
         * is the approved D2 vocabulary rather than a phrase invented here.
         *
         * COMMENTS ARE STRIPPED BECAUSE THIS FILE WOULD OTHERWISE SATISFY ITSELF: the dashboard
         * view carries the literal `"By Sales"` in a comment recording what the cards used to be
         * called, so a naive search for it over the raw source reports the defect as still
         * present, and a naive search for its absence can never pass. That is the census trap
         * CLAUDE.md names, hit four times on 2026-11-21 alone.
         */
        $view = $this->sourceWithoutComments('resources/views/dashboard.blade.php');

        $this->assertSame(
            2,
            substr_count($view, "__('By order value')"),
            'both ranked cards must state that they rank by order value'
        );
        $this->assertStringNotContainsString(
            "__('By Sales')",
            $view,
            'a card headed "By Sales" claims money received; this figure counts unpaid, '
            . 'cancelled and refunded order lines'
        );

        /* Both languages, because a merchant reading Arabic gets the first screen in Arabic. */
        foreach (['ar', 'en'] as $locale) {
            $json = json_decode(
                (string) file_get_contents(base_path('resources/lang/' . $locale . '.json')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertArrayHasKey('By order value', $json, $locale . '.json is missing the label');
            $this->assertNotSame('', trim((string) $json['By order value']));
        }
    }

    public function test_a_row_with_no_order_date_is_still_counted_by_this_card(): void
    {
        /*
         * THE HALF OF THE WINDOW DECISION THAT A COLUMN SWAP WOULD BREAK.
         *
         * `orders.order_date` is `dateTime()->nullable()`; `created_at` is not. Every other panel
         * on this dashboard windows on `order_date`, so a row with a null one is silently absent
         * from all of them. This card windows on `created_at` and therefore counts it. That is
         * the behaviour to keep: aligning the card by dropping the row would buy agreement by
         * losing data. `TopSellersByRevenue::revenueByProduct()` carries the measurement.
         */
        $c = $this->category('Lamps');
        $p = $this->product($c);

        $start = now()->startOfDay();
        $order = $this->order([[$p, 2, 30.0]], null, $start->copy()->addHours(3)->toDateTimeString());

        /* RAW, because `order_date` must become null without Eloquent re-stamping it, then
           flushed because `Order` is `Cachable` and under `CACHE_STORE=array` a stale read
           would make this test pass against the pre-write row. */
        DB::table('orders')->where('id', $order->id)->update(['order_date' => null]);
        (new Order)->flushCache();

        /* The premise. Without it a writer that quietly kept a date would make the case vacuous. */
        $this->assertNull(
            DB::table('orders')->where('id', $order->id)->value('order_date'),
            'premise failed: the row still has an order_date, so this case proves nothing'
        );

        $rows = TopSellersByRevenue::compute(
            TopSellersByRevenue::CATEGORY,
            $this->store->id,
            $start->toDateTimeString(),
            $start->copy()->addDay()->toDateTimeString()
        );

        $this->assertSame(60.0, $this->totalFor($rows, 'Lamps'),
            'a row with no order_date vanished from the card; this card windows on created_at '
            . 'precisely so that it does not');
    }

    public function test_every_order_writer_records_an_order_date(): void
    {
        /*
         * WHY THE DIVERGENCE IS HARMLESS, AS A RATCHET RATHER THAN AS PROSE.
         *
         * The comment in `revenueByProduct()` rests on a census: every site in `app/` that makes
         * an `orders` row sets `order_date` in the same block, to the instant Eloquent stamps
         * into `created_at`. That is what makes the two windows select the same orders for every
         * row the application has written -- and it is a claim about the source, so it decays
         * silently unless something counts it. A new creation path that forgets the column would
         * start producing rows this card counts and every other panel drops.
         *
         * COMMENTS ARE STRIPPED. Four of this repository's censuses have been satisfied by prose
         * about the thing they measure, and `Order::$fillable` carries the literal
         * `Order::create([` inside a docblock, which an unstripped scan reads as a creation site.
         */
        $sites = [];
        $missing = [];

        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));

        foreach ($dir as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'app/' . ltrim(str_replace(base_path('app'), '', $file->getPathname()), '/');
            $code = $this->sourceWithoutComments($relative);

            /*
             * A SECOND, NARROWER PASS, and the shared trait is deliberately not changed to do it.
             * `StripsSourceComments` strips only whole-line `//` comments, because stripping
             * trailing ones would eat the `//` in every `https://` URL in the codebase. But
             * `Order::$fillable` carries `// ... Order::create([...])` as a TRAILING comment on a
             * code line, and that one line was read as a creation site -- the census trap again,
             * one syntax further on. Here the risk is absent: this census only needs to know
             * whether a line opens a creation statement, and a `//` preceded by a colon (a URL)
             * is left alone.
             */
            $code = (string) preg_replace('#(?<![:\'\"])//.*$#m', '', $code);
            $lines = explode("\n", $code);

            foreach ($lines as $i => $line) {
                $creates = preg_match('/\bOrder::create\s*\(/', $line)
                    || preg_match('/=\s*new\s+(?:\\\\App\\\\Models\\\\)?Order\s*\(\s*\)/', $line);

                if (! $creates) {
                    continue;
                }

                $site = $relative . ':' . ($i + 1);
                $sites[] = $site;

                /* The write is never far from the constructor in this codebase -- the furthest
                   measured is 4 lines. 70 is generous and still inside one statement block. */
                $block = implode("\n", array_slice($lines, $i, 70));

                if (! str_contains($block, 'order_date')) {
                    $missing[] = $site;
                }
            }
        }

        $this->assertSame([], $missing, sprintf(
            "%d order-creating site(s) in app/ do not record an order_date:\n  %s\n\n"
            . "A row written without one is counted by the dashboard's Top Category / Top Brand "
            . "card (it windows on created_at) and silently dropped by every other panel and "
            . "report (they window on order_date). Either set the column, or read "
            . "TopSellersByRevenue::revenueByProduct() and widen the decision deliberately.",
            count($missing),
            implode("\n  ", $missing)
        ));

        /* The census must actually have found the sites -- a stripper or a pattern that matched
           nothing would make the assertion above a free pass. Measured 2026-11-23. */
        $this->assertSame(16, count($sites), sprintf(
            "the order-creation census now finds %d site(s) in app/, not the 16 measured when this "
            . "ratchet was written: %s\n\nA new one is fine; classify it and move the number.",
            count($sites),
            implode(', ', $sites)
        ));
    }

    // ------------------------------------------------------- ANA-TOP: currency integrity

    public function test_two_currencies_are_never_added_together(): void
    {
        /*
         * THE DEFECT, AS ONE FIXTURE. This method used to return a single bare float per category,
         * summed over every order regardless of `orders.currency_code`, and the card printed it
         * under the store's one symbol. 100 USD of Lamps plus 200 EUR of Lamps read as "300" in
         * whatever symbol the store had configured -- arithmetic on incommensurable units,
         * presented as a fact, with nothing on screen admitting it.
         *
         * No conversion is performed, because no conversion basis has been approved. The store's
         * own currency is the figure; the other is listed beside it.
         */
        $this->storeCurrency($this->store, 'USD');

        $lamps = $this->category('Lamps');
        $product = $this->product($lamps);

        $this->order([[$product, 1, 100.0]], null, null, 'USD');
        $this->order([[$product, 1, 200.0]], null, null, 'EUR');

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertSame(100.0, $this->totalFor($rows, 'Lamps'),
            'the figure must be the store-currency amount alone, never 300');
        $this->assertSame(['EUR' => 200.0], $this->othersFor($rows, 'Lamps'),
            'the other currency must be carried separately so the card can disclose it');
    }

    public function test_an_order_with_no_recorded_currency_is_counted_and_disclosed(): void
    {
        /*
         * THE OPPOSITE FAILURE, and the one a careless fix introduces. Bucketing by currency and
         * then keeping only the store's code would DROP every row whose `currency_code` is null or
         * a symbol -- trading an over-report for a quietly smaller number, which is worse because
         * nothing looks wrong. `MetricResult::assumingUnknownCurrencyIs()` plus a
         * `CURRENCY_UNRECORDED` caveat is the established treatment and this mirrors it.
         */
        $this->storeCurrency($this->store, 'USD');

        $lamps = $this->category('Lamps');
        $product = $this->product($lamps);

        $this->order([[$product, 1, 40.0]], null, null, 'USD');

        /*
         * A NULL HAS TO BE WRITTEN RAW, AND THE FIRST DRAFT OF THIS TEST DID NOT KNOW THAT.
         *
         * `Order::create(['currency_code' => null])` does NOT produce a null row:
         * `Order.php:177-178` stamps `OrderCurrencyStamp::codeFor($storeId)` on any order created
         * without one. So this fixture measured the stamp, not the gap -- the total came out right
         * for the wrong composition, and only the caveat assertion noticed. Writing it raw is what
         * a LEGACY row actually looks like: rows that predate the stamp, or any path that inserts
         * without Eloquent.
         *
         * `Order` is `Cachable`, so the raw write is followed by a flush -- CLAUDE.md records
         * three measured false readings from skipping it -- and the read-back is asserted so this
         * case cannot silently go back to measuring the stamp.
         */
        $legacy = $this->order([[$product, 1, 60.0]], null, null, 'USD');
        \Illuminate\Support\Facades\DB::table('orders')
            ->where('id', $legacy->id)->update(['currency_code' => null]);
        (new Order)->flushCache();

        $this->assertNull(Order::withoutGlobalScopes()->find($legacy->id)->currency_code,
            'PREMISE: the raw write did not stick, so this case is measuring the stamped code again');

        /* A SYMBOL rather than a code, which P5-R14 measured the storefront API really writing.
           `CurrencySymbol::code()` refuses it, so it lands in the same unreadable bucket. */
        $symbolled = $this->order([[$product, 1, 25.0]], null, null, 'USD');
        \Illuminate\Support\Facades\DB::table('orders')
            ->where('id', $symbolled->id)->update(['currency_code' => '$']);
        (new Order)->flushCache();

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertSame(125.0, $this->totalFor($rows, 'Lamps'),
            'an unreadable currency must still be counted, in the store currency');
        $this->assertSame([], $this->othersFor($rows, 'Lamps'));
        $this->assertSame(85.0, $rows[0]->unrecorded_currency_amount,
            'both unreadable rows must land in the unrecorded bucket: 60 null + 25 symbol');
        $this->assertStringContainsString('85.00', $rows[0]->caveat,
            'the amount counted from rows with no currency must be disclosed, not absorbed');
    }

    public function test_a_category_earning_only_a_foreign_currency_is_listed_without_a_comparable_total(): void
    {
        /* Not dropped -- a merchant would never learn the category existed -- and not ranked
           against store-currency rows either, because there is no rate to rank it by. */
        $this->storeCurrency($this->store, 'USD');

        $lamps = $this->category('Lamps');
        $rugs = $this->category('Rugs');

        $this->order([[$this->product($lamps), 1, 10.0]], null, null, 'USD');
        $this->order([[$this->product($rugs), 1, 900.0]], null, null, 'EUR');

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $names = array_map(fn ($r) => $r->sale_name, $rows);
        $this->assertContains('Rugs', $names, 'a foreign-currency category must not vanish');
        $this->assertSame('Lamps', $rows[0]->sale_name,
            'the store-currency row ranks first; 900 EUR must not outrank 10 USD');

        foreach ($rows as $row) {
            if ($row->sale_name === 'Rugs') {
                $this->assertNull($row->total_sale,
                    '0.00 would rank it against categories that genuinely earned nothing');
                $this->assertSame(['EUR' => 900.0], (array) $row->other_currencies);
            }
        }
    }

    public function test_another_tenants_revenue_never_reaches_this_ranking_in_any_currency(): void
    {
        $this->storeCurrency($this->store, 'USD');
        $this->storeCurrency($this->otherStore, 'USD');

        $mine = $this->category('Mine');
        $theirs = $this->category('Theirs', $this->otherStore);

        $this->order([[$this->product($mine), 1, 5.0]], null, null, 'USD');
        $this->order([[$this->product($theirs, $this->otherStore), 1, 5000.0]], $this->otherStore, null, 'USD');
        $this->order([[$this->product($theirs, $this->otherStore), 1, 7000.0]], $this->otherStore, null, 'EUR');

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $names = array_map(fn ($r) => $r->sale_name, $rows);
        $this->assertSame(['Mine'], $names);
        $this->assertSame(5.0, $this->totalFor($rows, 'Mine'));
    }

    public function test_the_primary_currency_is_read_from_this_store_and_not_another(): void
    {
        /*
         * ADDED BECAUSE A MUTATION SURVIVED. Removing the `store_id` predicate from the
         * `defult_currancy` lookup changed nothing in the cross-tenant test above, because both
         * fixtures configured USD -- so the predicate was untested, and an untested predicate is
         * one a later edit can delete for free.
         *
         * THE FIXTURE IS BUILT SO THE WRONG ANSWER IS VISIBLE: this store configures NOTHING and
         * the other store configures EUR. Scoped, the lookup finds no row here and falls back to
         * this store's busiest bucket, USD. Unscoped, it finds the other tenant's EUR and
         * denominates this merchant's ranking in a currency they never chose -- while the amount
         * stays the USD amount, which is the mislabelling this whole slice is about.
         */
        $this->storeCurrency($this->otherStore, 'EUR');

        $lamps = $this->category('Lamps');
        $this->order([[$this->product($lamps), 1, 55.0]], null, null, 'USD');

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertSame('USD', $rows[0]->currency,
            "another tenant's configured currency is naming this store's figures");
        $this->assertSame(55.0, $this->totalFor($rows, 'Lamps'));
    }

    public function test_an_unconfigured_store_is_denominated_in_its_busiest_currency_rather_than_nothing(): void
    {
        /* No `defult_currancy` row at all. Classing every bucket as foreign would leave the whole
           ranking without a comparable total, which is a refusal where an answer exists. */
        $lamps = $this->category('Lamps');
        $product = $this->product($lamps);

        $this->order([[$product, 1, 30.0]], null, null, 'EUR');
        $this->order([[$product, 1, 70.0]], null, null, 'EUR');
        $this->order([[$product, 1, 10.0]], null, null, 'GBP');

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertSame('EUR', $rows[0]->currency);
        $this->assertSame(100.0, $this->totalFor($rows, 'Lamps'));
        $this->assertSame(['GBP' => 10.0], $this->othersFor($rows, 'Lamps'));
    }

    public function test_the_brand_ranking_obeys_the_same_currency_rule_as_the_category_ranking(): void
    {
        /* The two dimensions share one computation, and the user-visible requirement is that they
           AGREE. Pinned rather than assumed, because they are reached by different request
           parameters and a future change could touch one path only. */
        $this->storeCurrency($this->store, 'USD');

        $lamps = $this->category('Lamps');
        $product = $this->product($lamps);

        $brand = \Illuminate\Support\Facades\DB::table('product_brands')->insertGetId([
            'name' => 'Acme', 'logo' => 'uploads/acme.png', 'status' => 1,
            'store_id' => $this->store->id, 'created_by' => $this->store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('products')
            ->where('id', $product->id)->update(['brand_id' => $brand]);

        $this->order([[$product, 1, 100.0]], null, null, 'USD');
        $this->order([[$product, 1, 200.0]], null, null, 'EUR');

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::BRAND, $this->store->id);

        $this->assertSame(100.0, $this->totalFor($rows, 'Acme'),
            'the brand ranking added two currencies where the category ranking did not');
        $this->assertSame(['EUR' => 200.0], $this->othersFor($rows, 'Acme'));
    }

    private function totalFor(array $rows, string $name): float
    {
        foreach ($rows as $row) {
            if ($row->sale_name === $name) {
                return (float) $row->total_sale;
            }
        }

        return -1.0;
    }

    /**
     * THE CASE THE OLD CODE GOT RIGHT BY ACCIDENT. One product, so the old fan-out was exactly 100
     * and the `/100` divisor cancelled it. Kept so the fix is shown not to break what worked.
     */
    public function test_a_single_product_order_is_counted_once(): void
    {
        $c = $this->category('Lamps');
        $p = $this->product($c);
        $this->order([[$p, 3, 20.0]]);                       // 3 x 20 = 60

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertCount(1, $rows);
        $this->assertSame(60.0, $this->totalFor($rows, 'Lamps'));
    }

    /**
     * THE CASE THE OLD CODE GOT WRONG, and the one F242 is about.
     *
     * Two products in two categories. Under the old shape the first category joined ONCE and was
     * then divided by 100, so a 60 read as 1.3 (the order total 140 / 100 rounded) while the second
     * joined 99 times and read near the full order total. Both were wrong, and in opposite
     * directions.
     *
     * Expected by hand: Lamps 3 x 20 = 60, Rugs 2 x 50 = 100. Neither includes the 25 delivery or
     * the 15 tax, which belong to no category.
     */
    public function test_a_two_product_order_credits_each_category_its_own_lines(): void
    {
        $lamps = $this->category('Lamps');
        $rugs = $this->category('Rugs');
        $this->order([
            [$this->product($lamps), 3, 20.0],
            [$this->product($rugs), 2, 50.0],
        ]);

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertCount(2, $rows);
        $this->assertSame(60.0, $this->totalFor($rows, 'Lamps'));
        $this->assertSame(100.0, $this->totalFor($rows, 'Rugs'));

        /* AND THE SUM IS NOT THE ORDER TOTAL, which is the second half of the defect. An
           implementation crediting each category the whole order would read 140 and 140. */
        $this->assertSame(160.0, array_sum(array_map(fn ($r) => $r->total_sale, $rows)),
            'the per-category figures add up to the ORDER total, so order-level money '
            . '(delivery 25 + tax 15) is being attributed to categories that did not earn it');
    }

    /**
     * THREE AND FOUR PRODUCTS, because the old fan-out error grew with the line count and the
     * directive names those counts explicitly.
     *
     * Expected by hand:
     *   Lamps 1x10 + 5x4   = 10 + 20 = 30
     *   Rugs  2x30         = 60
     *   Tiles 3x7          = 21
     */
    public function test_three_and_four_line_orders_are_exact(): void
    {
        $lamps = $this->category('Lamps');
        $rugs = $this->category('Rugs');
        $tiles = $this->category('Tiles');

        $lampA = $this->product($lamps);
        $lampB = $this->product($lamps);         // same category, two products

        $this->order([
            [$lampA, 1, 10.0],
            [$this->product($rugs), 2, 30.0],
            [$this->product($tiles), 3, 7.0],
        ]);
        $this->order([[$lampB, 5, 4.0]]);

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertSame(30.0, $this->totalFor($rows, 'Lamps'),
            'two products of one category across two orders did not add up');
        $this->assertSame(60.0, $this->totalFor($rows, 'Rugs'));
        $this->assertSame(21.0, $this->totalFor($rows, 'Tiles'));

        /* Ordering is part of what a "top sellers" card is. */
        $this->assertSame(['Rugs', 'Lamps', 'Tiles'], array_map(fn ($r) => $r->sale_name, $rows),
            'the card is not sorted by revenue descending');
    }

    /**
     * STORE ISOLATION, and it is not inherited: `Product` registers no `StoreScope` and is absent
     * from `config/tenancy.php`'s `scoped_models`, so an unpredicated `whereIn('id', …)` reads every
     * tenant's catalogue. Both stores use the SAME category name so a leak shows up as a wrong
     * figure rather than as an extra row.
     */
    public function test_another_stores_revenue_is_not_counted(): void
    {
        $mine = $this->category('Lamps');
        $theirs = $this->category('Lamps', $this->otherStore);

        $this->order([[$this->product($mine), 1, 10.0]]);
        $this->order([[$this->product($theirs, $this->otherStore), 1, 999.0]], $this->otherStore);

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertCount(1, $rows);
        $this->assertSame(10.0, $this->totalFor($rows, 'Lamps'),
            "another store's order reached this store's figures");

        $theirRows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->otherStore->id);
        $this->assertSame(999.0, $this->totalFor($theirRows, 'Lamps'), 'and the other store sees its own');
    }

    /**
     * THE CROSS-TENANT ORDER LINE, which is what the `products.store_id` predicate is actually for.
     *
     * **ADDED BECAUSE A MUTATION EXPOSED THE GAP.** Dropping that predicate failed NO test: the
     * product ids fed to `whereIn` already come from this store's own orders, so the predicate was
     * unexercised and the isolation case above passed either way. A guard no test reaches is the
     * F237 shape, and it was in my own fix.
     *
     * The case that reaches it: store A has an order whose LINE names store B's product. `Product`
     * registers no `StoreScope` and is absent from `scoped_models` (S19), so an unpredicated lookup
     * resolves it happily and would credit store B's category — inside store A's dashboard.
     *
     * Correct behaviour is that the line contributes to NEITHER store: not to A, because A does not
     * own the product; not to B, because B did not take the order. It is dropped and the figures stay
     * honest, which is better than guessing which tenant it belongs to.
     */
    public function test_an_order_line_naming_another_stores_product_is_dropped(): void
    {
        $mine = $this->category('Lamps');
        $theirCategory = $this->category('Rugs', $this->otherStore);
        $theirProduct = $this->product($theirCategory, $this->otherStore);

        /* Store A's order, one honest line plus one naming store B's product. */
        $this->order([
            [$this->product($mine), 1, 10.0],
            [$theirProduct, 1, 500.0],
        ]);

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertCount(1, $rows,
            "another store's product was resolved AND its category rendered inside this store's "
            . 'dashboard, so BOTH store predicates are missing — see the ratchet below for why '
            . 'this case cannot name which one');
        $this->assertSame(10.0, $this->totalFor($rows, 'Lamps'));
        $this->assertSame(-1.0, $this->totalFor($rows, 'Rugs'),
            "store B's category appeared in store A's figures");

        /* And store B does not gain revenue from an order it never took. */
        $theirRows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->otherStore->id);
        $this->assertSame([], $theirRows,
            "store B was credited 500 from store A's order");
    }

    /**
     * THE TWO STORE PREDICATES ARE REDUNDANT WITH EACH OTHER, AND THAT IS WHY THIS RATCHET EXISTS
     * RATHER THAN A BEHAVIOURAL TEST.
     *
     * **MEASURED, and it corrected a claim I had just made.** The case above was written to prove
     * that `products.store_id` keeps another tenant's product out. It does not prove that: removing
     * that predicate alone failed NO test, and removing the `categories`/`product_brands` predicate
     * alone failed no test either. Each one covers the other —
     *
     *   - without the PRODUCTS predicate, store B's product resolves and maps to store B's
     *     category id, but the LABELS query then refuses that id and the row is dropped unnamed;
     *   - without the LABELS predicate, the products query never let store B's id into the map in
     *     the first place.
     *
     * A category or brand id belongs to exactly one store, so no input shape makes the pair
     * non-redundant: only removing BOTH leaks, and no single-mutation test can isolate either. That
     * is defence in depth working as intended, and it is also exactly the condition under which a
     * guard quietly stops being exercised — the F237 shape.
     *
     * So the pair is ratcheted at the source, with the reason attached. A reader who removes one
     * "because nothing fails" is told here that nothing failing is expected and is not permission.
     */
    public function test_both_store_predicates_are_present_because_neither_alone_is_detectable(): void
    {
        /*
         * COMMENTS STRIPPED, and the review of ANA-TOP is why. This census counts occurrences of
         * a literal in the source. Read raw, it is satisfiable by PROSE: an edit that removes a
         * predicate AND adds a comment containing `->where('store_id', $storeId)` keeps the count
         * at four and the removal survives. CLAUDE.md records that exact failure hit four times in
         * one day, in three syntaxes; the sibling census in RawQueryTenancyInventoryTest strips
         * comments, which is why its numbers are trustworthy and this one's were not.
         *
         * This file's own docblocks now DO quote that literal, several times, so stripping is no
         * longer merely prudent -- without it the count would be wrong today.
         */
        $source = $this->sourceWithoutComments('app/Support/TopSellersByRevenue.php');

        /* THREE, not two: the `orders` query carries one on the same line as its `DB::table()`,
           so it matches this needle too. Counted after measuring rather than predicted — the first
           version of this assertion said 2 and failed against a correct file.

           FOUR SINCE ANA-TOP, and the direction matters: `primaryCurrency()` reads this store's
           configured currency from `settings`, and that read is scoped like every other. This
           ratchet counts tenant predicates, so a NEW scoped read raises it and a REMOVED one
           lowers it. Raising it to record a new predicate is the honest edit; lowering it to make
           a removal pass would be the defect this test exists to catch. */
        $this->assertSame(4, substr_count($source, "->where('store_id', \$storeId)"),
            'TopSellersByRevenue no longer carries all FOUR store predicates — one on `orders`, '
            . 'one on the `products` lookup, one on the `categories`/`product_brands` lookup, '
            . 'and one on the `settings` read that decides which currency the ranking is '
            . 'denominated in. Removing any one alone '
            . 'breaks no test, because each covers the other; removing both leaks another tenant\'s '
            . 'revenue into this dashboard. Product registers no StoreScope and is absent from '
            . "config/tenancy.php's scoped_models, so neither predicate is inherited.");

        /* And the orders query carries its own, which IS individually load-bearing — the isolation
           case above fails without it. */
        $this->assertStringContainsString("DB::table('orders')->where('store_id', \$storeId)", $source,
            'the orders query lost its store predicate, so every tenant\'s orders are summed');
    }

    /** No tenant resolves — refuse rather than return every store's revenue. */
    public function test_an_unresolved_store_gets_no_figures(): void
    {
        $this->order([[$this->product($this->category('Lamps')), 1, 10.0]]);

        foreach ([null, 0, '', 'abc', -1] as $storeId) {
            $this->assertSame([], TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $storeId),
                'an unresolved store id produced figures');
        }
    }

    /**
     * THE WINDOW IS HALF-OPEN `[from, to)`, so an order at exactly the boundary belongs to one
     * period and not two. A closed upper bound double-counts midnight.
     */
    public function test_the_date_window_is_half_open(): void
    {
        $c = $this->category('Lamps');
        $p = $this->product($c);

        $start = now()->startOfDay();
        $end = $start->copy()->addDay();

        $this->order([[$p, 1, 7.0]], null, $start->toDateTimeString());               // in
        $this->order([[$p, 1, 11.0]], null, $start->copy()->addHours(5)->toDateTimeString()); // in
        $this->order([[$p, 1, 13.0]], null, $end->toDateTimeString());                // OUT, exactly
        $this->order([[$p, 1, 17.0]], null, $start->copy()->subSecond()->toDateTimeString()); // OUT

        $rows = TopSellersByRevenue::compute(
            TopSellersByRevenue::CATEGORY, $this->store->id,
            $start->toDateTimeString(), $end->toDateTimeString()
        );

        $this->assertSame(18.0, $this->totalFor($rows, 'Lamps'),
            'the window is not half-open: 7 + 11 = 18 is the only correct answer, and 31 means the '
            . 'upper bound is inclusive while 35 means the lower bound leaks');

        /* All time sees everything. */
        $all = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);
        $this->assertSame(48.0, $this->totalFor($all, 'Lamps'));
    }

    /**
     * THE KEY SPELLINGS, driven directly, because the platform's writers do not agree: measured
     * across `app/` and the vendored modules, `quantity` appears at 33 sites and `qty` at 23, with
     * `price` at 56 and `final_price` at 37. A reader that knows one spelling silently reports zero
     * for a third of the platform's orders — which is this card's existing failure mode.
     */
    public function test_a_line_is_read_whichever_spelling_the_writer_used(): void
    {
        $cases = [
            'stated final_price wins' => [['final_price' => 90, 'qty' => 3, 'price' => 20], 90.0],
            'total_orignal_price next' => [['total_orignal_price' => 80, 'qty' => 2, 'price' => 20], 80.0],
            'qty x price' => [['qty' => 3, 'price' => 20], 60.0],
            'quantity x price' => [['quantity' => 4, 'price' => 5], 20.0],
            'qty wins over quantity' => [['qty' => 2, 'quantity' => 9, 'price' => 10], 20.0],
            'orignal_price as unit' => [['qty' => 2, 'orignal_price' => 15], 30.0],
            'nothing readable' => [['name' => 'x'], 0.0],
            'negative quantity is floored' => [['qty' => -5, 'price' => 10], 0.0],
            'zero stated total falls through' => [['final_price' => 0, 'qty' => 2, 'price' => 10], 20.0],
        ];

        foreach ($cases as $label => [$line, $expected]) {
            $this->assertSame($expected, TopSellersByRevenue::lineRevenue($line), $label);
        }
    }

    /**
     * THE NEW COMPUTATION DOES NOT READ `orders.product_id` AT ALL, and this proves it rather than
     * asserting it in a comment.
     *
     * The fixture writes a faithful comma-separated list; here it is corrupted into something the
     * old `SUBSTRING_INDEX` parse would have mangled, and the figures must be unchanged. If a future
     * change reintroduces a dependency on that column, this bites.
     */
    public function test_the_figures_do_not_depend_on_the_comma_separated_product_id(): void
    {
        $c = $this->category('Lamps');
        $order = $this->order([[$this->product($c), 2, 25.0]]);

        $before = $this->totalFor(
            TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id), 'Lamps'
        );

        DB::table('orders')->where('id', $order->id)->update(['product_id' => '9999,8888,,,7777']);
        (new Order)->flushCache();

        $after = $this->totalFor(
            TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id), 'Lamps'
        );

        $this->assertSame(50.0, $before);
        $this->assertSame($before, $after,
            'the figures moved when `orders.product_id` was corrupted, so the computation is still '
            . 'reading the comma-separated list rather than `product_json`');
    }

    /** An order whose `product_json` cannot be read contributes nothing rather than raising. */
    public function test_an_unreadable_order_is_skipped_not_fatal(): void
    {
        $c = $this->category('Lamps');
        $p = $this->product($c);
        $this->order([[$p, 1, 10.0]]);

        $broken = $this->order([[$p, 1, 10.0]]);
        DB::table('orders')->where('id', $broken->id)->update(['product_json' => 'not json']);
        (new Order)->flushCache();

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertSame(10.0, $this->totalFor($rows, 'Lamps'));
    }

    /**
     * THE CARD ITSELF NO LONGER DIVIDES BY 100. Asserted on the view source rather than on a
     * rendered page, because the divisor is the thing that was defended twice and re-added once,
     * and a source assertion is what stops a third round.
     */
    public function test_the_card_does_not_rescale_the_figure(): void
    {
        $view = file_get_contents(base_path('resources/views/order/brand_category_chart.blade.php'));

        $this->assertStringNotContainsString('/ 100', $view,
            'the `/100` fan-out compensation is back, but the cross join it compensated for is gone, '
            . 'so every figure on both cards now reads a hundredth of the truth');
        /* ANA-TOP changed the EXPRESSION and kept the property. `?? 0` is gone deliberately:
           `total_sale` is now null for a row that earned nothing in the store's own currency, and
           `?? 0` would have printed that as 0.00 -- ranking it against rows that genuinely earned
           nothing. The view branches on null and prints "Not available" instead. What this
           assertion still guarantees is the one thing it was written for: the amount reaches
           `currency_format_with_sym` UNMODIFIED, with no divisor applied on the way. */
        $this->assertStringContainsString('currency_format_with_sym($sale->total_sale,', $view,
            'the figure is no longer printed as-is');
        $this->assertStringNotContainsString('$sale->total_sale ?? 0', $view,
            'a null total is being printed as zero again, which turns "no revenue in your '
            . 'currency" into "no revenue"');
    }

    /**
     * AND THE OLD QUERY SHAPE IS GONE FROM THE CONTROLLER, so a merge cannot quietly restore the
     * twelve cross joins while the view's divisor stays removed — which would be the worst of both.
     */
    public function test_the_cross_join_query_shape_is_gone(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/HomeController.php'));

        $this->assertStringNotContainsString('SUBSTRING_INDEX', $controller,
            'the fan-out query is back in HomeController');
        $this->assertStringContainsString('TopSellersByRevenue', $controller,
            'the controller no longer uses the corrected computation');
    }
}
