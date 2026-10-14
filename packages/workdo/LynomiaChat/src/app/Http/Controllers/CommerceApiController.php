<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Http\Middleware\AuthenticateCommerceApi;

/**
 * Store Syria's commerce surface for the Lynomia adapter.
 *
 * SEPARATE FROM THE ADMIN API ON PURPOSE. The admin API tenants through users.current_store and
 * answers for "whichever store this human last clicked"; see AuthenticateCommerceApi. This
 * surface takes its store from the credential and from nowhere else, so a handler here cannot
 * accidentally read the wrong tenant by calling a helper.
 *
 * VERSIONED, because the adapter on the other side ships independently of this application and
 * the two have to be able to move at different speeds. /v1 is the contract recorded in
 * docs/development/lynomia-integration/PHASE-3A-COMMERCE-CONTRACT.md.
 *
 * WHAT IT DOES NOT RETURN is as deliberate as what it does: no addresses, no payment instrument
 * data, no raw product_json. A commerce panel in an inbox needs to tell an agent what the
 * customer ordered and where it is; it does not need the customer's street.
 */
class CommerceApiController extends Controller
{
    /**
     * Who this credential is for, and whether the store is in a state to serve.
     *
     * The adapter calls this for Commerce::Providers::Base#health and #store_identity, which
     * Lynomia runs BEFORE it persists a connection -- so this is the endpoint that decides
     * whether a store can be connected at all.
     */
    public function store(Request $request): JsonResponse
    {
        $storeId = (int) $request->attributes->get(AuthenticateCommerceApi::STORE_ID);
        $link = $request->attributes->get(AuthenticateCommerceApi::LINK);
        $store = Store::find($storeId);

        if ($store === null) {
            // The link survives a deleted store, so this is reachable. It is a 404 rather than a
            // 401: the credential was valid, the thing it names is gone.
            return response()->json(['error' => 'Store not found'], 404);
        }

        return response()->json([
            // EXTERNAL ID, NOT THE PRIMARY KEY ALONE. Lynomia's commerce_stores table has a
            // GLOBAL unique index on (provider, external_store_id), so this value decides store
            // exclusivity across the whole installation. It is qualified by the installation so
            // two Store Syria deployments can never collide into one Lynomia account.
            'external_store_id' => $this->externalStoreId($storeId),
            'name' => (string) $store->name,
            'slug' => (string) $store->slug,
            'currency' => $this->currency(),
            'active' => (bool) $store->is_active,
            'link_state' => (string) $link->state,
            'capabilities' => [
                // Declared, so the adapter never probes. Carts are reported but recovery is NOT
                // offered: Phase 3E's consent foundation has to exist first.
                'customers' => true,
                'orders' => true,
                'carts' => true,
                'order_actions' => false,
            ],
            'contract_version' => 'v1',
        ]);
    }

    /**
     * Qualified by the installation, never the bare primary key.
     *
     * Store ids are small sequential integers and two Store Syria installations both have a
     * store 1. Lynomia's uniqueness is global across the installation, so an unqualified id
     * would let a second deployment claim the first one's commerce connection.
     */
    private function externalStoreId(int $storeId): string
    {
        return (string) config('lynomia.installation_ref') . ':' . $storeId;
    }

    private function currency(): ?string
    {
        $value = \App\Models\Setting::where('name', 'defult_currancy')->value('value');

        return is_string($value) && $value !== '' ? strtoupper($value) : null;
    }
}
