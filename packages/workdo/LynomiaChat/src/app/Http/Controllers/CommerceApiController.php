<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Workdo\LynomiaChat\app\Http\Middleware\AuthenticateCommerceApi;
use Workdo\LynomiaChat\app\Services\CommerceReader;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

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
     * Candidates for ONE verified identifier. Exactly one of email or phone, never both and
     * never a name: a name search is how one customer is shown another's orders, and the
     * contract has no endpoint for it.
     *
     * Candidates only. The adapter verifies each one carries the identifier it asked for and
     * drops the rest, so a loose match here cannot become a wrong match there.
     */
    public function lookupCustomers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'string', 'email', 'max:255', 'required_without:phone', 'prohibits:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
        ]);

        $reader = $this->reader($request);

        return $this->collection(isset($data['email'])
            ? $reader->customersByEmail($data['email'])
            : $reader->customersByPhone($data['phone']));
    }

    /**
     * This customer's orders in THIS store. The customer reference is the one this surface
     * issued in a lookup; it is read as a reference and never as a tenant.
     */
    public function customerOrders(Request $request, string $customer): JsonResponse
    {
        return $this->collection(
            $this->reader($request)->customerOrders($customer, $this->limit($request, CommerceReader::MAX_ORDERS))
        );
    }

    public function order(Request $request, string $order): JsonResponse
    {
        if (! ctype_digit($order)) {
            // An order id is a primary key. Anything else is a malformed request, not a 404 that
            // tells a caller whether some other store's order exists.
            throw ValidationException::withMessages(['order' => 'Invalid order id.']);
        }

        $payload = $this->reader($request)->order((int) $order);

        // An order of another store and an order that does not exist answer identically: the
        // reader's query is already scoped, so there is no state here to probe for.
        return $payload === null
            ? response()->json(['error' => 'Not found'], 404)
            : response()->json($payload);
    }

    /**
     * Order search by the number printed on the order, which Store Syria keeps separately from
     * the primary key (orders.product_order_id).
     */
    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:64']]);

        return $this->collection($this->reader($request)->ordersByNumber($data['number']));
    }

    /**
     * Carts left behind, reported and nothing more. Offering RECOVERY is a separate decision
     * that waits on the consent model; see the contract.
     */
    public function abandonedCarts(Request $request): JsonResponse
    {
        $data = $request->validate(['customer' => ['nullable', 'string', 'max:64']]);

        return $this->collection($this->reader($request)->abandonedCarts(
            $data['customer'] ?? null,
            $this->limit($request, CommerceReader::MAX_CARTS)
        ));
    }

    /**
     * The store comes from the credential, through the middleware, and from nowhere else. No
     * handler above resolves a tenant any other way, and there is no parameter that widens it.
     */
    private function reader(Request $request): CommerceReader
    {
        return new CommerceReader((int) $request->attributes->get(AuthenticateCommerceApi::STORE_ID));
    }

    /**
     * Always wrapped. The adapter refuses a bare array on purpose: a bare array means a
     * different server answered.
     */
    private function collection(array $rows): JsonResponse
    {
        return response()->json(['data' => $rows]);
    }

    private function limit(Request $request, int $ceiling): int
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:' . $ceiling]]);

        return (int) $request->query('limit', (string) $ceiling);
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
        return StoreIdentity::of($storeId);
    }

    private function currency(): ?string
    {
        $value = \App\Models\Setting::where('name', 'defult_currancy')->value('value');

        return is_string($value) && $value !== '' ? strtoupper($value) : null;
    }
}
