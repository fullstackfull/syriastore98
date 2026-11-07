<?php

namespace Tests\Contract;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE SHIPPED FLUTTER APP'S CONTRACT WITH `POST api/admin/AddCustomer`, across the S2 permission
 * repair of 2026-11-07.
 * =================================================================================================
 *
 * The repair adds a refusal to an endpoint a shipped client calls. **A new refusal on a path a
 * client already uses is a contract change**, and this project has a specific reason to be careful
 * about which code it answers with: `ApiResponser::error()` returns HTTP 200 by default, 401 must
 * be passed deliberately, and **the shipped app forces a logout on any 401**.
 *
 * WHAT WAS CHECKED IN THE CLIENT ITSELF, at `/home/user/StoreSyria_app98`, rather than assumed:
 *
 *   `lib/network/network.dart:352-358`   `case 403:` maps to `FailureReason.unauthorized` and
 *                                        surfaces `action_not_permitted`, with the comment one
 *                                        line above reading *"session here would turn one denied
 *                                        action into a logout loop"* — so **403 does not log the
 *                                        merchant out, and 401 does**.
 *   `lib/translations/ar.dart:693`       that message is translated: «ليست لديك صلاحية لتنفيذ هذا الإجراء»
 *   `lib/utils/prefer.dart:74-78`        already anticipates this exact case in prose: *"a store_id
 *                                        the merchant does not own answers 403 — which the network
 *                                        layer now reports as 'action_not_permitted'"*
 *   `lib/config/repository/customer_repository.dart:29-40`  the call site, which passes the body
 *                                        straight through and does not branch on the status itself
 *
 * So the client needs **no new branch** for this refusal — provided the server answers 403 with the
 * same shape as the ownership refusal it already handles. That is what this file asserts, from the
 * server side.
 *
 * THIS IS A CONTRACT TEST, NOT A SECURITY TEST. Whether the gate is correct is
 * `AdminApiCustomerCreatePermissionTest`'s question, with its two negatives, its three positive
 * controls, its per-store control and its tenancy control. (An earlier version of this sentence
 * credited that file with "four mutations". It has none: the four mutations were run by hand
 * against the gate and are recorded in the commit message, not asserted anywhere.) This file asks
 * only: **did the wire format change for anyone who was already succeeding, and is the new refusal
 * one the client can already read?**
 */
class AddCustomerPermissionRefusalContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;
    private Store $foreignStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->foreignStore = $this->makeStore($this->makeMerchant());
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        // The merchant holds the global `admin` role, exactly as RegisteredUserController and
        // StoreController hand it to every merchant in production. Via the IDEMPOTENT helper, not
        // makeGlobalAdminRole(): `roles.name` carries a UNIQUE index
        // (2023_12_08_100022_laratrust_setup_tables.php:19) and that one is an unconditional
        // forceCreate, so it throws the moment anything else in the same test needs an `admin`
        // role.
        $this->merchant = $this->grantMerchantGlobalRole($this->merchant);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Contract',
            'last_name'  => 'Shape',
            'email'      => 'contract@example.test',
            'mobile'     => '555000999',
            'password'   => 'Passw0rdLong',
            'status'     => '1',
        ], $overrides);
    }

    /**
     * NOT `post()`. `Illuminate\Foundation\Testing\TestCase` already has a PUBLIC `post()`, so a
     * private one here is a fatal at class-load time, not a test failure — the file simply does
     * not load. `AdminOrderPayloadShapeContractTest:55-56` records the same collision being hit in
     * this same directory, and I hit it again anyway.
     */
    private function addCustomer(User $as, int $storeId, array $payload = [])
    {
        return $this->withHeader('Authorization', 'Bearer ' . $as->createToken('auth_token')->plainTextToken)
            ->postJson('/api/admin/AddCustomer?store_id=' . $storeId, $this->payload($payload));
    }

    // =============================================================================================
    //  THE SUCCESS PAYLOAD IS UNCHANGED FOR ANYONE WHO WAS ALREADY SUCCEEDING
    // =============================================================================================

    public function test_a_permitted_caller_gets_the_same_201_payload_shape_as_before(): void
    {
        $response = $this->addCustomer($this->merchant, $this->store->id);

        $response->assertStatus(201);

        $body = $response->json();

        $this->assertSame(['status', 'message', 'data'], array_keys($body),
            'the top-level keys of the success payload changed. The shipped app reads this '
            . 'response; adding, removing or reordering a key here is a client-visible break');

        $this->assertSame(1, $body['status']);
        $this->assertSame('successful', $body['message']);
        // MEASURED, NOT ASSUMED — and the first version of this assertion was assumed and wrong.
        // It listed three keys; the endpoint returns four. `address_created` is there because the
        // handler optionally creates a DeliveryAddress alongside the customer and tells the client
        // whether it did. A contract test written from memory of the handler rather than from its
        // output is not a contract test, and this one caught its own author.
        $this->assertSame(['message', 'customer_id', 'store_id', 'address_created'],
            array_keys($body['data']),
            'the `data` keys of the success payload changed. The shipped app reads this response; '
            . 'adding, removing or reordering a key here is a client-visible break');

        $this->assertIsInt($body['data']['customer_id']);
        $this->assertNotNull(Customer::withoutGlobalScopes()->find($body['data']['customer_id']),
            'the id the response returns does not resolve to a row');
    }

    // =============================================================================================
    //  THE NEW REFUSAL IS SHAPED LIKE THE ONE THE CLIENT ALREADY HANDLES
    // =============================================================================================

    public function test_the_permission_refusal_is_byte_identical_in_shape_to_the_ownership_refusal(): void
    {
        // (a) the refusal the client has handled since before this change: a store the caller
        //     does not own.
        $ownership = $this->addCustomer($this->merchant, $this->foreignStore->id,
            ['email' => 'ownership@example.test']);

        // (b) the refusal this change introduces: a store the caller DOES own, without the right.
        $unpermitted = $this->makeStaff($this->merchant, [
            'current_store' => $this->store->id,
            'is_active' => 1,
        ]);
        $permission = $this->addCustomer(User::find($unpermitted->id), $this->store->id,
            ['email' => 'permission@example.test']);

        $this->assertSame(403, $ownership->getStatusCode(), 'premise: the ownership refusal is 403');
        $this->assertSame(403, $permission->getStatusCode(),
            'the NEW refusal does not answer 403, so the client must learn a new status for it');

        $this->assertSame($ownership->json(), $permission->json(),
            'the two refusals differ in shape, so the shipped app would need a new branch to read '
            . 'the new one. They are deliberately identical — which also means a caller cannot '
            . 'tell "I do not own that store" from "I lack the right", so the repair adds no '
            . 'existence oracle');
    }

    public function test_neither_refusal_is_ever_401_because_the_app_logs_out_on_401(): void
    {
        $unpermitted = $this->makeStaff($this->merchant, [
            'current_store' => $this->store->id,
            'is_active' => 1,
        ]);

        foreach ([
            'permission' => [User::find($unpermitted->id), $this->store->id],
            'ownership'  => [$this->merchant, $this->foreignStore->id],
        ] as $label => [$actor, $storeId]) {
            $code = $this->addCustomer($actor, $storeId, ['email' => $label . '@example.test'])
                ->getStatusCode();

            $this->assertNotSame(401, $code,
                "the {$label} refusal answers 401. lib/network/network.dart treats 401 as a session "
                . 'failure and the shipped app FORCES A LOGOUT — so a merchant whose staff lack a '
                . 'right would be signed out of the app instead of being told. The comment at '
                . 'network.dart:350 names this exact hazard: "session here would turn one denied '
                . 'action into a logout loop"');

            // AND IT MUST BE A REFUSAL AT ALL. Without this line the `permission` leg of this
            // loop could not fail for the change it exists to guard: revert the gate and that
            // leg answers 201, which satisfies `assertNotSame(401, ...)` perfectly. The
            // independent review found it; it is the same can-never-fail shape this branch has
            // now hit four times, so it is worth naming rather than quietly fixing.
            $this->assertSame(403, $code,
                "the {$label} refusal did not answer 403 at all, so the 401 assertion above it "
                . 'proved nothing — a reverted gate returns 201 and passes it');
        }
    }

    public function test_an_absent_token_still_answers_401_and_that_is_correct(): void
    {
        // The contract is not "never 401" — it is "401 means the session, 403 means the right".
        // A missing token really is a session failure and the logout is the right response.
        $this->postJson('/api/admin/AddCustomer?store_id=' . $this->store->id, $this->payload())
            ->assertStatus(401);
    }

    // =============================================================================================
    //  AND NOTHING ELSE ON THE ENDPOINT MOVED
    // =============================================================================================

    public function test_the_validation_refusal_still_answers_422_with_its_field_errors(): void
    {
        $response = $this->addCustomer($this->merchant, $this->store->id, ['email' => 'not-an-email']);

        $response->assertStatus(422);

        $body = $response->json();
        $this->assertSame(0, $body['status'] ?? null);
        $this->assertArrayHasKey('errors', $body['data'] ?? [],
            'the validation refusal no longer carries per-field errors, which the client renders');
        $this->assertArrayHasKey('email', $body['data']['errors'],
            'the email field error is gone from the validation payload');
    }
}
