<?php

namespace App\View\Composers;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * P1-SEC-69 (b) — THE CAMPAIGNS SCREEN'S STORE, PRODUCT AND USER LISTS, SCOPED TO THE CALLER'S TENANT.
 * =================================================================================================
 *
 * Scoping the Campaigns DataTable scoped the ROWS. Independent review of that fix pointed out that
 * the same endpoint builds three more lists, and `Workdo\Campaigns\...\CampaignsController` builds
 * each with the bare predicate this branch has been removing everywhere else:
 *
 *     Store::where('created_by', Auth::user()->creatorId())      // $catList, and $store in create()
 *     Product::where('created_by', Auth::user()->creatorId())    // $productList
 *     User::get()                                                // $userList — no predicate at all
 *
 * For a merchant owner and their staff `creatorId()` IS the tenant root, so those two are right and
 * this composer reproduces them exactly. For a principal ROOTED AT THE OPERATOR — staff the
 * operator created, or a merchant who changed their own `type` to a role name — `creatorId()` is the
 * OPERATOR's id, so the screen offered the operator's store and product names. MEASURED through the
 * real routes with the module loaded (`CampaignsFilterListsTenantScopeTest`), on the filter bar and
 * on the create form, before this existed. `Store` and `Product` add no global scope of their own
 * (CLAUDE.md §1), so that predicate was the whole control.
 *
 * `$userList` IS DIFFERENT, AND THE REVIEW'S READING OF IT IS REFUTED. It is built from
 * `User::get()` with no predicate, which the review reported as every platform user's name and id
 * served to any entitled merchant. Measured: `index.blade.php` wraps that `<select>` in
 * `@if (Auth::user()->type == 'super admin')`, so for everybody else the list is fetched and never
 * printed. Both directions are pinned by test. It is narrowed here anyway, because "a template
 * happens not to print it" is not a control and the next template might.
 *
 * ===========================================================================================
 *  WHY A VIEW COMPOSER
 * ===========================================================================================
 *
 * `packages/workdo/` is vendored and is not edited. The list fix had a class to bind over
 * (`AllCampaignsData`, which the controller takes by injection); these three lists are built inline
 * in the controller's own body, so there is no seam there. A composer runs after the controller has
 * handed its data to the view and before the view renders, and `$view->with()` replaces a key the
 * controller set — so this is the last writer of `catList`, `productList`, `userList` and `store`,
 * whatever the handler put there. It touches nothing else on the screen.
 *
 * The one list it CANNOT reach is `POST campaigns-product` (`productData()`), which answers JSON
 * and renders no view. That one is guarded on the route instead, by
 * `ModuleRouteManifest`'s `tenant-store:store_id` rule — the body's `store_id` must name a store of
 * the caller's own tenant.
 *
 * THE OPERATOR IS LEFT ALONE. Their platform-wide lists are the module's own design: Campaigns is a
 * paid placement on the platform's landing page, which the operator prices and approves, and the
 * controller gives a `super admin` the unscoped lists deliberately. This narrows what a MERCHANT
 * sees; it does not decide what the operator should see.
 */
class CampaignsTenantFilterScope
{
    /** The views whose lists this composes, and the key each one reads. */
    public const VIEWS = ['campaigns::index', 'campaigns::create'];

    public function compose(View $view): void
    {
        $lists = $this->listsFor(Auth::guard('web')->user());

        if ($lists !== null) {
            $view->with($lists);
        }
    }

    /**
     * The four lists for a principal, or NULL to leave the handler's own untouched.
     *
     * SEPARATE FROM `compose()` ON PURPOSE, so that every branch can be asserted. Three of the four
     * keys are observable through the rendered screen; `userList` is NOT -- `index.blade.php` prints
     * that `<select>` only for a `super admin`, so a change that widened it back to `User::get()`
     * would be invisible to any test that drives the route. This method is what the test drives
     * instead (`CampaignsFilterListsTenantScopeTest`), and it is the same code path the view uses.
     *
     * @return array<string, \Illuminate\Support\Collection>|null
     */
    public function listsFor(?User $user): ?array
    {
        // The operator keeps the module's platform-wide lists (see the class docblock). A request
        // with no `web` principal does NOT: it gets the fail-closed branch, because the module's own
        // `if (! $user)` refusal is a line in code this project does not control.
        if ($user !== null && UserPolicy::isOperator($user)) {
            return null;
        }

        $tenant = $user === null ? null : UserPolicy::merchantTenantRoot($user);

        return [
            // The vendored labels, character for character: the blade's `0` option and its wording
            // are part of the screen, and this must leave an ordinary merchant's page unchanged.
            'catList' => $this->stores($tenant)->prepend('Select store', 0),
            'store' => $this->stores($tenant)->prepend('Select store', 0),
            'productList' => $this->products($tenant)->prepend('Select product', 0),
            'userList' => $this->users($tenant)->prepend('Select User', 0),
        ];
    }

    /**
     * A tenant's stores, or nothing.
     *
     * `$tenant === null` is a principal with no merchant tenant root, and it binds a `created_by`
     * no row can have rather than skipping the predicate — the same fail-closed sentinel the list
     * guard uses, for the same reason: `Store` is not in `config/tenancy.php`'s `scoped_models`, so
     * a missing predicate here is every tenant's stores.
     */
    private function stores(?int $tenant): \Illuminate\Support\Collection
    {
        return Store::where('created_by', $tenant ?? 0)->pluck('name', 'id');
    }

    private function products(?int $tenant): \Illuminate\Support\Collection
    {
        return Product::where('created_by', $tenant ?? 0)->pluck('name', 'id');
    }

    /**
     * The caller's own tenant: its root and the users that root created.
     *
     * NOT `User::get()`. The filter is over `campaigns.user`, which the module writes as the id of
     * whoever created the campaign, and the campaigns this caller can see are their own tenant's —
     * so a wider list would offer ids that can match no row the caller may read, while naming
     * people in other tenants.
     */
    private function users(?int $tenant): \Illuminate\Support\Collection
    {
        if ($tenant === null) {
            return collect();
        }

        return User::where(function ($query) use ($tenant) {
            $query->where('id', $tenant)->orWhere('created_by', $tenant);
        })->pluck('name', 'id');
    }
}
