<?php

namespace Tests\Feature\Security;

use App\Listeners\EnforceLotteryTicketPrice;
use App\Models\AddOnManager;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-21 / #68 item 1 -- the recorded ticket price is a client-posted hidden input while the
 * CHARGE is server-side.
 *
 * THE DEFECT, measured against unfixed code: a two-request flow posting `ticket_price = 1` on a
 * lottery whose `ticket_settings.ticket_price` is 25 stored `amount = 1`.
 *
 * WHY THIS NEEDS ITS OWN FILE RATHER THAN A CASE IN LotteryTicketWriterTest: the control is a MODEL
 * EVENT, and every assertion in that file reads or writes through `DB::table()`. A `DB::table()`
 * fixture BYPASSES model events entirely, so no existing fixture can exercise this and a test built
 * from one would pass against unfixed code.
 *
 * THREE PREMISE TESTS, each guarding a failure mode this repository has already produced:
 *
 *   1. the route is registered and carries no `auth`      -- else "no forged row" is vacuous
 *   2. the listener is registered under the name the MODEL reports -- the string key makes the
 *      vendored FQCN load-bearing, and a silent mismatch makes the whole fix inert
 *   3. a probe listener fires on a bare `(new PurchaseTicket)->save()` -- catches an upstream switch
 *      to a query-builder insert, which would bypass model events without changing any line this
 *      test reads
 *
 * THE REMOVAL CHECKS, AS MEASURED (scripted; each control deleted or weakened ALONE, suite re-run,
 * file restored byte-for-byte). 7 of 8 isolate:
 *
 *   R1 the Event::listen registration ......... 4 fail
 *   R2 the amount rewrite ..................... 4 fail
 *   R3 the event-name constant ................ 4 fail   <- one character in the FQCN
 *   R4 the lottery_id key on the lookup ....... 1 fail
 *   R5 usableId strictness (filter_var) ....... 1 fail   <- needed a value PHP and the column read
 *                                                          differently, WITH a real lottery at the
 *                                                          coerced id; the other unresolvable ids
 *                                                          behave identically under both predicates
 *   R6 the null-price guard ................... 1 fail
 *   R8 the `void` return ...................... 6 fail   <- `until()` HALTS on a non-null return,
 *                                                          so `?bool` lets a listener abort the
 *                                                          save while the controller discards it
 *   R7 the unresolvable-id early return ....... GREEN -- SUBSUMED by R6, measured: with the early
 *                                              return gone, `->where('id', null)` matches no row,
 *                                              `$price` is null, and R6 returns. The early return
 *                                              is kept because it states the intent and avoids a
 *                                              pointless query, not because it is load-bearing.
 */
class LotteryTicketPriceIntegrityTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const MODEL = 'Workdo\LotteryPrizes\app\Models\PurchaseTicket';

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'LotteryPrizes',
            'name' => 'Lottery Prizes',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'lottery-prizes',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['LotteryPrizes']);

        foreach (glob(base_path('packages/workdo/LotteryPrizes/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        foreach (['purchase_tickets', 'lottery_settings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Module table `{$table}` was not created.");
        }
    }

    /**
     * @return array{0: Store, 1: int, 2: int}  store, product id, lottery id
     */
    private function lotteryAt(string $slug, float $price): array
    {
        $owner = $this->makeMerchant();
        $store = $this->makeStore($owner, ['slug' => $slug]);
        $owner->current_store = $store->id;
        $owner->save();

        $productId = (int) DB::table('products')->insertGetId([
            'name' => 'Lottery ' . $slug,
            'slug' => 'lottery-' . $slug,
            'store_id' => $store->id,
            'created_by' => $owner->id,
            'price' => $price,
            'track_stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lotteryId = (int) DB::table('lottery_settings')->insertGetId([
            'store_id' => $store->id,
            'lottery_products' => $productId,
            'ticket_price' => $price,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$store, $productId, $lotteryId];
    }

    private function formFields(int $productId, mixed $postedPrice): array
    {
        return [
            'product_id' => $productId,
            'payment_methods' => 'telegram',
            'ticket_price' => $postedPrice,
            'lottery_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'lottery_user_telephone' => '1', 'lottery_address' => 'x',
                'lottery_country' => 1, 'lottery_state' => 1, 'lottery_city' => 1,
                'lottery_postecode' => '1',
            ],
        ];
    }

    /* ---------------------------------------------------------------------------------------- *
     *  PREMISES
     * ---------------------------------------------------------------------------------------- */

    public function test_premise_the_flow_routes_are_registered_and_carry_no_auth(): void
    {
        $this->enableModule();

        foreach (['lottery-form.data.store', 'store.lottery.payment'] as $name) {
            $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === $name);
            $this->assertNotNull($route, "{$name} is not registered, so every probe below is vacuous.");
            $this->assertNotContains('auth', array_map('strval', $route->gatherMiddleware()));
        }
    }

    /**
     * THE STRING KEY MUST MATCH WHAT THE MODEL REPORTS.
     *
     * `fireModelEvent` builds the event name as `'eloquent.creating: ' . static::class`, and
     * `static::class` carries no leading backslash. A listener registered under a name that differs
     * by one character is never called, and nothing else in the suite would notice -- the fix would
     * be silently inert while every other test still passed.
     */
    public function test_premise_the_listener_is_registered_under_the_name_the_model_reports(): void
    {
        $this->enableModule();

        $this->assertTrue(class_exists(self::MODEL),
            'The module did not load, so the name below cannot be checked against the real class.');

        $expected = 'eloquent.creating: ' . self::MODEL;

        $this->assertSame($expected, EnforceLotteryTicketPrice::EVENT,
            'The listener constant does not match `eloquent.creating: ` . the model FQCN. The '
            . 'string key is the whole registration, so a mismatch makes the fix inert.');

        $this->assertTrue(Event::hasListeners($expected),
            "Nothing is listening on `{$expected}`. AppServiceProvider::boot registers it; if that "
            . 'registration moved or the FQCN changed, the price rewrite does not run at all.');
    }

    /**
     * A BARE `->save()` ON THE MODEL FIRES `creating`.
     *
     * This is the premise the whole mechanism rests on, and it is asserted independently of the
     * HTTP flow so that an upstream switch to `::insert()` or `DB::table()` -- which would bypass
     * model events without touching any line the other tests read -- fails here with a clear
     * reason rather than silently reopening the defect elsewhere.
     *
     * It also demonstrates that `Cachable` does not suppress the event: `PurchaseTicket` uses it,
     * and `bootCachable()` adds listeners on `created`/`deleted`/`saved` only.
     */
    public function test_premise_a_bare_save_on_the_module_model_fires_creating(): void
    {
        $this->enableModule();

        $fired = 0;
        Event::listen('eloquent.creating: ' . self::MODEL, function () use (&$fired) {
            $fired++;
        });

        $class = self::MODEL;
        $model = new $class();
        $model->lottery_id = 1;
        $model->lottery_code = 'PREMISE1';
        $model->amount = 7;
        $model->store_id = 1;
        $model->save();

        $this->assertSame(1, $fired,
            'A bare ->save() on the module model did not fire `creating`. Either the model no '
            . 'longer extends Eloquent, or a trait now intercepts persistence -- either way the '
            . 'price rewrite is inert and the defect is reopened.');
    }

    /* ---------------------------------------------------------------------------------------- *
     *  THE DEFECT
     * ---------------------------------------------------------------------------------------- */

    /**
     * THE HEADLINE. A forged hidden `ticket_price` does not reach `purchase_tickets.amount`.
     *
     * Driven through the REAL two-request flow, not a fixture: the first request populates
     * `lottery_request_data` in the session (`processTelegram` is `Session::put` + a view), the
     * second reaches `StoreLotteryPayment`, which reads the amount from that session at `:571` and
     * assigns it at `:614`.
     */
    public function test_a_forged_hidden_price_does_not_reach_the_stored_amount(): void
    {
        $this->enableModule();

        [, $productId, ] = $this->lotteryAt('price-store', 25.0);

        $post = $this->post('/price-store/lottery-form-data', $this->formFields($productId, 1));

        $this->assertNotNull(session('lottery_request_data'),
            'The first request did not populate the session, so the second proves nothing. Status '
            . $post->getStatusCode());

        $this->get('/price-store/store-lottery-payment');

        $row = DB::table('purchase_tickets')->orderByDesc('id')->first();

        $this->assertNotNull($row,
            'No ticket row was written, so this test cannot tell a correct amount from a refused '
            . 'request. The flow is expected to complete.');

        fwrite(STDERR, "\nPOSTED ticket_price=1, lottery ticket_price=25 -> stored amount="
            . var_export($row->amount, true) . "\n");

        $this->assertSame(25.0, (float) $row->amount,
            "The stored amount is the caller's posted value, not the lottery's own ticket_price.");
    }

    /**
     * AND AN HONEST PRICE IS UNCHANGED, so the listener is a correction and not a constant.
     */
    public function test_an_honest_price_is_stored_unchanged(): void
    {
        $this->enableModule();

        [, $productId, ] = $this->lotteryAt('honest-store', 25.0);

        $this->post('/honest-store/lottery-form-data', $this->formFields($productId, 25));
        $this->get('/honest-store/store-lottery-payment');

        $row = DB::table('purchase_tickets')->orderByDesc('id')->first();

        $this->assertNotNull($row);
        $this->assertSame(25.0, (float) $row->amount);
    }

    /**
     * THE AMOUNT COMES FROM THE TICKET'S OWN LOTTERY, not from whichever lottery happens to exist.
     *
     * Two stores, two lotteries, two prices. A naive listener that read "the" lottery, or that
     * keyed on anything the caller controls, would pass the test above and fail here.
     */
    public function test_the_amount_comes_from_the_tickets_own_lottery(): void
    {
        $this->enableModule();

        [, $productA, $lotteryA] = $this->lotteryAt('two-a', 10.0);
        [, $productB, $lotteryB] = $this->lotteryAt('two-b', 90.0);

        $this->assertNotSame($lotteryA, $lotteryB);

        $this->post('/two-b/lottery-form-data', $this->formFields($productB, 1));
        $this->get('/two-b/store-lottery-payment');

        $row = DB::table('purchase_tickets')->orderByDesc('id')->first();

        $this->assertNotNull($row);
        $this->assertSame((int) $lotteryB, (int) $row->lottery_id,
            'The ticket is not against the lottery this test drove.');
        $this->assertSame(90.0, (float) $row->amount,
            "The stored amount is not store B's own ticket_price.");
    }

    /**
     * AN UNRESOLVABLE lottery_id LEAVES THE ROW ALONE rather than zeroing it or refusing.
     *
     * Unreachable through the HTTP handler -- `:612` assigns `$lottery_setting->id` and throws on a
     * null lottery, five lines before the save -- so this is driven on the model directly, which is
     * the only way a caller can arrive here without a usable lottery. The conservative behaviour is
     * asserted because the alternatives are both worse: writing 0 corrupts a row the listener does
     * not understand, and returning `false` from `creating` produces a SILENT FALSE SUCCESS (the
     * controller discards `save()`'s return value at `:617` and renders a receipt with a blank
     * date).
     */
    public function test_an_unresolvable_lottery_id_leaves_the_amount_untouched(): void
    {
        $this->enableModule();

        $class = self::MODEL;

        foreach ([null, '', 0, -1, 'abc', 999999] as $lotteryId) {
            $model = new $class();
            $model->lottery_id = $lotteryId;
            $model->lottery_code = 'UNRES' . uniqid();
            $model->amount = 4242;
            $model->store_id = 1;
            $model->save();

            $stored = DB::table('purchase_tickets')
                ->where('lottery_code', $model->lottery_code)->value('amount');

            $this->assertSame(4242.0, (float) $stored,
                'With lottery_id ' . var_export($lotteryId, true) . ' the listener altered the '
                . 'amount. It cannot resolve a price, so it must leave the row as built.');
        }
    }

    /**
     * A lottery_id THAT PHP AND MySQL WOULD READ DIFFERENTLY RESOLVES NO PRICE.
     *
     * `purchase_tickets.lottery_id` is a **STRING** column
     * (`2024_10_24_063457_create_purchase_tickets_table.php:17`), so a value like `'1.5'` is stored
     * VERBATIM -- it is not coerced on the way in. A listener using `is_numeric` would cast it to
     * `1`, resolve lottery 1, and stamp THAT lottery's price onto a ticket whose own `lottery_id`
     * reads `'1.5'`: an amount and a lottery that disagree, in a row nothing can later reconcile.
     *
     * `filter_var(FILTER_VALIDATE_INT)` refuses it, so no price is resolved and the row is left as
     * built. This test exists because a removal check showed the strictness was unprotected: the
     * other unresolvable-id cases behave identically under both predicates, so only a value where
     * they DIFFER can isolate it -- and it needs a REAL lottery at that id to differ at all.
     */
    public function test_a_lottery_id_php_and_mysql_read_differently_resolves_no_price(): void
    {
        $this->enableModule();

        [, , $lotteryId] = $this->lotteryAt('coerce-store', 25.0);

        // PREMISE: the integer part must name a REAL lottery, or both predicates fail to resolve a
        // price and the test cannot tell them apart.
        $this->assertSame(1, (int) $lotteryId,
            'This test needs the seeded lottery to be id 1 so that "1.5" has something to coerce '
            . 'to. The fixture created id ' . $lotteryId . '.');

        $class = self::MODEL;

        foreach (['1.5', '1.0', ' 1', '+1', '1abc'] as $posted) {
            $model = new $class();
            $model->lottery_id = $posted;
            $model->lottery_code = 'COERCE' . uniqid();
            $model->amount = 4242;
            $model->store_id = 1;
            $model->save();

            $row = DB::table('purchase_tickets')
                ->where('lottery_code', $model->lottery_code)->first();

            // WHAT WAS ACTUALLY STORED IN lottery_id decides what the amount is allowed to be.
            // `' 1'` and `'+1'` ARE accepted by filter_var and denote lottery 1, so the price
            // SHOULD be rewritten for those -- the rule is that the amount must agree with the
            // lottery the row names, not that every odd string is refused.
            $resolves = filter_var($posted, FILTER_VALIDATE_INT) === 1;

            $this->assertSame(
                $resolves ? 25.0 : 4242.0,
                (float) $row->amount,
                'With lottery_id ' . var_export($posted, true) . ' stored as '
                . var_export($row->lottery_id, true) . ' the amount is '
                . var_export($row->amount, true) . '. A value PHP would coerce to 1 but which is '
                . 'not an integer must NOT pick up lottery 1\'s price, or the row\'s amount and '
                . 'its lottery disagree.'
            );
        }
    }

    /**
     * THE LISTENER DOES NOT TOUCH store_id, AND THAT IS DELIBERATE RATHER THAN AN OMISSION.
     *
     * Re-deriving `store_id` from the lottery looked like a second win and is a TAUTOLOGY, caught
     * by an adversarial pass before it shipped: `StoreLotteryPayment:595-597` selects the lottery
     * `where('store_id', $store->id)`, so `$lottery_setting->store_id` is identically `$store->id`
     * -- exactly what `:616` already assigns. A rewrite could never change a value.
     *
     * This test pins the absence, so nobody adds a control that cannot fail and reads as protection.
     */
    public function test_the_listener_does_not_pretend_to_rederive_the_store(): void
    {
        $source = (string) file_get_contents(base_path('app/Listeners/EnforceLotteryTicketPrice.php'));

        $this->assertStringNotContainsString("setAttribute('store_id'", $source,
            'The listener now rewrites store_id. That is a tautology: the handler selects the '
            . 'lottery BY the store it then assigns, so the derived value is always the value '
            . 'already there. A control that cannot fail is worse than none, because it reads as '
            . 'protection.');

        $this->assertStringContainsString("setAttribute('amount'", $source,
            'The listener no longer rewrites the amount, which is the only thing it exists to do.');
    }
}
