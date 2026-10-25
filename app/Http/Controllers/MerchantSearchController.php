<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Policies\UserPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P2-06-2 -- the merchant's global search. Workstream H.
 * =============================================================================================
 *
 * THE RULE THIS SCREEN LIVES BY: a search box is an ENUMERATION ORACLE unless every section is
 * gated and every query is scoped. It is the one surface that reaches products, orders and
 * customers from a single, unauthenticated-looking text field, so the authorization is here,
 * per section, server-side, and never in the client that renders the results.
 *
 * TENANCY IS EXPLICIT IN EVERY QUERY, never inherited.
 *
 *   - `Product` and `Order` are NOT in `config/tenancy.php`'s `scoped_models` and register no
 *     effective global scope, so they add NO predicate for any principal. Their `store_id`
 *     filter below is the only thing standing between one merchant and another's catalogue.
 *   - `Customer` IS scoped -- and still gets an explicit predicate, because the project's rule
 *     is that the scope must never be the only tenant check: `null_tenant => 'open'` means a
 *     principal who resolves no tenant gets NO predicate at all from it.
 *
 * THE STORE COMES FROM THE PRINCIPAL, never from request input. `getCurrentStore($user->id)`
 * takes the id explicitly because the no-argument form reads the MUTABLE default guard, which
 * `actingAs` and `CustomAuth` can both repoint. A caller-supplied store id would not be a tenant
 * check at all -- it would compare the caller's own two values, which is the shape that has
 * produced four separate defects in this codebase.
 *
 * PERMISSIONS ARE PER SECTION, OPERATOR FIRST. Laratrust has no super-admin bypass, so
 * `isAbleTo()` alone refuses the platform operator. A merchant who may read orders but not
 * customers gets orders and no customers -- not a 403 for the whole box, which would make the
 * feature useless for staff.
 *
 * AND A SECTION THE CALLER MAY NOT SEE IS ABSENT, NOT EMPTY. An empty "Customers (0)" heading
 * tells a staff member that customers exist and they are not allowed to see them; it is a
 * smaller leak than the rows, but it is still an answer to a question they did not get to ask.
 */
class MerchantSearchController extends Controller
{
    /** Per section, so one noisy section cannot push another off the list. */
    private const LIMIT = 5;

    /** Shortest query we will run. One character matches most of a catalogue and tells nobody anything. */
    private const MIN_LENGTH = 2;

    public function search(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user === null) {
            return response()->json(['sections' => []], 401);
        }

        $raw = trim((string) $request->query('q', ''));

        /*
         * STRIPPED FIRST, THEN LENGTH-CHECKED, AND THE ORDER IS THE WHOLE POINT.
         *
         * The first version checked the length of the RAW term and stripped afterwards, so `%%`
         * -- two characters, passes the check -- became an EMPTY term and a `'%%'` pattern that
         * matches every row the caller's predicate admits. A length gate applied to the wrong
         * string is not a gate; it is a two-character dump of the caller's catalogue from a
         * search box. Caught before this shipped, by writing the wildcard case first.
         */
        $term = $this->strip($raw);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return response()->json(['sections' => [], 'term' => $raw]);
        }

        $isOperator = UserPolicy::isOperator($user);
        $storeId = getCurrentStore($user->id);

        /*
         * FAIL CLOSED ON AN UNRESOLVED TENANT, and this is the limb that matters most.
         *
         * A principal with no current store would otherwise reach the queries below with
         * `where('store_id', null)`, which matches nothing for products and orders -- but for
         * `Customer` the GLOBAL SCOPE also contributes nothing under `null_tenant => 'open'`, so
         * an unscoped read of every tenant's customers is exactly what would come back. Refusing
         * here is the difference between "no results" and "all results".
         *
         * The operator is exempt because reading across stores is their contract, and every
         * other controller in this application already treats them that way.
         */
        if (! $isOperator && empty($storeId)) {
            return response()->json(['sections' => [], 'term' => $term]);
        }

        $sections = [];

        if ($this->may($user, $isOperator, 'Manage Products')) {
            $sections[] = [
                'key' => 'products',
                'label' => __('Products'),
                'items' => $this->products($term, $storeId, $isOperator),
            ];
        }

        if ($this->may($user, $isOperator, 'Manage Order')) {
            $sections[] = [
                'key' => 'orders',
                'label' => __('Orders'),
                'items' => $this->orders($term, $storeId, $isOperator),
            ];
        }

        if ($this->may($user, $isOperator, 'Manage Customer')) {
            $sections[] = [
                'key' => 'customers',
                'label' => __('Customers'),
                'items' => $this->customers($term, $storeId, $isOperator),
            ];
        }

        // A section with no hits is dropped, so the palette shows what was found rather than a
        // column of empty headings.
        $sections = array_values(array_filter($sections, fn (array $s) => $s['items'] !== []));

        return response()->json(['sections' => $sections, 'term' => $raw]);
    }

    /** Operator first -- Laratrust has no super-admin bypass. */
    private function may($user, bool $isOperator, string $permission): bool
    {
        return $isOperator || $user->isAbleTo($permission);
    }

    /**
     * The LIKE pattern for a caller-supplied term.
     *
     * `%` and `_` are WILDCARDS in LIKE, so a term carrying either would WIDEN the match rather
     * than narrow it: `%` alone returns every row the caller's own predicate admits, which is a
     * free dump of their whole catalogue from a search box.
     *
     * THEY ARE STRIPPED, NOT ESCAPED, AND THAT IS A PORTABILITY DECISION. Escaping them needs a
     * declared `ESCAPE` clause, because MySQL defaults to backslash and SQLITE HAS NO DEFAULT AT
     * ALL -- so `LIKE '%50\%%'` matches "50%" on MySQL and matches NOTHING on SQLite. Measured:
     * the escaped version failed the "a literal percent is still findable" case on the test
     * engine while passing by inspection. Declaring the clause means raw SQL in a controller,
     * which this repository inventories and ratchets for good reasons.
     *
     * Stripping costs one thing and it is small: a term is matched without its wildcards, so
     * searching for "50%" finds "50% Linen Mix" through the "50". Nobody loses a result; the
     * only behaviour removed is the one that was a leak.
     */
    private function strip(string $term): string
    {
        return str_replace(['%', '_'], '', $term);
    }

    /** `$term` has already been through `strip()` by the time it reaches here. */
    private function like(string $term): string
    {
        return '%' . $this->strip($term) . '%';
    }

    private function products(string $term, $storeId, bool $isOperator): array
    {
        $query = Product::query()
            ->whereNull('product_type')
            ->where('name', 'LIKE', $this->like($term));

        if (! $isOperator) {
            $query->where('store_id', $storeId);
        }

        return $query->orderBy('name')->limit(self::LIMIT)->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'title' => $p->name,
                'meta' => $p->product_stock !== null ? __(':n in stock', ['n' => $p->product_stock]) : null,
                'href' => route('product.edit', $p->id),
            ])->all();
    }

    private function orders(string $term, $storeId, bool $isOperator): array
    {
        $query = Order::query()
            ->where('product_order_id', 'LIKE', $this->like($term));

        if (! $isOperator) {
            $query->where('store_id', $storeId);
        }

        return $query->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'title' => '#' . $o->product_order_id,
                'meta' => $o->order_date ? \Carbon\Carbon::parse($o->order_date)->isoFormat('D MMM YYYY') : null,
                // The PREVIEW, not the full page: it is the read-only surface, and its own
                // uri is registered in OrderDocumentAccessGuard, which answers again on that
                // request. Nothing here is taken as authorisation for that one.
                'href' => route('order.preview', $o->id),
                'panel' => 'ss-order-preview-panel',
            ])->all();
    }

    private function customers(string $term, $storeId, bool $isOperator): array
    {
        /*
         * `withoutGlobalScopes()` is deliberately NOT used. Customer IS scoped, and the explicit
         * predicate below is belt to its braces rather than a replacement -- removing the scope
         * and relying on the predicate alone would make this the only customer read in the
         * application that does so.
         */
        /*
         * `first_name` / `last_name`, NOT `name`. The customers table has no `name` column --
         * read from the live schema after a `where('name', ...)` failed outright. Searching a
         * concatenation in SQL would not be portable between MySQL and SQLite, so each part is
         * matched separately and the display name is assembled in PHP.
         *
         * THE GROUPING PARENTHESES ARE LOAD-BEARING. Without the closure the `orWhere` chain
         * would sit at the same level as the store predicate below, and SQL's OR binds looser
         * than AND -- so `store_id = X AND a OR b OR c` matches every row satisfying b or c in
         * ANY store. That is a one-character cross-tenant leak and it is why this is written
         * this way.
         */
        $query = Customer::query()->where(function ($q) use ($term) {
            $q->where('first_name', 'LIKE', $this->like($term))
                ->orWhere('last_name', 'LIKE', $this->like($term))
                ->orWhere('email', 'LIKE', $this->like($term));
        });

        if (! $isOperator) {
            $query->where('store_id', $storeId);
        }

        return $query->orderBy('first_name')->limit(self::LIMIT)->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'title' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ($c->email ?? ''),
                'meta' => $c->email,
                'href' => route('customer.index'),
            ])->all();
    }
}
