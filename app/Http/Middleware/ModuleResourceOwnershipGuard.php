<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-INV-17 / #63. FOUR CROSS-TENANT WRITES AND TWO CROSS-TENANT READS BEHIND AuctionProduct's
 * `Route::resource` ROUTES, THREE OF THEM ON THE HOST `products` TABLE AND ONE THAT MOVES ANOTHER
 * STORE'S ROW.
 * =============================================================================================
 *
 * `ModuleOrderMutationGuard` is this guard's sibling: same vehicle, same shared predicate
 * (App\Http\Middleware\Concerns\ComparesRowOwnership), different id source. That one closed six
 * ANONYMOUS mutations declared in `['web']`-only groups. These four are not anonymous -- they sit
 * in the module's own `['auth','web','PlanModuleCheck:AuctionProduct']` group -- and they are
 * reachable by any merchant whose PLAN carries the module, against any other tenant's rows:
 *
 *   packages/workdo/AuctionProduct/src/routes/web.php
 *     :20  Route::resource('auctionproduct',  AuctionProductController::class)
 *     :21  Route::resource('auctionsetting', AuctionProductSettingController::class)
 *     :22  Route::resource('auctionorder',   AuctionOrdersController::class)
 *
 * | route + method                      | handler                                   | what it does          |
 * |-------------------------------------|-------------------------------------------|-----------------------|
 * | PUT/PATCH auctionproduct/{id}       | AuctionProductController::update:861      | HOST Product, bare id |
 * | DELETE    auctionproduct/{id}       | AuctionProductController::destroy:1509    | HOST Product + images |
 * | PUT/PATCH auctionsetting/{id}       | AuctionProductSettingController::update:115 | MOVES store_id      |
 * | DELETE    auctionsetting/{id}       | AuctionProductSettingController::destroy:140 | delete             |
 * | DELETE    auctionorder/{id}         | AuctionOrdersController::destroy:105      | delete                |
 * | GET  auctionproduct/{id}/edit        | AuctionProductController::edit:823        | HOST Product, READ    |
 * | GET  auctionsetting/{id}/edit        | AuctionProductSettingController::edit:92  | READ, no permission   |
 *
 * THE `auctionproduct` PAIR IS THE WORST, and it is not an auction table: both resolve
 * `App\Models\Product::find($id)`, the HOST catalogue model, which carries NO EFFECTIVE GLOBAL
 * SCOPE -- it is absent from config/tenancy.php's `scoped_models`, so `StoreScope::isActiveFor()`
 * short-circuits for it. So this is a cross-tenant catalogue EDIT and DELETE, taking
 * `ProductImage` rows and whatever `products.category_id`'s ON DELETE CASCADE takes with it.
 * P1-INV-01 closed the same defect on the host `ProductController`; the auction controller is a
 * separate copy in vendored code that it could not reach.
 *
 * THE PERMISSIONS ARE NO BARRIER TO A MERCHANT. `Edit Auction Product` and
 * `Delete Auction Product` are both created by the module's own
 * database/seeders/PermissionTableSeeder.php:19-51 and GRANTED TO THE GLOBAL `admin` ROLE, which
 * every self-registered merchant holds. They are a barrier only to staff on a narrower role.
 * `AuctionProductSettingController` and `AuctionOrdersController::destroy` check no permission at
 * all. This guard still requires them -- existence-conditionally, see the trait -- because a
 * guard that drops a check the handler makes is a regression even when the check is weak.
 *
 * THE `auctionsetting` UPDATE IS A DIFFERENT SHAPE FROM THE OTHER FOUR AND THE DIFFERENCE MATTERS.
 * It writes `$setting->store_id = getCurrentStore()` (`:126`), so it does not merely modify
 * another store's row -- it MOVES it into the caller's tenant. The victim's row vanishes from
 * their own `where('store_id', ...)` listings and appears in the attacker's, which makes it far
 * harder to notice than an overwrite. Refusing the request before the handler runs is therefore
 * the whole fix for it; there is no "scope the lookup" half-measure.
 *
 * ===========================================================================================
 *  THE ID COMES FROM THE ROUTE PARAMETER ONLY, WHICH IS THE OPPOSITE OF THE SIBLING GUARD.
 * ===========================================================================================
 *
 * Every one of these handlers takes `$id` as a METHOD ARGUMENT -- `update(Request $request, $id)`,
 * `destroy($id)` -- so the route parameter is what they act on and a posted body key cannot
 * change it. The sibling guard reads the body FIRST because its handlers read `$request->id`, and
 * `Request::__get` reads `all()` before the route parameters. Same principle, opposite answer:
 * read what the handler reads. Reading the body here would be the P1-ORD-04 bug in mirror image
 * -- a crafted `?auctionsetting=<own id>` would authorise the caller's own row while the handler
 * deleted another store's.
 *
 * ===========================================================================================
 *  TARGETS ARE KEYED ON METHOD+URI, NOT URI, AND THAT IS NOT TIDINESS.
 * ===========================================================================================
 *
 * `Route::resource` puts GET (show), PUT, PATCH and DELETE on the same uri. Keying on uri alone
 * would mean one entry deciding all four, and they are not the same question: the write methods
 * need different permissions from each other, and `show()` is a stub -- both resources' `show()`
 * is `return view(...)` with no model read.
 *
 * AND THE READS ARE NOT ALL ON THAT URI, WHICH THE FIRST VERSION OF THIS DOCBLOCK MISSED. The
 * original said "every other method on the same uri passes through untouched -- stated here so
 * nobody reads this guard as covering the resources' reads", which was true and useless: `edit`
 * is registered at `<base>/{param}/edit`, a DIFFERENT uri, so that sentence did not reach it, and
 * the only read it named was the harmless one. Two of the three `edit()` handlers were live
 * cross-tenant reads with no permission check. They are now targets -- see their entries.
 *
 * `auctionorder`'s own `update()` is a `//` stub and is guarded anyway, because a stub that
 * upstream fills in later must not become an unguarded write.
 */
class ModuleResourceOwnershipGuard
{
    use ComparesRowOwnership;

    /**
     * 'METHOD uri' => [table, route parameter name, required permission or null].
     *
     * The route parameter name is the resource's own singular, which is what `Route::resource`
     * registers; `test_every_guard_target_is_a_real_registered_route` asserts each one against
     * the live route table so a rename upstream cannot leave this guard silently inert.
     */
    private const TARGETS = [
        // --- auctionproduct: the HOST products table. ---
        'PUT auctionproduct/{auctionproduct}' => [
            'table' => 'products',
            'parameter' => 'auctionproduct',
            'permission' => 'Edit Auction Product',
        ],
        'PATCH auctionproduct/{auctionproduct}' => [
            'table' => 'products',
            'parameter' => 'auctionproduct',
            'permission' => 'Edit Auction Product',
        ],
        'DELETE auctionproduct/{auctionproduct}' => [
            'table' => 'products',
            'parameter' => 'auctionproduct',
            'permission' => 'Delete Auction Product',
        ],

        // --- auctionsetting: the module's own table. The handlers check NO permission; the
        //     module seeds 'Edit Auction Setting' and 'Delete Auction Setting', which is what
        //     this guard requires. ---
        'PUT auctionsetting/{auctionsetting}' => [
            'table' => 'auction_product_settings',
            'parameter' => 'auctionsetting',
            'permission' => 'Edit Auction Setting',
        ],
        'PATCH auctionsetting/{auctionsetting}' => [
            'table' => 'auction_product_settings',
            'parameter' => 'auctionsetting',
            'permission' => 'Edit Auction Setting',
        ],
        'DELETE auctionsetting/{auctionsetting}' => [
            'table' => 'auction_product_settings',
            'parameter' => 'auctionsetting',
            'permission' => 'Delete Auction Setting',
        ],

        /*
         * --- THE `edit` READS, ADDED AFTER THE SECOND REVIEW AND VERIFIED BY EXECUTION ---
         *
         * `Route::resource` registers `edit` at a DIFFERENT URI -- `<base>/{param}/edit` -- so this
         * guard's original "every other method on the same uri passes through untouched" sentence
         * never reached them, and the only read it named was the harmless `show()` stub. Two of
         * the three resources' edit() handlers load the target by bare id with NO PERMISSION CHECK
         * AT ALL and render it into a Form::model() view.
         *
         * MEASURED, not read: a probe reproduced both. A merchant holding NO auction permission,
         * with only the plan entitlement, got HTTP 200 and another store's product name, slug,
         * price, sale_price, description, specification, detail, custom_field JSON, stock posture
         * and the file paths of its cover image, downloadable product and preview asset; and
         * another store's auction setting name and all four date/time fields.
         *
         * THE ASYMMETRY IS THE SHAPE OF THE HOLE: `Edit Auction Product` IS checked by update() on
         * the identical uri, and the module's own UI gates the Edit BUTTON on it
         * (resources/views/action.blade.php:1, Laratrust `@permission` -- which resolves through
         * hasPermission() and NOT through Laravel's Gate, so it is unaffected by
         * `permissions_as_gates => false`). So the write is gated and the read is not, which is
         * the opposite of what a reviewer comparing the two methods would assume.
         *
         * NO FALSE DENIAL: every non-operator who legitimately reaches these screens already holds
         * the name, because the button is gated on it. The platform operator is the one principal
         * for whom `isAbleTo()` is false, and the trait checks the operator FIRST -- so adding a
         * permission requirement here cannot lock them out of a page they can reach by URL today.
         *
         * `auctionorder/{id}/edit` is deliberately NOT a target: its handler is
         * `return view('auction-product::auctionorder.edit');` with no model read and no id use,
         * and that view does not exist, so it is a 500 for everyone. There is no row read to
         * become a leak. If upstream ever writes one, the action-keyed census below produces it.
         */
        'GET auctionproduct/{auctionproduct}/edit' => [
            'table' => 'products',
            'parameter' => 'auctionproduct',
            'permission' => 'Edit Auction Product',
        ],
        'GET auctionsetting/{auctionsetting}/edit' => [
            'table' => 'auction_product_settings',
            'parameter' => 'auctionsetting',
            'permission' => 'Edit Auction Setting',
        ],

        // --- auctionorder: destroy() is live and checks nothing; update() is a `//` stub,
        //     guarded so that upstream filling it in cannot create an unguarded write. The
        //     module seeds 'Delete Auction Product Order'. ---
        'DELETE auctionorder/{auctionorder}' => [
            'table' => 'auction_product_orders',
            'parameter' => 'auctionorder',
            'permission' => 'Delete Auction Product Order',
        ],
        'PUT auctionorder/{auctionorder}' => [
            'table' => 'auction_product_orders',
            'parameter' => 'auctionorder',
            'permission' => 'Manage Auction Product Order',
        ],
        'PATCH auctionorder/{auctionorder}' => [
            'table' => 'auction_product_orders',
            'parameter' => 'auctionorder',
            'permission' => 'Manage Auction Product Order',
        ],

        /*
         * --- DigitalProduct: AuctionProduct's TWIN, P1-INV-23 ---
         *
         * The module was cloned from AuctionProduct, so `DigitalProductController::edit/update/destroy` are the same three handlers:
         * `App\Models\Product::find($id)` (the host catalogue, no effective global scope), the permission checked in update()/destroy() and
         * never the owner, and the product's images and the files they name destroyed with it. Any merchant whose plan carries the module
         * could read, rewrite or delete another tenant's product. Found by the read-only review of P1-SEC-28 and reproduced by
         * DigitalProductOwnershipTest before this entry existed.
         *
         * AND ONE ROUTE THAT HAS NO `auth` AT ALL -- the shape ModuleOrderMutationGuard's six routes had:
         *   routes/web.php:20  Route::middleware(['web'])->group(... Route::delete('digital-products/{id}/delete', ..., 'file_delete'))
         * file_delete($id) finds a DigitalProductImage by bare id, unlinks the file its `image_path` names and deletes the row. An anonymous
         * caller could delete any tenant's product image and file by counting integers. It is the one target for which the `auth` premise
         * in the census does NOT hold, which is why the guard's own anonymous limb above is not belt-and-braces here: it IS the
         * authentication requirement. `digital_product_images` carries its own store_id, so the shared row read works as it is.
         *
         * `show()` is a view stub with no model read, as in the auction resource, and is left alone.
         */
        'GET digitalproduct/{digitalproduct}/edit' => [
            'table' => 'products',
            'parameter' => 'digitalproduct',
            'permission' => 'Edit Digital Product',
        ],
        'PUT digitalproduct/{digitalproduct}' => [
            'table' => 'products',
            'parameter' => 'digitalproduct',
            'permission' => 'Edit Digital Product',
        ],
        'PATCH digitalproduct/{digitalproduct}' => [
            'table' => 'products',
            'parameter' => 'digitalproduct',
            'permission' => 'Edit Digital Product',
        ],
        'DELETE digitalproduct/{digitalproduct}' => [
            'table' => 'products',
            'parameter' => 'digitalproduct',
            'permission' => 'Delete Digital Product',
        ],
        'DELETE digital-products/{id}/delete' => [
            'table' => 'digital_product_images',
            'parameter' => 'id',
            'permission' => 'Edit Digital Product',
        ],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // FIRST STATEMENTS, so this guard is inert on every other route in the application.
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $target = self::TARGETS[$request->method() . ' ' . $route->uri()] ?? null;

        if ($target === null) {
            return $next($request);
        }

        /*
         * EXPLICIT GUARD NAME, NEVER auth()->user(). The default guard is mutable -- CustomAuth
         * logs a token's owner into whichever of the three guards matches its tokenable class,
         * and actingAs($customer, 'customers') sets it in tests -- so auth()->user() can return
         * an App\Models\Customer and isAbleTo() would be a fatal on a model with no such method.
         *
         * Anonymous CANNOT reach these routes: they are in the module's own `auth` group, and
         * `Authenticate` runs ahead of the `web` group's appended middleware. The null limb is
         * here anyway, because "the group has auth today" is a property of a vendored file that
         * CI forbids this repository from editing and upstream is free to change -- which is
         * exactly how the sibling guard's six routes came to be anonymous.
         */
        $user = auth('web')->user();

        if ($user === null) {
            return $this->deny($request, 'anonymous');
        }

        if ($this->isPlatformOperator($user)) {
            return $next($request);
        }

        if ($this->permissionIsEnforceable($target['permission'])
            && ! $user->isAbleTo($target['permission'])) {
            return $this->deny($request, 'permission');
        }

        // THE ROUTE PARAMETER, AND NOTHING ELSE. See the docblock: these handlers take $id as a
        // method argument, so a posted body key cannot change which row they act on, and reading
        // one here would authorise the wrong row.
        $rowId = $this->usableId($route->parameter($target['parameter']));

        if ($rowId === null) {
            return $this->deny($request, 'no id');
        }

        [$status, $rowStore] = $this->readRowStore($target['table'], $rowId);

        if ($status !== 'ok') {
            return $this->deny($request, $status);
        }

        if (! $this->storeMatches($user, $rowStore)) {
            return $this->deny($request, 'other store');
        }

        return $next($request);
    }

    /**
     * Refuse.
     *
     * These five are FORM submissions whose handlers `redirect()->back()`, not AJAX endpoints
     * like the sibling guard's, so a plain 403 is the right answer for a browser; the JSON branch
     * is kept for a caller that asks for it. `$reason` is logged, never returned: the response
     * must not say whether the row exists, belongs elsewhere, or was never named.
     */
    private function deny(Request $request, string $reason): Response
    {
        $actorId = optional(auth('web')->user())->id;

        Log::warning('module resource mutation refused', [
            'reason' => $reason,
            'method' => $request->method(),
            'uri' => $request->route() ? $request->route()->uri() : null,
            'user_id' => $actorId,
            'current_store' => $actorId === null ? null : getCurrentStore($actorId),
        ]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['status' => 'error', 'message' => __('Forbidden.')], 403);
        }

        abort(403);
    }
}
