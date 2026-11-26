<?php

namespace Tests\Feature\Platform;

use App\Models\PlanCoupon;
use App\Models\PlanUserCoupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE OPERATOR DASHBOARD'S COUPON RANKING MUST NOT SERVE A CACHED AGGREGATE.
 * =================================================================================================
 *
 * THIS TEST EXISTS BECAUSE MY OWN FIRST FIX INTRODUCED THE DEFECT IT CATCHES. `handleSuperAdmin()`
 * ranked plan coupons by calling `PlanCoupon::used_coupon()` inside a `foreach` -- one `COUNT(*)`
 * per coupon, measured at 12 queries for 12 coupons. The obvious repair was
 * `PlanCoupon::query()->withCount('planUserCoupons')`, which is one query and reads correctly in
 * every test that does not write a redemption mid-request.
 *
 * It is also STALE, and the staleness is invisible without a second read:
 *
 *   - 79 of the 95 models in `app/Models/` use `Cachable`, `PlanCoupon` and `PlanUserCoupon` among
 *     them. laravel-model-caching keys a query's result under THE MODEL BEING QUERIED.
 *   - `withCount` is a correlated subquery inside a `PlanCoupon` query, so its count is cached
 *     under `plan_coupons`.
 *   - A redemption inserts into `plan_user_coupons`. That flushes `PlanUserCoupon`'s cache. It does
 *     NOT flush `PlanCoupon`'s.
 *   - So the dashboard kept naming the previous winner, at its previous count, after the lead had
 *     actually changed.
 *   - The ORIGINAL loop was accidentally immune: `used_coupon()` is
 *     `hasMany(PlanUserCoupon::class, ...)->count()`, a query on `PlanUserCoupon`, whose cache the
 *     redemption write does invalidate.
 *
 * MEASURED, on MySQL with the array store, before this test existed:
 *
 *     after 1 redemption on ALPHA        winner=ALPHA count=1
 *     after 2 MORE on BETA (Eloquent)    winner=ALPHA count=1   <- STALE
 *     used_coupon() (the OLD path)       ALPHA=1 BETA=2         <- FRESH
 *     grouped on PlanUserCoupon+toBase() coupon_id=2 count=2    <- FRESH
 *
 * The shipped ranking is therefore the third shape: grouped on `PlanUserCoupon`, the table the
 * writes go to, with `toBase()` taking it off the model cache entirely -- the same shape
 * `Plan::most_purchese_plan()` uses for the card beside it.
 *
 * WHY THIS CAN LIVE IN THE SUITE when the `GROUP BY` evidence could not: staleness needs only a
 * cache that survives between two requests in one process, and `phpunit.xml` sets
 * `CACHE_STORE=array`, which is exactly that. It needs no MySQL. The `ONLY_FULL_GROUP_BY` half is
 * in `tests/Support/Security/bin/verify-dashboard-rankings-on-mysql.php`, which cannot be a test.
 */
class PlanCouponRankingFreshnessTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        $this->makeStore($this->operator, ['default_language' => 'en']);
        (new User)->flushCache();
        Cache::forget('admin_details');
    }

    private function namedCoupon(): ?string
    {
        $response = $this->actingAs(User::find($this->operator->id))->get('/dashboard');
        $response->assertOk();

        return $response->viewData('couponName');
    }

    private function redeem(PlanCoupon $coupon, int $times): void
    {
        // Through ELOQUENT, not `DB::table()`. A raw write flushes no model cache at all, so it
        // would make this test pass against the stale implementation -- CLAUDE.md's recorded
        // reading, and the exact mistake that made the first MySQL probe report two defects that
        // did not exist.
        for ($i = 0; $i < $times; $i++) {
            PlanUserCoupon::forceCreate([
                'coupon_id' => (int) $coupon->id,
                'user_id' => (int) $this->operator->id,
                'order' => 'PUC-' . $coupon->id . '-' . $i . '-' . bin2hex(random_bytes(3)),
            ]);
        }
    }

    public function test_a_new_redemption_moves_the_named_coupon_on_the_next_request(): void
    {
        $alpha = PlanCoupon::forceCreate([
            'name' => 'ALPHACOUPON', 'code' => 'A1', 'discount' => 5, 'limit' => '99',
        ]);
        $beta = PlanCoupon::forceCreate([
            'name' => 'BETACOUPON', 'code' => 'B1', 'discount' => 5, 'limit' => '99',
        ]);

        $this->redeem($alpha, 1);

        // FIRST REQUEST. This is what populates the cache, and without it the second request
        // would be the only read and could not be stale. It is the premise of the whole test.
        $this->assertSame('ALPHACOUPON', $this->namedCoupon(),
            'PREMISE: the first request does not name the only redeemed coupon, so the ranking is '
            . 'broken for a reason that has nothing to do with caching.');

        // BETA takes the lead, two to one.
        $this->redeem($beta, 2);

        $this->assertSame(2, PlanUserCoupon::where('coupon_id', $beta->id)->count(),
            'PREMISE: the two further redemptions were not written.');

        $this->assertSame('BETACOUPON', $this->namedCoupon(),
            'the dashboard still names the coupon that WAS in the lead. The ranking is serving a '
            . 'cached aggregate: a count that rides inside a PlanCoupon query is cached under '
            . '`plan_coupons`, and a redemption writes `plan_user_coupons`, which does not '
            . 'invalidate it.');
    }

    /**
     * And it goes the other way: removing redemptions moves the name back.
     *
     * A read-through cache that is merely slow to warm would pass the test above on a retry. This
     * one fails for a cache that never invalidates in either direction, and it covers the deletion
     * path, which the dashboard has no write of its own for but `plan-coupon` administration does.
     */
    public function test_removing_redemptions_moves_the_named_coupon_back(): void
    {
        $alpha = PlanCoupon::forceCreate([
            'name' => 'ALPHACOUPON', 'code' => 'A1', 'discount' => 5, 'limit' => '99',
        ]);
        $beta = PlanCoupon::forceCreate([
            'name' => 'BETACOUPON', 'code' => 'B1', 'discount' => 5, 'limit' => '99',
        ]);

        $this->redeem($alpha, 1);
        $this->redeem($beta, 2);

        $this->assertSame('BETACOUPON', $this->namedCoupon(), 'PREMISE: BETA is not in the lead.');

        foreach (PlanUserCoupon::where('coupon_id', $beta->id)->get() as $row) {
            $row->delete();
        }

        $this->assertSame('ALPHACOUPON', $this->namedCoupon(),
            'the dashboard still names a coupon whose redemptions are all gone');
    }

    /** The last redemption going away empties the card rather than naming a zero-use coupon. */
    public function test_deleting_every_redemption_empties_the_card(): void
    {
        $alpha = PlanCoupon::forceCreate([
            'name' => 'ALPHACOUPON', 'code' => 'A1', 'discount' => 5, 'limit' => '99',
        ]);

        $this->redeem($alpha, 2);
        $this->assertSame('ALPHACOUPON', $this->namedCoupon(), 'PREMISE: ALPHA is not named.');

        foreach (PlanUserCoupon::all() as $row) {
            $row->delete();
        }

        $this->assertSame('', $this->namedCoupon(),
            'a coupon with no redemptions left is still being named');
    }
}
