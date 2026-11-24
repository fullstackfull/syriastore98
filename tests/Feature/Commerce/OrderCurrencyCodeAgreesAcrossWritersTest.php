<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * P5-R14 — AN ORDER'S CURRENCY CODE WAS ITS CURRENCY SYMBOL, ON TWO OF THE ORDER WRITERS.
 * =============================================================================================
 *
 * `orders.currency_code` is what every per-currency money figure groups by. FIVE places stamp it:
 * `Order`'s own `creating` hook, which every gateway and point-of-sale writer leaves it to; the
 * merchant panel's manual order; the admin API's; and the two storefront-API writers,
 * `Api\ApiController::place_order()` and `::ordersave()`. The first three read the store's
 * `defult_currancy` — the CODE. The two storefront writers read a setting named `CURRENCY`, whose
 * shipped default in `Utility::Setting()` is `'$'`. It is the SYMBOL. (An earlier draft of this
 * docblock said "four places … three … the two", which is five.)
 *
 * MEASURED 2026-11-22, by placing an order through each path against one store:
 *
 *     store's settings                          place-order   order-save   model hook
 *     nothing set                               $             $            USD
 *     defult_currancy = SYP                     $             $            SYP
 *     defult_currancy = SYP, CURRENCY = ل.س      ل.س            ل.س           SYP
 *
 * So a store trading in Syrian pounds had its API-placed orders recorded as `$`. That is not a
 * formatting fault. `GrossMerchandiseValue` buckets by this column, `MetricResult::total()` REFUSES
 * a result holding more than one currency, and the operator's store ranking lists each currency
 * apart — so one store's orders were split across two currencies, and its pound amounts were filed
 * under the dollar sign beside stores that really do sell in dollars.
 *
 * The symbol was wrong on the same two writers, for the mirror-image reason: they read a setting
 * named `currency_symbol`, which nothing writes, so every such order was stamped `$` whatever the
 * store's own `defult_currancy_symbol` said.
 *
 * FOUND BY READING, CONFIRMED BY EXECUTION. It was recorded as a lead while repairing the
 * operator's per-currency ranking (`GAP-162`), because that ranking is only as trustworthy as this
 * column; the table above is what turned the lead into a defect.
 *
 * WHAT THIS DOES NOT TOUCH: orders already written. Rewriting a recorded currency is a change to
 * historical financial data and is the owner's decision (`D-N34`), not a side effect of a fix.
 *
 * THE FIXTURE is `StorefrontOrderPlacementAuthorizationTest`'s, which is the authority for why each
 * step exists — a plan, `stock_management`, a shipping row, and `admin_details` forgotten. Without
 * all of them these handlers answer 500 before an order is written and every assertion below would
 * be measuring a crash.
 */
class OrderCurrencyCodeAgreesAcrossWritersTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: int} */
    private function orderableTenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'test-plan-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Through Eloquent: `User` is Cachable, and a raw write leaves the handler reading plan_id = null.
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        $this->setting($merchant, $store, 'stock_management', 'on');

        $shippingId = DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant->fresh(), $store, $shippingId];
    }

    private function setting($merchant, $store, string $name, string $value): void
    {
        DB::table('settings')->insert([
            'name' => $name, 'value' => $value, 'created_by' => $merchant->id,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\Setting)->flushCache();
    }

    /** Places one order through a storefront-API writer and returns the row it wrote. */
    private function placeThrough(string $path, $store, int $shippingId): object
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'Currency',
            'email' => 'cur-' . uniqid() . '@example.test', 'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'cur-product-' . uniqid(), 'slug' => 'cur-product-' . uniqid(),
            'product_stock' => 10, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 10, 'store_id' => $store->id,
        ]);

        $address = [
            'firstname' => 'Test', 'lastname' => 'Shopper', 'email' => 'shopper@example.test',
            'billing_address' => 'Street 1', 'billing_user_telephone' => '555000999', 'billing_postecode' => '11111',
            'billing_country' => 'Testland', 'billing_state' => 'Teststate', 'billing_city' => 'Testcity',
            'delivery_address' => 'Street 1', 'delivery_postcode' => '11111',
            'delivery_country' => 'Testland', 'delivery_state' => 'Teststate', 'delivery_city' => 'Testcity',
        ];

        $this->withHeaders(['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken])
            ->postJson('/api/' . $store->slug . '/' . $path, [
                'customer_id' => $customer->id, 'billing_info' => $address, 'payment_type' => 'cod',
                'delivery_id' => $shippingId, 'method_id' => $shippingId,
            ]);

        // Asserted on the table: `place_order` answers 500 from a webhook call AFTER the order is saved.
        $order = DB::table('orders')->where('customer_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull($order,
            "PREMISE: `{$path}` wrote no order, so nothing below is measuring what that writer records.");

        return $order;
    }

    /** The reference: an order created with no currency given, stamped by `Order`'s own hook. */
    private function stampedByTheModel($store): object
    {
        $order = new Order();
        $order->product_order_id = 'HOOK-' . $this->uniqueFixtureRef();
        $order->store_id = $store->id;
        $order->customer_id = 0;
        $order->is_guest = 1;
        $order->final_price = 1;
        $order->order_date = now();
        $order->save();

        return DB::table('orders')->where('id', $order->id)->first();
    }

    /** @return array<string, array{0: string}> */
    public static function storefrontWriters(): array
    {
        return ['place_order' => ['place-order'], 'ordersave' => ['order-save']];
    }

    /**
     * @dataProvider storefrontWriters
     */
    public function test_a_storefront_order_records_the_stores_currency_code_not_its_symbol(string $path): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();
        $this->setting($merchant, $store, 'defult_currancy', 'SYP');
        // The symbol setting the handlers used to read AS the code. Distinct from every code here,
        // so the wrong column cannot produce the right answer.
        $this->setting($merchant, $store, 'CURRENCY', 'ل.س');

        $order = $this->placeThrough($path, $store, $shippingId);

        $this->assertSame('SYP', $order->currency_code,
            "`{$path}` recorded '{$order->currency_code}' as this order's currency CODE. The store "
            . 'trades in SYP; every per-currency figure groups by this column, so the order is '
            . 'filed under a currency it is not denominated in.');
    }

    /**
     * @dataProvider storefrontWriters
     */
    public function test_a_store_with_no_currency_setting_records_the_same_default_as_every_other_writer(string $path): void
    {
        [, $store, $shippingId] = $this->orderableTenant();

        $reference = $this->stampedByTheModel($store);
        $this->assertSame('USD', $reference->currency_code,
            'PREMISE: the model no longer defaults an unset currency to USD, so this test is comparing against a moved target.');

        $order = $this->placeThrough($path, $store, $shippingId);

        $this->assertSame($reference->currency_code, $order->currency_code,
            "`{$path}` and the model disagree about the currency of a store that has set none: "
            . "'{$order->currency_code}' against '{$reference->currency_code}'. One store's orders "
            . 'then sit in two currency buckets.');
    }

    /**
     * THE SYMBOL, WHICH HAD THE SAME FAULT IN MIRROR IMAGE. These writers read a setting called
     * `currency_symbol`, which nothing writes, so the store's own symbol never reached the order.
     *
     * @dataProvider storefrontWriters
     */
    public function test_a_storefront_order_records_the_stores_own_currency_symbol(string $path): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();
        $this->setting($merchant, $store, 'defult_currancy', 'SYP');
        $this->setting($merchant, $store, 'defult_currancy_symbol', 'SP');

        $order = $this->placeThrough($path, $store, $shippingId);

        $this->assertSame('SP', $order->currency_symbol,
            "`{$path}` recorded '{$order->currency_symbol}' as this order's currency symbol; the "
            . "store's own is 'SP'.");
    }

    /**
     * ONE STORE, EVERY WRITER, ONE ANSWER — which is the property the money figures depend on.
     * The model's hook is the reference because it is what stamps every order no handler stamps.
     */
    public function test_every_writer_stamps_one_store_with_one_currency(): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();
        $this->setting($merchant, $store, 'defult_currancy', 'EUR');
        $this->setting($merchant, $store, 'defult_currancy_symbol', 'E');
        $this->setting($merchant, $store, 'CURRENCY', '€');

        $stamps = [
            'model' => $this->stampedByTheModel($store),
            'place_order' => $this->placeThrough('place-order', $store, $shippingId),
            'ordersave' => $this->placeThrough('order-save', $store, $shippingId),
        ];

        $this->assertSame(
            ['model' => ['EUR', 'E'], 'place_order' => ['EUR', 'E'], 'ordersave' => ['EUR', 'E']],
            array_map(static fn (object $o): array => [$o->currency_code, $o->currency_symbol], $stamps),
            'The order writers do not agree about one store\'s currency.'
        );

        // And what that agreement is FOR: the store's order value is one figure, not a refusal.
        $buckets = DB::table('orders')->where('store_id', $store->id)->distinct()->pluck('currency_code')->all();

        $this->assertSame(['EUR'], $buckets,
            'One store\'s orders carry more than one currency code, so its order value cannot be totalled.');
    }

    /**
     * NO OTHER WRITER READS THE SYMBOL SETTING AS A CODE. The defect was two lines with the same
     * shape, 5,600 lines apart in one file; nothing stops a third. `CURRENCY` is the symbol
     * (`Utility::Setting()` ships it as `$`), so assigning from it to anything named a code is the
     * fault, wherever it appears.
     */
    public function test_no_first_party_code_assigns_the_symbol_setting_to_a_currency_code(): void
    {
        $this->assertSame([], $this->symbolReadAsACode(app_path()),
            'A currency CODE is being read from the `CURRENCY` setting, which holds the SYMBOL.');
    }

    /** The same rule over the vendored tree, which has its own order writers. */
    public function test_no_vendored_module_assigns_the_symbol_setting_to_a_currency_code(): void
    {
        $this->assertSame([], $this->symbolReadAsACode(base_path('packages')),
            'A vendored module reads a currency CODE from the `CURRENCY` setting, which holds the SYMBOL.');
    }

    /**
     * EVERY SHAPE OF THE SAME FAULT, not the one line that was reverted.
     *
     * The first version of this census matched `currency_code<...> = <...>GetValueByName('CURRENCY'`
     * on ONE line. An independent review listed what that misses, and every one of them is a shape
     * this codebase already writes somewhere:
     *
     *   - the array-key form `'currency_code' => ...`, which is how three of the five writers do it;
     *   - a multi-line assignment, which is the shape the model hook itself had;
     *   - an intermediate variable — `$CURRENCY = GetValueByName('CURRENCY', ...)` already exists
     *     inside `ordersave`, so `$order->currency_code = $CURRENCY;` passed;
     *   - the other readers of the same setting: `GetValByName`, `$settings['CURRENCY']`, `GetCurrency()`;
     *   - the MIRROR fault on the symbol — a symbol read from a CODE setting — which was not
     *     censused at all;
     *   - anything outside `app/`.
     *
     * So this reads the file as one string with comments stripped (`StripsSourceComments`, because
     * a block comment quoting the old line would otherwise fail the census — CLAUDE.md records that
     * trap in three syntaxes), finds every currency assignment, and asks which SETTING NAME the
     * expression it is assigned ends up reading, following one level of local variable.
     *
     * @return list<string> "path: line" for each site, so a failure names them
     */
    private function symbolReadAsACode(string $root): array
    {
        // Which of the currency settings is a code and which is a symbol. `CURRENCY` is the symbol
        // (`Utility::Setting()` ships it as `$`); `CURRENCY_NAME` and `defult_currancy` are codes.
        $symbolSettings = ['CURRENCY', 'defult_currancy_symbol', 'currency_symbol'];
        $codeSettings = ['CURRENCY_NAME', 'defult_currancy', 'currency'];

        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            /*
             * A BLADE TEMPLATE IS NOT PHP SOURCE and is not an order writer. `sourceWithoutComments()`
             * runs PHP's tokeniser, which returns nothing for a file that is mostly markup — and its
             * own guard, correctly, treats "the stripper ate the file" as a failure rather than as an
             * empty result. Measured: `LandingPage`'s package-details view.
             */
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // An EMPTY file -- `BulkDelete`'s `routes/api.php` is zero bytes -- is nothing to read,
            // and the stripper's guard would read it as "the stripper ate the file".
            if (trim((string) file_get_contents($file->getPathname())) === '') {
                continue;
            }

            $path = str_replace(base_path() . '/', '', $file->getPathname());

            if (str_contains($path, '/tests/') || str_starts_with($path, 'tests/')) {
                continue;
            }

            $source = $this->sourceWithoutComments($file->getPathname());

            /*
             * One level of local variable: `$x = ...GetValueByName('NAME'...` makes `$x` that name.
             *
             * EVERY ASSIGNMENT, NOT THE LAST. One name can be assigned twice in a file --
             * `Api\DashboardController` assigns `$currentCurrencyCode` in two different handlers --
             * and keeping one value per name meant the later assignment hid the earlier. MEASURED:
             * a mutation that put the symbol setting back in the FIRST of those two handlers
             * survived this census, because the second one still read a code. Collecting every
             * name a variable is assigned from over-reports where one name is reused for two
             * purposes, which is the safe direction for a census and has no site today.
             */
            $named = [];

            if (preg_match_all('/\$(\w+)\s*=\s*([^;]{0,400}?(?:GetValueByName|GetValByName)\s*\(\s*[\'"](\w+)[\'"][^;]{0,200});/s', $source, $vars, PREG_SET_ORDER)) {
                foreach ($vars as $var) {
                    $named['$' . $var[1]][] = $var[3];
                }
            }

            // Every assignment to a currency column, in either syntax, however many lines it spans.
            $pattern = '/(?:[\'"]?(currency_code|currency_symbol)[\'"]?\s*(?:=>|=)\s*)([^;,\n]{0,400}(?:\n[^;,]{0,200}){0,3})/i';

            if (! preg_match_all($pattern, $source, $writes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($writes as $write) {
                $column = strtolower($write[1][0]);
                $expression = $write[2][0];
                $line = substr_count(substr($source, 0, (int) $write[0][1]), "\n") + 1;

                // Which setting does the expression read -- directly, or through a local variable?
                $reads = [];

                if (preg_match_all('/(?:GetValueByName|GetValByName)\s*\(\s*[\'"](\w+)[\'"]/', $expression, $direct)) {
                    $reads = $direct[1];
                }

                if (preg_match_all('/\[\s*[\'"](\w+)[\'"]\s*\]/', $expression, $arrayRead)) {
                    foreach ($arrayRead[1] as $name) {
                        if (in_array($name, array_merge($symbolSettings, $codeSettings), true)) {
                            $reads[] = $name;
                        }
                    }
                }

                foreach ($named as $variable => $names) {
                    /*
                     * ON A WORD BOUNDARY, because `str_contains()` is not one. The first version of
                     * this census reported `PaymentService::processPaypal()` as a fault: that method
                     * holds both `$CURRENCY` and `$CURRENCY_NAME`, and the PayPal payload's own
                     * `"currency_code" => $CURRENCY_NAME` matched the `$CURRENCY` variable by
                     * substring. It is correct code reading a code setting, and the field is
                     * PayPal's, not this product's column.
                     */
                    if (preg_match('/' . preg_quote($variable, '/') . '\b/', $expression) === 1) {
                        $reads = array_merge($reads, $names);
                    }
                }

                foreach ($reads as $name) {
                    $wrong = $column === 'currency_code'
                        ? in_array($name, $symbolSettings, true)
                        : in_array($name, $codeSettings, true);

                    if ($wrong) {
                        $offenders[] = $path . ':' . $line . ' (' . $column . ' <- ' . $name . ')';
                    }
                }
            }
        }

        sort($offenders);

        return array_values(array_unique($offenders));
    }

    /**
     * THE CENSUS CATCHES EACH SHAPE, shown on text rather than inferred. Without this the regex
     * above could be subtly wrong in the direction that reports nothing, which is the one way a
     * census fails silently.
     *
     * @dataProvider shapesOfTheSameFault
     */
    public function test_the_census_sees_every_shape_of_the_fault(string $label, string $code, bool $isAFault): void
    {
        $directory = sys_get_temp_dir() . '/currency-census-' . bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory . '/Probe.php', "<?php
" . $code . "
");

        try {
            $found = $this->symbolReadAsACode($directory);
        } finally {
            @unlink($directory . '/Probe.php');
            @rmdir($directory);
        }

        $this->assertSame($isAFault, $found !== [],
            $isAFault
                ? "The census does not see the fault written as: {$label}"
                : "The census reports correct code as a fault: {$label}");
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function shapesOfTheSameFault(): array
    {
        return [
            'direct assignment' => ['direct assignment', '$o->currency_code = Utility::GetValueByName(\'CURRENCY\', $id);', true],
            'array key' => ['array key', '$x = [\'currency_code\' => Utility::GetValueByName(\'CURRENCY\', $id)];', true],
            'across lines' => ['across lines', "\$o->currency_code = Utility::GetValueByName(\n    'CURRENCY',\n    \$id\n);", true],
            'through a variable' => ['through a variable', "\$c = Utility::GetValueByName('CURRENCY', \$id);\n\$o->currency_code = \$c;", true],
            'the other reader' => ['the other reader', '$o->currency_code = Utility::GetValByName(\'CURRENCY\', $id);', true],
            'a settings array' => ['a settings array', '$o->currency_code = $settings[\'CURRENCY\'];', true],
            'the mirror fault' => ['the mirror fault', '$o->currency_symbol = Utility::GetValueByName(\'defult_currancy\', $id);', true],
            'in a block comment' => ['in a block comment', "/*\n   \$o->currency_code = Utility::GetValueByName('CURRENCY', \$id);\n*/\n\$x = 1;", false],
            'the correct code' => ['the correct code', '$o->currency_code = Utility::GetValueByName(\'defult_currancy\', $id);', false],
            'the correct symbol' => ['the correct symbol', '$o->currency_symbol = Utility::GetValueByName(\'defult_currancy_symbol\', $id);', false],
            // The false positive the first version of this census produced: two variables, one a
            // prefix of the other, and the correct one assigned.
            'a longer variable name' => ['a longer variable name', "\$CURRENCY = Utility::GetValueByName('CURRENCY', \$id);\n\$CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME', \$id);\n\$x = ['currency_code' => \$CURRENCY_NAME];", false],
            // The shape that survived this census until 2026-11-22: ONE name, assigned twice in a
            // file -- once wrongly -- where the correct assignment came second.
            'one name assigned twice' => ['one name assigned twice', "function a(\$id) { \$c = Utility::GetValueByName('CURRENCY', \$id); return ['currency_code' => \$c]; }\nfunction b(\$id) { \$c = Utility::GetValueByName('defult_currancy', \$id); return ['currency_code' => \$c]; }", true],
        ];
    }
}
