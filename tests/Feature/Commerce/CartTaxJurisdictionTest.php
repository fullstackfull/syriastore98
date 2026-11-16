<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\TaxMethod;
use App\Models\TaxOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P4-F070 — A TAX RESTRICTED TO ONE JURISDICTION IS CHARGED TO SHOPPERS OUTSIDE IT.
 * =================================================================================================
 *
 * `Cart::getProductTaxAmount()` walks a store's tax methods in `priority asc` and adds the ones
 * whose country/state/city match the shopper. After the four matching branches sits a fallback:
 *
 *     if ($tax_value == false && isset($settings['price_type']) && $settings['price_type'] != 'inclusive') {
 *         $tax_price += $tax->tax_rate * $sub_total / 100;
 *     }
 *
 * `$tax_value` is initialised ONCE before the loop and never reset, so the fallback fires for every
 * non-matching method that sorts BEFORE the first matching one — and for ALL of them when nothing
 * matches at all. The money moves the wrong way for the shopper: the tax is OVERCHARGED, and the
 * merchant collects tax their own configuration says does not apply.
 *
 * THE PRIORITY ORDER IS WHAT MAKES IT HARD TO SEE, and it is why this suite pins it. With
 * [Aleppo 3% priority 1, Damascus 5% priority 2] a Damascus shopper pays **8%**. Swap the
 * priorities and the same shopper is charged correctly at 5%. A fixture that happened to order
 * them the other way would report the code as sound.
 *
 * AND THE WIDEST CASE IS THE FIRST PAGE VIEW. `country_id`, `state_id` and `city_id` default to
 * null on a cart-list call made before any address is entered, so nothing matches and **every**
 * restricted method is charged — to every shopper, on the first view of their basket.
 *
 * THE INCLUSIVE BRANCH HAS THE SAME HOLE IN A DIFFERENT SPELLING: `if ($tax_price == 0)` re-walks
 * every method when none matched. Fixed and pinned together, because a fix to one of them alone
 * would leave the store that configures the other exactly as it was.
 *
 * THE PRECONDITION IS NARROWER THAN "EVERY STORE", and the premise case below asserts it rather
 * than assuming: nothing seeds `tax_options`, so for a store that has never saved tax settings
 * `isset($settings['price_type'])` is false and the restriction already works. The affected
 * population is stores that HAVE saved tax options with a `price_type` other than `inclusive` —
 * and, because `price_type` is validated only as `required` with no `in:` rule, that includes any
 * other string a future form sends.
 *
 * WHY THIS DRIVES THE ARITHMETIC DIRECTLY. `getProductTaxAmount()` is the whole of the
 * calculation, it is a public static with explicit arguments, and
 * `test_premise_both_cart_readers_ask_for_the_jurisdiction_rules()` asserts that both cart paths
 * call it with `$flag = true`, which is the mode under test. A full cart-list round trip would
 * exercise the same function through two more layers without testing anything extra about it.
 */
class CartTaxJurisdictionTest extends TestCase
{
    private const STORE = 77;
    private const SUBTOTAL = 100.00;
    private const TAX_GROUP = 5;

    /** Syria / Damascus / Damascus — the shopper's real jurisdiction throughout. */
    private const COUNTRY = 101;
    private const STATE = 1;
    private const CITY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        foreach (['tax_methods', 'tax_options'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('tax_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_id');
            $table->string('name')->nullable();
            $table->decimal('tax_rate', 15, 2)->default(0);
            $table->integer('priority')->default(0);
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });

        Schema::create('tax_options', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('value')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach (['tax_methods', 'tax_options'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /**
     * Written through Eloquent, because `TaxMethod` and `TaxOption` are both `Cachable` and this
     * suite runs under `CACHE_STORE=array`: a raw insert leaves the next read serving the
     * pre-insert miss for the rest of the process.
     */
    private function method(string $name, float $rate, int $priority, ?int $country, ?int $state, ?int $city): void
    {
        (new TaxMethod())->forceFill([
            'tax_id' => self::TAX_GROUP,
            'name' => $name,
            'tax_rate' => $rate,
            'priority' => $priority,
            'country_id' => $country,
            'state_id' => $state,
            'city_id' => $city,
            'store_id' => self::STORE,
        ])->save();
    }

    private function taxOptions(array $values): void
    {
        foreach ($values as $name => $value) {
            (new TaxOption())->forceFill([
                'name' => $name,
                'value' => $value,
                'store_id' => self::STORE,
            ])->save();
        }
    }

    private function taxFor(?int $city, ?int $state, ?int $country): float
    {
        return (float) Cart::getProductTaxAmount(
            self::TAX_GROUP,
            self::SUBTOTAL,
            self::STORE,
            $city,
            $state,
            $country,
            true
        );
    }

    /** Aleppo first by priority, Damascus second — the order that exposes the fallback. */
    private function twoRestrictedMethods(): void
    {
        $this->method('Aleppo 3%', 3.00, 1, self::COUNTRY, 2, 2);
        $this->method('Damascus 5%', 5.00, 2, self::COUNTRY, self::STATE, self::CITY);
    }

    /**
     * THE PREMISE, in two parts, and without either one everything below is vacuous.
     */
    public function test_premise_the_store_has_saved_exclusive_tax_options(): void
    {
        $this->taxOptions(['price_type' => 'exclusive', 'shop_price' => 'exclusive']);

        $saved = TaxOption::withoutGlobalScopes()
            ->where('store_id', self::STORE)
            ->pluck('value', 'name')
            ->toArray();

        $this->assertSame('exclusive', $saved['price_type'] ?? null,
            'the fallback under test is gated on `isset($settings[price_type]) && != inclusive`. '
            . 'If the fixture did not save it, every case below passes because the branch was '
            . 'never entered — not because the restriction works.');
    }

    /** Both cart readers ask for the jurisdiction rules, so this arithmetic is the live one. */
    public function test_premise_both_cart_readers_ask_for_the_jurisdiction_rules(): void
    {
        foreach ([
            'app/Models/Cart.php' => 'the guest cart reader',
            'app/Http/Controllers/Api/ApiController.php' => 'the authenticated cart reader',
        ] as $file => $what) {
            $source = (string) file_get_contents(base_path($file));

            $this->assertMatchesRegularExpression(
                '/getProductTaxAmount\([^;]*,\s*true\s*\)/',
                $source,
                $what . ' no longer calls getProductTaxAmount() with $flag = true, so this suite '
                . 'is measuring a mode nothing uses — re-read it before trusting it'
            );
        }
    }

    /**
     * THE DEFECT, priority-order case: a Damascus shopper is charged Aleppo's tax as well.
     */
    public function test_a_method_for_another_city_is_not_charged_to_this_shopper(): void
    {
        $this->taxOptions(['price_type' => 'exclusive', 'shop_price' => 'exclusive']);
        $this->twoRestrictedMethods();

        $this->assertSame(
            5.00,
            $this->taxFor(self::CITY, self::STATE, self::COUNTRY),
            'a shopper in Damascus was charged Aleppo tax too. Measured before the fix: 8.00 — '
            . "the priority-1 Aleppo method falls through the fallback before the Damascus one "
            . 'matches. Reverse the two priorities and the same shopper is charged correctly, '
            . 'which is what makes this invisible to a fixture that happens to order them the '
            . 'other way.'
        );
    }

    /**
     * THE WIDEST CASE: a basket viewed before any address is entered knows no jurisdiction, so no
     * jurisdiction-restricted method can apply.
     */
    public function test_no_restricted_method_is_charged_when_no_address_is_known(): void
    {
        $this->taxOptions(['price_type' => 'exclusive', 'shop_price' => 'exclusive']);
        $this->twoRestrictedMethods();

        $this->assertSame(
            0.00,
            $this->taxFor(null, null, null),
            'both restricted taxes were charged to a shopper whose jurisdiction is not known yet. '
            . 'Measured before the fix: 8.00. `country_id`, `state_id` and `city_id` default to '
            . 'null on a cart-list call made before an address is entered, so this is the FIRST '
            . "view of every shopper's basket."
        );
    }

    /**
     * THE ALLOW PATH, so the fix cannot be "return 0".
     *
     * A method with no jurisdiction columns applies to everyone and must keep applying — with and
     * without an address.
     */
    public function test_an_unrestricted_method_is_still_charged(): void
    {
        $this->taxOptions(['price_type' => 'exclusive', 'shop_price' => 'exclusive']);
        $this->twoRestrictedMethods();
        $this->method('Everywhere 2%', 2.00, 3, null, null, null);

        $this->assertSame(
            7.00,
            $this->taxFor(self::CITY, self::STATE, self::COUNTRY),
            'a Damascus shopper must pay the Damascus 5% and the universal 2%, and nothing else'
        );

        $this->assertSame(
            2.00,
            $this->taxFor(null, null, null),
            'a shopper with no address must still pay the universal method'
        );
    }

    /**
     * THE INCLUSIVE MIRROR. A different spelling of the same hole, in the branch taken when
     * `price_type` is `inclusive` and `shop_price` is `including`.
     */
    public function test_the_inclusive_branch_does_not_sweep_in_every_method_either(): void
    {
        $this->taxOptions(['price_type' => 'inclusive', 'shop_price' => 'including']);
        $this->method('Aleppo 3%', 3.00, 1, self::COUNTRY, 2, 2);
        $this->method('Homs 5%', 5.00, 2, self::COUNTRY, 3, 3);

        $this->assertSame(
            0.00,
            round($this->taxFor(self::CITY, self::STATE, self::COUNTRY), 2),
            'a Damascus shopper was charged both the Aleppo and the Homs inclusive taxes. The '
            . '`if ($tax_price == 0)` sweep re-walks every method when none matched, which is the '
            . 'inclusive spelling of the exclusive branch\'s fallback.'
        );
    }

    /**
     * AND A STORE THAT HAS NEVER SAVED TAX OPTIONS IS UNAFFECTED, in both directions.
     *
     * Nothing seeds `tax_options`, so `isset($settings['price_type'])` is false there and the
     * exclusive fallback never fired. Pinned so the fix cannot be mistaken for the thing that made
     * the restriction work — it already worked for these stores, and must go on working.
     */
    public function test_a_store_with_no_tax_options_still_restricts_by_jurisdiction(): void
    {
        $this->twoRestrictedMethods();

        $this->assertSame(
            5.00,
            $this->taxFor(self::CITY, self::STATE, self::COUNTRY),
            'a store with no saved tax options already restricted correctly; it must still'
        );

        $this->assertSame(
            0.00,
            $this->taxFor(null, null, null),
            'and it must still charge nothing when no jurisdiction is known'
        );
    }

    /**
     * ANY OTHER `price_type` STRING BEHAVES LIKE `exclusive`, because the form validates it only
     * as `required` with no `in:` rule. Pinned so a fix written as `== 'exclusive'` is caught.
     */
    public function test_an_unrecognised_price_type_does_not_reopen_the_fallback(): void
    {
        $this->taxOptions(['price_type' => 'something-else', 'shop_price' => 'exclusive']);
        $this->twoRestrictedMethods();

        $this->assertSame(
            5.00,
            $this->taxFor(self::CITY, self::STATE, self::COUNTRY),
            'an unrecognised price_type re-opened the fallback. The settings form validates this '
            . "field as `required` with no `in:` rule, so it is not a hypothetical value."
        );
    }
}
