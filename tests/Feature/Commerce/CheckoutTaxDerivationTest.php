<?php

namespace Tests\Feature\Commerce;

use App\Services\OrderTaxResolver;
use Tests\TestCase;

/**
 * Tax is DERIVED from the server's own cart and shipping computations, never
 * accepted from the client.
 *
 * The defect these cover: the storefront read the tax component of the charged
 * total from client input at nine sites, with no Validator over it, so a
 * customer could set — including negate — the tax on their own order.
 *
 * No database is needed: the resolver only reads the cart payloads it is handed.
 *
 * @see \App\Services\OrderTaxResolver
 */
class CheckoutTaxDerivationTest extends TestCase
{
    private OrderTaxResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new OrderTaxResolver();
    }

    /** The cart's own server-computed tax is what is used. */
    public function test_tax_comes_from_the_server_computed_cart_total(): void
    {
        $this->assertSame(
            1500.0,
            $this->resolver->resolve(['total_tax_price' => 1500.0])
        );
    }

    /**
     * When the plan taxes shipping, get_shipping_method's `final_tax_price`
     * supersedes the cart figure, because it includes the tax on the shipping
     * charge as well.
     */
    public function test_shipping_method_tax_supersedes_the_cart_tax(): void
    {
        $this->assertSame(
            1725.0,
            $this->resolver->resolve(
                ['total_tax_price' => 1500.0],
                ['final_tax_price' => 1725.0]
            )
        );
    }

    /** A cart payload that carries the figure as `tax_price` is also server output. */
    public function test_cart_payload_tax_price_key_is_accepted(): void
    {
        $this->assertSame(42.0, $this->resolver->resolve(['tax_price' => 42.0]));
    }

    /** No tax computed means no tax charged. */
    public function test_absent_cart_tax_resolves_to_zero(): void
    {
        $this->assertSame(0.0, $this->resolver->resolve([]));
        $this->assertSame(0.0, $this->resolver->resolve(['total_tax_price' => null]));
        $this->assertSame(0.0, $this->resolver->resolve(['total_tax_price' => 'not a number']));
    }

    /**
     * THE EXPLOIT. `tax_price = -999999` both reduced the amount actually
     * charged and corrupted the tax ledger. Tax is never negative: a discount is
     * `coupon_price`, a separate component with its own sign.
     */
    public function test_a_negative_tax_is_never_produced(): void
    {
        $this->assertSame(
            0.0,
            $this->resolver->resolve(['total_tax_price' => -999999])
        );

        $this->assertSame(
            0.0,
            $this->resolver->resolve([], ['final_tax_price' => -999999])
        );
    }

    /**
     * The resolver has NO input through which a client value could enter: it
     * takes cart payloads only. A posted value can be reported but not used.
     */
    public function test_resolver_signature_admits_no_request_input(): void
    {
        $method = new \ReflectionMethod(OrderTaxResolver::class, 'resolve');

        $this->assertSame(['cartlist', 'shippingMethodData'], array_map(
            static fn (\ReflectionParameter $p) => $p->getName(),
            $method->getParameters()
        ));
    }

    /**
     * A posted tax that disagrees with the server figure is recorded, so the
     * theme templates still posting these fields can be enumerated from real
     * traffic rather than assumed empty (the GATE H prerequisite).
     */
    public function test_a_mismatching_client_tax_is_recorded(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message, array $context): bool {
                return str_contains($message, 'client-supplied tax_price discarded')
                    && $context['client_tax_price'] === '-999999'
                    && $context['server_tax_price'] === '1500';
            });

        $this->resolver->recordClientSuppliedTax('-999999', 1500.0, self::class);
    }

    /** A posted tax that agrees with the server figure is not noise-logged. */
    public function test_a_matching_client_tax_is_not_logged(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->never();

        $this->resolver->recordClientSuppliedTax('1500.00', 1500.0, self::class);
        $this->resolver->recordClientSuppliedTax(null, 1500.0, self::class);
        $this->resolver->recordClientSuppliedTax('', 1500.0, self::class);
    }
}
