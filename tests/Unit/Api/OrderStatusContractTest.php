<?php

namespace Tests\Unit\Api;

use App\Support\Api\OrderStatusContract;
use Tests\TestCase;

/**
 * F154 — the stable key and the localised label, and the line between them.
 *
 * The admin API never runs `SetLocate`, so every `__()`-rendered field reaches the Flutter app in
 * the application default language. The fix is ADDITIVE: `order_status_text` keeps its exact
 * value and two new fields carry what a consumer actually needs. These cases pin both halves —
 * that the key never moves and is never translated, and that the label really follows the locale.
 */
class OrderStatusContractTest extends TestCase
{
    /** All nine `delivered_status` values, not the seven usually remembered. */
    public function test_every_delivered_status_has_a_stable_key(): void
    {
        $expected = [
            0 => 'pending',
            1 => 'delivered',
            2 => 'cancelled',
            3 => 'return',
            4 => 'confirmed',
            5 => 'picked_up',
            6 => 'shipped',
            7 => 'partially_paid',
            8 => 'pre_order',
        ];

        foreach ($expected as $status => $key) {
            $this->assertSame($key, OrderStatusContract::key($status), 'status ' . $status);
        }

        $this->assertSame($expected, OrderStatusContract::allKeys());
    }

    public function test_an_unknown_or_null_status_is_named_rather_than_guessed(): void
    {
        $this->assertSame('unknown', OrderStatusContract::key(99));
        $this->assertSame('unknown', OrderStatusContract::key(null));
        $this->assertSame('unknown', OrderStatusContract::key(-1));
    }

    /**
     * THE KEY IS NOT TRANSLATED, in any locale. This is the whole contract: a consumer branches
     * on it, so it must be the same bytes whoever is logged in.
     */
    public function test_the_key_is_identical_in_every_locale(): void
    {
        $byLocale = [];

        foreach (['en', 'ar', 'fr', 'tr', 'ru'] as $locale) {
            $this->app->setLocale($locale);
            $byLocale[$locale] = array_map(
                static fn ($s) => OrderStatusContract::key($s),
                range(0, 8)
            );
        }

        $this->app->setLocale('en');

        foreach ($byLocale as $locale => $keys) {
            $this->assertSame(
                $byLocale['en'],
                $keys,
                'order_status_key changed under locale ' . $locale . ', so it is not a stable identifier.'
            );
        }
    }

    public function test_the_label_follows_the_requested_locale(): void
    {
        $english = OrderStatusContract::label(0, 'en');
        $arabic = OrderStatusContract::label(0, 'ar');

        $this->assertSame('Pending', $english);
        $this->assertNotSame(
            $english,
            $arabic,
            'The Arabic label for status 0 is identical to the English one. If ar.json lost the '
            . '`Pending` key, this field is not doing its job and the hazard is dormant, not gone.'
        );
    }

    /**
     * RESOLVING A LABEL MUST NOT CHANGE THE APPLICATION LOCALE.
     *
     * This runs inside a response builder, so a `setLocale` leak would silently change every
     * field assembled after it — which is exactly the blast radius the additive approach exists
     * to avoid.
     */
    public function test_resolving_a_label_does_not_leak_the_locale(): void
    {
        $this->app->setLocale('en');

        OrderStatusContract::label(4, 'ar');
        OrderStatusContract::label(6, 'fr');

        $this->assertSame('en', $this->app->getLocale());
    }

    /**
     * `Comfirmed` IS NOT CARRIED INTO THE NEW FIELD.
     *
     * The host misspells status 4 as `__('Comfirmed')`, and
     * `tests/Feature/ApiContract/OrderStatusTextTest` pins that for `order_status_text` so the
     * four surfaces stay in step. There is no reason to inherit it here, and a consumer reading
     * the new field should not have to know about it.
     */
    public function test_the_new_label_does_not_inherit_the_host_misspelling(): void
    {
        $this->assertSame('confirmed', OrderStatusContract::key(4));
        $this->assertSame('Confirmed', OrderStatusContract::label(4, 'en'));
        $this->assertStringNotContainsStringIgnoringCase('comfirmed', OrderStatusContract::label(4, 'en'));
    }

    public function test_fields_returns_both_and_only_both(): void
    {
        $fields = OrderStatusContract::fields(6, 'en');

        $this->assertSame(['order_status_key', 'order_status_label'], array_keys($fields));
        $this->assertSame('shipped', $fields['order_status_key']);
        $this->assertSame('Shipped', $fields['order_status_label']);
    }
}
