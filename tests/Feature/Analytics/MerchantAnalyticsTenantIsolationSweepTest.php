<?php

namespace Tests\Feature\Analytics;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Shetabit\Visitor\Models\Visit;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P05-06 / P05-19 — NO FIGURE ON `/analytics` MAY MOVE BECAUSE ANOTHER TENANT TRADED.
 * =============================================================================================
 *
 * `MerchantAnalyticsFigures` reads `orders` and the visit log directly for half of its screen, and
 * neither is in `config/tenancy.php`'s `scoped_models`: no global scope adds a store predicate for
 * anybody. The class writes eight of them by hand. Each one is the ONLY thing between a merchant
 * and another tenant's rows.
 *
 * THREE OF THE EIGHT WERE HELD BY NO TEST. Measured on 2026-11-22 by a reviewer instructed to
 * refute the claim that this dashboard was done: with all three `where('store_id', $storeId)`
 * lines removed from `customers()` — the Buyers tile — every one of the 28 test files that mention
 * analytics still passed, 327 tests. The predicates were present and correct; nothing would have
 * said so the day one was dropped.
 *
 * WHY A SWEEP AND NOT ONE CASE PER TILE. A case per tile covers the tiles somebody thought of. This
 * computes the whole figure set for one store, then lets a second tenant write every kind of row
 * this class reads — inside the window and before it — and requires the set to be IDENTICAL. A
 * figure added next month is covered the day it is added, and a regression names the key that
 * moved.
 *
 * THE FIXTURE IS BUILT SO THAT EACH PREDICATE MATTERS, which is not automatic:
 *
 *   - the "returning buyer" read asks whether one of THIS store's buyers ordered before the
 *     window, so the other tenant gets an earlier order carrying THIS store's buyer's customer id.
 *     Without that row, dropping the predicate changes nothing and the test could not see it;
 *   - the coverage start is the store's FIRST visit, so the other tenant gets an older one;
 *   - the other tenant's rows use devices, referrers, channels and campaign tags this store has
 *     none of, so a leak adds ROWS to a breakdown rather than only raising a count.
 *
 * `Order` IS `Cachable`. Every order here is written through Eloquent, which flushes that cache;
 * a raw insert would leave the second read serving the first read's answers, and this test would
 * pass with every predicate deleted. It is flushed explicitly as well, and the mutation run that
 * accompanies this file is what shows the comparison is live.
 */
final class MerchantAnalyticsTenantIsolationSweepTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-20 12:00:00');
    }

    private function visit(int $storeId, int $daysAgo, ?string $device = null, ?string $referer = null, ?string $url = null): void
    {
        $visit = new Visit();
        $visit->timestamps = false;
        $visit->store_id = $storeId;
        $visit->device = $device;
        $visit->referer = $referer;
        $visit->url = $url;
        $visit->created_at = $this->now()->subDays($daysAgo);
        $visit->updated_at = $this->now()->subDays($daysAgo);
        $visit->save();
    }

    private function customerIn(int $storeId): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Nour', 'last_name' => 'Saleh',
            'email' => 'sweep-' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeId,
        ]);
    }

    private function order($store, int $daysAgo, array $extra = []): Order
    {
        return $this->makeOrder($store, array_merge(['order_date' => $this->now()->subDays($daysAgo)], $extra));
    }

    private function figures($store): array
    {
        (new Order())->flushCache();

        return MerchantAnalyticsFigures::forStore($store->id, 'USD', $this->now());
    }

    public function test_no_figure_moves_when_another_tenant_trades(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        // ---- this store: a small book with one of everything the screen reads ----------------
        $myBuyer = $this->customerIn($mine->id);

        $this->order($mine, 5, ['customer_id' => $myBuyer->id, 'is_guest' => 0, 'order_source' => Order::SOURCE_STOREFRONT]);
        $this->order($mine, 6, ['order_source' => Order::SOURCE_STOREFRONT]);                       // a guest order
        $this->visit($mine->id, 3, 'Windows', 'https://mine.example/page', 'https://shop.test/?utm_source=mine&utm_campaign=ours');
        $this->visit($mine->id, 40);                                                               // this store's first visit

        $before = $this->figures($mine);

        // PREMISES: the store's own book is non-trivial, or "nothing moved" is trivially true.
        $this->assertStringContainsString('1 new', $this->plain($before['customers']['value']),
            'PREMISE: this store\'s buyer is not reported as new, so the returning-buyer predicate has nothing to protect.');
        $this->assertNotSame([], $before['devices']['rows'], 'PREMISE: this store has no device row.');
        $this->assertNotSame([], $before['sources']['rows'], 'PREMISE: this store has no channel row.');

        // ---- another tenant: every kind of row this class reads -------------------------------
        $theirBuyer = $this->customerIn($theirs->id);

        $this->order($theirs, 4, ['customer_id' => $theirBuyer->id, 'is_guest' => 0, 'order_source' => 'pos']);
        $this->order($theirs, 7, ['order_source' => 'whatsapp']);                                   // their guest order
        $this->order($theirs, 2, ['order_source' => 'pos']);
        // An EARLIER order, in THEIR store, carrying MY buyer's customer id. Dropping the store
        // predicate from the "ordered here before?" read turns my new buyer into a returning one.
        $this->order($theirs, 200, ['customer_id' => $myBuyer->id, 'is_guest' => 0]);

        $this->visit($theirs->id, 1, 'Macintosh', 'https://theirs.example/landing', 'https://rival.test/?utm_source=rival&utm_campaign=theirs');
        $this->visit($theirs->id, 2, 'iPhone', 'https://theirs.example/other');
        $this->visit($theirs->id, 900);                                                            // far older than my first visit

        // A paid order of theirs, so the ledger-built tiles are in the sweep as well.
        $paid = $this->order($theirs, 3, ['final_price' => 777.00]);
        DB::table('store_order_financials')->where('order_id', $paid->id)
            ->update(['payment_status' => 'paid', 'paid_amount' => 777.00, 'remaining_amount' => 0.0, 'paid_at' => $this->now()->subDays(3)]);

        $after = $this->figures($mine);

        // PREMISE: the other tenant's book is really there, read the same way.
        $theirOwn = $this->figures($theirs);
        $this->assertNotSame($before['orders']['value'], $theirOwn['orders']['value'],
            'PREMISE: the other tenant\'s figures equal this store\'s, so a leak could not be told from a coincidence.');

        foreach (array_keys($before + $after) as $key) {
            $this->assertSame(
                json_encode($before[$key] ?? null, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                json_encode($after[$key] ?? null, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                "The '{$key}' figure on this store's analytics screen changed when ANOTHER tenant "
                . 'placed orders and logged visits. Nothing scopes `orders` or the visit log for '
                . 'anybody, so a store predicate was dropped or a new read was added without one.'
            );
        }
    }

    private function plain(?string $value): string
    {
        return str_replace(["\u{2066}", "\u{2069}"], '', (string) $value);
    }
}
