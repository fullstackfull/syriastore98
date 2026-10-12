<?php

namespace Tests\Feature\Security;

use App\Models\OrderNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * NEW-order-note-cross-tenant-write / NEW-order-note-cross-tenant-delete, and
 * the web half of NEW-unauthenticated-order-notes-write.
 *
 * The ANONYMOUS write to /api/admin/orders/notes/create was closed in Phase 1B
 * by adding `custom.auth` to the route, and AdminApiSurfaceTest pins that. What
 * was left -- and what this suite closes -- is the layer underneath it: both
 * API order-note mutators resolved the store from a REQUEST FIELD
 * (`Store::find($request->store_id)`) and never compared it against the
 * caller's tenant, so the order lookup that followed only ever re-asserted the
 * attacker's own parameter. Any holder of any valid token could write or delete
 * order notes in any store on the platform.
 *
 * The web sibling was worse in one respect: OrderNoteController::destroy took an
 * IMPLICITLY BOUND OrderNote and checked only isAbleTo('Delete Order Note'), a
 * permission the shared `admin` role carries by default (Utility.php:2957), so
 * the bound id was the entire authorization decision.
 *
 * EVERY DENIAL HERE IS ASSERTED AS 403, NEVER 401. repo-B
 * lib/network/network.dart:285-287 maps any 401 into _handleUnauthorized(),
 * which clears preferences and forces a re-login, so answering an authorization
 * denial with 401 would log the merchant out of the shipped Flutter app.
 */
class OrderNoteAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // The API pair: /api/admin/orders/notes/{create,delete}
    // -----------------------------------------------------------------

    public function test_api_note_create_rejects_a_store_the_caller_does_not_own(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $before = DB::table('order_notes')->count();
        $token = $merchantA->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/orders/notes/create', [
                'store_id'  => $storeB->id,
                'order_id'  => $orderB->id,
                'note'      => 'injected across the tenant boundary',
                'note_type' => 'to_customer',
            ])
            ->assertStatus(403);

        $this->assertSame(
            $before,
            DB::table('order_notes')->count(),
            'A cross-tenant note write must not have persisted a row.'
        );
    }

    public function test_api_note_delete_rejects_a_store_the_caller_does_not_own(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $noteB = OrderNote::create([
            'order_id'    => $orderB->id,
            // null, not 0: order_notes.customer_id has a foreign key to
            // customers.id, so 0 violates it. See
            // Api/DashboardController::AddOrderNote.
            'customer_id' => null,
            'notes'       => 'store B private note',
            'note_type'   => 'private_note',
            'store_id'    => $storeB->id,
        ]);

        $token = $merchantA->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/admin/orders/notes/delete', [
                'store_id' => $storeB->id,
                'order_id' => $orderB->id,
                'note_id'  => $noteB->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('order_notes', ['id' => $noteB->id]);
    }

    /**
     * THE LEGITIMATE WORKFLOW. Without this, the two tests above could both pass
     * while order notes were simply broken for everybody.
     */
    public function test_api_note_create_and_delete_still_work_in_the_callers_own_store(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $token = $merchant->createToken('auth_token')->plainTextToken;

        $create = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/orders/notes/create', [
                'store_id'  => $store->id,
                'order_id'  => $order->id,
                'note'      => 'your parcel ships tomorrow',
                'note_type' => 'to_customer',
            ]);

        $create->assertStatus(200);
        $this->assertSame(1, $create->json('status'), 'The merchant\'s own note write must succeed.');

        $noteId = $create->json('data.note.id');
        $this->assertNotNull($noteId, 'The response must carry the created note id.');
        $this->assertDatabaseHas('order_notes', [
            'id'       => $noteId,
            'store_id' => $store->id,
            'order_id' => $order->id,
        ]);

        $delete = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/admin/orders/notes/delete', [
                'store_id' => $store->id,
                'order_id' => $order->id,
                'note_id'  => $noteId,
            ]);

        $delete->assertStatus(200);
        $this->assertSame(1, $delete->json('status'));
        $this->assertDatabaseMissing('order_notes', ['id' => $noteId]);
    }

    /**
     * The platform-operator escape hatch, in the shape resolveOwnedStore()
     * already expresses it for the seven admin list methods.
     */
    public function test_a_platform_admin_may_write_a_note_in_any_store(): void
    {
        $super = $this->makePlatformAdmin();
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $token = $super->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/orders/notes/create', [
                'store_id'  => $store->id,
                'order_id'  => $order->id,
                'note'      => 'operator note',
                'note_type' => 'private_note',
            ]);

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'A platform admin must keep the cross-store escape hatch.'
        );
    }

    // -----------------------------------------------------------------
    // The web pair: POST /order-note and DELETE /order-note/{id}
    // -----------------------------------------------------------------

    /**
     * These are web-guard form routes, so a denial is the application's own
     * redirect-back-with-error, not a 403 page -- that is the pattern this
     * controller has always used and changing it would change merchant UX. The
     * property asserted is therefore the one that matters: the row is not
     * written, and is not deleted. The 403 discipline applies to the JSON API
     * pair above, where the Flutter client is the caller.
     */
    public function test_web_note_create_refuses_an_order_in_another_store(): void
    {
        [$attacker, $storeA] = $this->makeTenant();
        $this->grantPermission($attacker, 'Create Order Note');

        [, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $before = DB::table('order_notes')->count();

        $this->actingAs($attacker)->post('/order-note', [
            'order_id'  => $orderB->id,
            'note'      => 'note on a foreign order',
            'note_type' => 'private_note',
        ]);

        $this->assertSame(
            $before,
            DB::table('order_notes')->count(),
            'A note must not be created against an order in another store.'
        );
    }

    public function test_web_note_delete_refuses_another_stores_note(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Order Note');

        [, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $noteB = OrderNote::create([
            'order_id'    => $orderB->id,
            // null, not 0: order_notes.customer_id has a foreign key to
            // customers.id, so 0 violates it. See
            // Api/DashboardController::AddOrderNote.
            'customer_id' => null,
            'notes'       => 'store B note',
            'note_type'   => 'private_note',
            'store_id'    => $storeB->id,
        ]);

        $this->actingAs($attacker)->delete('/order-note/' . $noteB->id);

        $this->assertDatabaseHas(
            'order_notes',
            ['id' => $noteB->id],
            // assertDatabaseHas's third argument is the CONNECTION name, not a
            // message, so the explanation stays in this comment: the implicitly
            // bound id used to be the whole authorization decision.
        );
    }

    /**
     * THE LEGITIMATE WORKFLOW, and the test that proves the policy actually
     * resolves. App\Policies\OrderNotePolicy is registered by Laravel's
     * convention-based discovery (App\Models\OrderNote -> App\Policies\
     * OrderNotePolicy) rather than by the $policies array in
     * AuthServiceProvider, which this workstream does not own. If discovery did
     * not find it, can() would answer false for everybody and this test would
     * fail -- which is the point of asserting the allowed direction.
     */
    public function test_web_note_create_and_delete_work_inside_the_merchants_own_store(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Order Note');
        $this->grantPermission($merchant, 'Delete Order Note');
        $merchant = $merchant->fresh();

        $order = $this->makeOrder($store);

        $this->actingAs($merchant)->post('/order-note', [
            'order_id'  => $order->id,
            'note'      => 'picked and packed',
            'note_type' => 'private_note',
        ]);

        $this->assertDatabaseHas('order_notes', [
            'order_id' => $order->id,
            'store_id' => $store->id,
            'notes'    => 'picked and packed',
        ]);

        $note = OrderNote::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($merchant)->delete('/order-note/' . $note->id);

        $this->assertDatabaseMissing('order_notes', ['id' => $note->id]);
    }

    /**
     * The permission half must still bite: tenancy is an ADDITIONAL test, not a
     * replacement for the permission check.
     */
    public function test_web_note_create_still_requires_the_permission(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $before = DB::table('order_notes')->count();

        $this->actingAs($merchant)->post('/order-note', [
            'order_id'  => $order->id,
            'note'      => 'no permission held',
            'note_type' => 'private_note',
        ]);

        $this->assertSame($before, DB::table('order_notes')->count());
    }

    /**
     * note_type was persisted verbatim on the web path while the API sibling
     * restricted it to `in:private_note,to_customer`
     * (Api/DashboardController::AddOrderNote:111). The two write paths must not
     * disagree about what a note_type is: `to_customer` notes are shown to the
     * shopper.
     */
    public function test_web_note_create_rejects_an_unknown_note_type(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Order Note');
        $order = $this->makeOrder($store);

        $before = DB::table('order_notes')->count();

        $this->actingAs($merchant->fresh())->post('/order-note', [
            'order_id'  => $order->id,
            'note'      => 'arbitrary type',
            'note_type' => 'not_a_real_note_type',
        ]);

        $this->assertSame($before, DB::table('order_notes')->count());
    }
}
