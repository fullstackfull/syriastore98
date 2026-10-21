<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

/**
 * P1-ORD-05 -- THE ORDER READ FAMILY: (A) A FORGEABLE ENCRYPTED ID, AND (B) TWO HANDLERS THAT LOCK THE PLATFORM OPERATOR OUT.
 * ==========================================================================================================================
 *
 * (A) The ledger row was filed against OrderController::show / order_view / order_receipt, which decrypt the id from the URL and read
 * Order::order_detail() (unscoped) with no ownership check; `Crypt::encrypt()` is not a control because APP_KEY is in git history. By the
 * time this row was worked, P1-ORD-07/08's OrderDocumentAccessGuard had put every one of those URIs under a store-ownership predicate
 * (its TARGETS: order-view/{id}, orders/order_view/{id}, order-receipt/{id}, order/{order}). This file therefore does not assume the
 * old finding: it MEASURES it, with a correctly encrypted foreign id (and a hand-forged one), for each handler, and asserts the
 * response carries none of the victim's billing PII. The cases pass on the current tree and are kept as the regression pin for the
 * row; the removal check is on the guard (see OrderDocumentAccessTest for its own).
 *
 * (B) is real and was RED: OrderController::index() (`/order`) and ::order_view() (`/order-view/{id}`) check
 * `auth()->user()->isAbleTo('Manage Order'|'Show Order')` with no operator limb. CLAUDE.md: Laratrust has no super-admin bypass and the
 * operator holds no merchant role, so on a database built from source the platform operator was answered "Permission denied" on both the
 * order list and the order screen (the guard lets the operator through; the handler then refuses). OrderPolicy::allow already orders the
 * checks operator-first; these two call sites did not.
 */
class OrderReadFamilyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const VICTIM_EMAIL = 'victim-billing-FIXTURE@example.test';
    private const VICTIM_NAME = 'Victim-FIXTURE-Billing-Name';

    private function victimOrder(): array
    {
        [$victim, $victimStore] = $this->makeTenant();

        $order = $this->makeOrder($victimStore, [
            'billing_informations' => json_encode(['name' => self::VICTIM_NAME, 'email' => self::VICTIM_EMAIL, 'phone' => '+96500000000']),
        ]);

        return [$victim, $victimStore, $order];
    }

    // =========================================================================================
    //  (A) A foreign order, correctly encrypted, is not rendered to another tenant
    // =========================================================================================

    public static function readUris(): array
    {
        return [
            'order view (admin screen)' => ['/order-view/%s'],
            'order show (orders/order_view)' => ['/orders/order_view/%s'],
            'order receipt' => ['/order-receipt/%s'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readUris')]
    public function test_a_merchant_cannot_read_another_stores_order_with_a_correctly_encrypted_id(string $uri): void
    {
        [, , $order] = $this->victimOrder();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Show Order');

        $response = $this->actingAs($attacker->fresh())->get(sprintf($uri, Crypt::encrypt($order->id)));

        $this->assertStringNotContainsString(self::VICTIM_EMAIL, (string) $response->getContent(), 'The victim\'s billing e-mail was rendered to another tenant.');
        $this->assertStringNotContainsString(self::VICTIM_NAME, (string) $response->getContent(), 'The victim\'s billing name was rendered to another tenant.');
        $this->assertNotSame(200, $response->status(), 'A foreign order answered 200.');
    }

    /** Encryption is an identifier, not an authorisation: the app key is public, so anyone can mint this. */
    #[\PHPUnit\Framework\Attributes\DataProvider('readUris')]
    public function test_the_refusal_does_not_depend_on_the_id_being_encrypted_by_the_application(string $uri): void
    {
        [, , $order] = $this->victimOrder();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Show Order');

        // a raw integer is not a valid ciphertext; the handler's decrypt fails, and nothing of the order is rendered
        $response = $this->actingAs($attacker->fresh())->get(sprintf($uri, $order->id));

        $this->assertStringNotContainsString(self::VICTIM_EMAIL, (string) $response->getContent());
        $this->assertStringNotContainsString(self::VICTIM_NAME, (string) $response->getContent());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readUris')]
    public function test_a_merchant_can_still_read_their_own_order(string $uri): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');
        $order = $this->makeOrder($store, [
            'billing_informations' => json_encode(['name' => 'Own-FIXTURE-Billing-Name', 'email' => 'own@example.test', 'phone' => '1']),
        ]);

        $response = $this->actingAs($merchant->fresh())->get(sprintf($uri, Crypt::encrypt($order->id)));

        // 200 for the screens that render, or the receipt; the point is that the guard does not refuse the owner
        $this->assertNotSame(403, $response->status(), 'Premise for the refusals above: the guard lets the owner through.');
    }

    // =========================================================================================
    //  (B) The platform operator is not locked out
    // =========================================================================================

    public function test_the_platform_operator_can_open_the_order_list(): void
    {
        [, $store] = $this->makeTenant();
        // the operator's current store: the layout header reads the store's slug, and getCurrentStore() answers a hardcoded 1 for a
        // super admin with none (app/Helper/helper.php), which does not exist in a fresh test database
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);
        $this->assertSame('super admin', $operator->type);
        $this->assertFalse($operator->isAbleTo('Manage Order'), 'Premise: on a database built from source the operator holds no merchant permission.');

        $response = $this->actingAs($operator)->get('/order');

        $this->assertFalse(
            $response->isRedirect() && str_contains((string) session('error'), 'Permission denied'),
            'The platform operator was answered "Permission denied" on the order list.'
        );
        $response->assertSessionMissing('error');
        $response->assertOk();
    }

    public function test_the_platform_operator_can_open_an_order_screen(): void
    {
        [, $victimStore, $order] = $this->victimOrder();
        $operator = $this->makePlatformAdmin(['current_store' => $victimStore->id]);
        $this->assertFalse($operator->isAbleTo('Show Order'), 'Premise: the operator holds no merchant permission.');

        $response = $this->actingAs($operator)->get('/order-view/' . Crypt::encrypt($order->id));

        $response->assertSessionMissing('error');
        $response->assertOk();
        $this->assertStringContainsString((string) $order->product_order_id, (string) $response->getContent(), 'Premise: the screen really rendered the order, so "no error" is not a redirect in disguise.');
    }

    public function test_a_merchant_without_the_permission_is_still_refused_on_both(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $order = $this->makeOrder($store);

        $list = $this->actingAs($staff->fresh())->get('/order');
        $list->assertSessionHas('error');

        $view = $this->actingAs($staff->fresh())->get('/order-view/' . Crypt::encrypt($order->id));
        $this->assertTrue($view->status() === 403 || session()->has('error'), 'A staff member with no Show Order permission read an order screen.');
    }
}
