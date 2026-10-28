<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F146 -- A LANGUAGE FILE MUST NOT BE ABLE TO CHANGE AN ORDER'S BEHAVIOUR.
 * ============================================================================================
 *
 * `PosController::store()` stored the till's payment type as `__('POS')` -- the TRANSLATED
 * string. Three branches in `App\Models\Order` then compare `payment_type` against the LITERAL
 * `'POS'`:
 *
 *   - `Order.php:301`  reshapes each product line for a POS order
 *   - `Order.php:374`  adds `tax_price` and `coupon_price` to the response array
 *   - `Order.php:503`  takes a DIFFERENT branch for the customer block: a POS order's
 *                      `billing_informations` is built from the `Customer` row -- or from the
 *                      words "Walk-in Customer" when there is none -- while a non-POS order's
 *                      is read from `order_billing_details`, which a till sale never writes
 *
 * All three feed the array `Order::order_detail()` returns, which is the ORDER-DETAIL API
 * RESPONSE the Flutter admin app and Lynomia read. So for as long as the writer went through the
 * translator, the behaviour of a till order depended on the UI language of the session that
 * created it -- and `resources/lang/ar.json` currently maps "POS" to the identical string "POS",
 * which is the only reason nothing is broken today.
 *
 * THE DEFECT IS LIVE, AND AN EARLIER VERSION OF THIS DOCBLOCK SAID IT WAS LATENT. That version
 * checked `ar.json` and `en.json`, found both map "POS" to the identical string "POS", and
 * concluded the trigger was a future translation edit. Independent review checked the other
 * locales: **six shipped ones already translate it** -- `es` "Punto de venta", `fr` "Le point de
 * vente", `pl` "Pozycja POS", `ru` "ПОЗ", `zh` "没有", `ja` "POS ポイント". All are offered by
 * `Utility`'s language list unless the operator disables them, and the locale is settable through
 * `change-languages/{lang}`, a PUBLIC GET route writing `users.language` from a route parameter.
 *
 * So a merchant working in Spanish has been storing `payment_type = 'Punto de venta'` on every
 * till sale: it misses all three branches above and renders '-' in the order list's Payment Type
 * cell. A merchant working in Arabic or English was never affected, which is exactly why this
 * went unseen. `test_the_landmine_is_real` drives a real shipped locale and measures the response
 * difference; `test_the_till_writes_the_literal` shows the writer no longer feeds it.
 *
 * THE FIX CHANGES NO STORED VALUE TODAY. `__('POS')` returns 'POS' in both shipped locales, so
 * writing the literal produces a byte-identical row -- no existing order changes, no payment
 * logic changes, and no migration or backfill is involved. It only removes the dependency.
 */
class PosPaymentTypeLocaleTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant);
    }

    /**
     * Arms the landmine and measures it. An order whose `payment_type` holds the TRANSLATED name
     * is treated as a non-POS order by `order_detail()`: it gains a `billing_informations` block
     * that a till sale has no row for.
     *
     * This test does NOT assert the fix. It asserts that the mechanism the fix removes is real,
     * so that "the translation is identical today" is recorded as luck rather than as safety.
     */
    public function test_the_landmine_is_real(): void
    {
        /*
         * A REAL SHIPPED LOCALE, not a synthetic translation. This test first did
         * `Lang::addLines(['*.POS' => 'نقطة بيع'], 'ar')` and proved only "IF someone translated
         * POS, then…". Independent review established that six shipped locales ALREADY do --
         * `es` "Punto de venta", `fr` "Le point de vente", `pl` "Pozycja POS", `ru` "ПОЗ",
         * `zh` "没有", `ja` "POS ポイント" -- so the hypothetical was unnecessary and the defect was
         * live rather than latent. Driving `es` from `resources/lang/es.json` makes this a
         * statement about the shipped product.
         */
        app()->setLocale('es');

        $this->assertNotSame('POS', __('POS'),
            'premise: a SHIPPED locale really does translate this. If es.json ever maps "POS" to '
            . '"POS" the rest of the test proves nothing -- pick another of the six.');
        $this->assertSame('Punto de venta', __('POS'),
            'premise: and it is the value review measured, so the fixture below is realistic');

        // A walk-in sale: no customer row, which is the ordinary till case and the one whose
        // two branches differ most visibly.
        $common = ['customer_id' => 0, 'is_guest' => 1, 'product_json' => json_encode([])];

        $till = $this->makeOrder($this->store, $common + [
            'product_order_id' => 'POS-LITERAL',
            'payment_type' => 'POS',
        ]);

        $translated = $this->makeOrder($this->store, $common + [
            'product_order_id' => 'POS-TRANSLATED',
            'payment_type' => __('POS'),
        ]);

        $asLiteral = Order::order_detail($till->id);
        $asTranslated = Order::order_detail($translated->id);

        $this->assertSame(__('Walk-in Customer'), $asLiteral['billing_informations']['name'] ?? null,
            'premise: a till order identified as POS names the buyer from the customer branch '
            . '(Order.php:503 `else`), which is where "Walk-in Customer" comes from');

        $this->assertNotSame(
            $asLiteral['billing_informations']['name'],
            $asTranslated['billing_informations']['name'] ?? null,
            'THE SAME SALE, RUNG UP IN ARABIC, COMES BACK DESCRIBING A DIFFERENT BUYER. The '
            . 'translated row falls through to the non-POS branch, which reads an '
            . '`order_billing_details` row a till sale never writes, so the name the Flutter '
            . 'app shows goes blank -- caused by a TRANSLATION STRING, with no code or data '
            . 'change anywhere.'
        );

        $this->assertSame(' ', $asTranslated['billing_informations']['name'],
            'and the blank is literally the two empty halves of a name that was never recorded');
    }

    /**
     * THE FIX. The till's write site must not pass the discriminator through the translator.
     *
     * Asserted on the SOURCE, because that is precisely the claim: no runtime value can
     * distinguish `__('POS')` from `'POS'` while the two shipped locales agree, so a behavioural
     * test of the writer would pass either way today. Comments are blanked first -- this
     * codebase's prose quotes its own identifiers constantly, and a scanner that reads its
     * subject's commentary has produced three false readings in this project already.
     */
    public function test_the_till_writes_the_literal(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents(base_path('app/Http/Controllers/PosController.php'))
        );

        $assignments = [];

        foreach (explode("\n", $source) as $i => $line) {
            if (preg_match('/payment_type\s*=\s*(.+?);/', $line, $m)) {
                $assignments[$i + 1] = trim($m[1]);
            }
        }

        $this->assertNotEmpty($assignments,
            'premise: PosController still assigns payment_type at all. If it stopped, this test '
            . 'would otherwise pass by finding nothing.');

        foreach ($assignments as $lineNo => $value) {
            $this->assertStringNotContainsString('__(', $value,
                "PosController.php:{$lineNo} assigns payment_type as `{$value}`. Passing the "
                . 'till\'s discriminator through the translator makes the stored value depend on '
                . 'the UI language, and Order.php:301, :374 and :446 all compare it against the '
                . "literal 'POS'. Write the literal.");
        }
    }

    /** Blank `//`, `#` and docblock content while preserving line numbers. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
