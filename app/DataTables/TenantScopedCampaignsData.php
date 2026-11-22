<?php

namespace App\DataTables;

use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\Campaigns\app\Models\Campaigns;
use Workdo\Campaigns\DataTables\AllCampaignsData;

/**
 * P1-SEC-69 — THE CAMPAIGNS LIST, SCOPED TO THE CALLER'S OWN TENANT.
 * =================================================================================================
 *
 * `Workdo\Campaigns\DataTables\AllCampaignsData::query()` (vendored, not edited) applies its
 * tenant predicate only when the caller's `users.type` is the literal `'admin'`:
 *
 *     if (Auth::user()->type == 'admin') { $query->where('created_by', Auth::user()->creatorId()); }
 *
 * In this product `'admin'` is a merchant OWNER's type. A staff member's type is the NAME OF THE
 * ROLE THEY HOLD, so for staff the condition is false and NO predicate is applied — and `campaigns`
 * is not in `config/tenancy.php`'s `scoped_models`, so nothing else adds one.
 *
 * MEASURED 2026-11-22 by driving the real route with the module loaded: a merchant's staff member
 * received `recordsTotal: 2` — their own tenant's campaign and ANOTHER TENANT'S, with that
 * tenant's store name, product name and the name of the user who created it. The owner received 1.
 *
 * AND THE READ WAS ALSO A WRITE. The vendored `query()` ends by saving an expired status onto
 * every row the query returned, so merely opening the list mutated other tenants' rows — a status
 * change with no actor, in a tenant whose staff never visited the page.
 *
 * ===========================================================================================
 *  WHAT THIS CHANGES, AND WHAT IT DELIBERATELY DOES NOT
 * ===========================================================================================
 *
 * The predicate is applied to EVERY principal except the platform operator, through
 * `UserPolicy::isOperator()` — this project's one definition of that principal, which normalises
 * the way MySQL's PAD SPACE does — and its VALUE comes from `UserPolicy::merchantTenantRoot()`,
 * which refuses a root that is not a type-`admin` merchant. See the method for why `creatorId()`
 * alone is wrong here. The operator's cross-tenant view is the vendored module's own
 * design: Campaigns is a PAID PLACEMENT on the platform's landing page, which the operator prices
 * and approves, so a list of every tenant's campaigns is what that role is for. This fix narrows
 * what a MERCHANT sees; it does not decide whether the operator should see it.
 *
 * For an ordinary merchant and their staff the value is the one the vendored code used, so an
 * owner's list is byte-identical to before — which is what the two positive controls in the test
 * assert. What changes is that staff now get the predicate their owner always had, and that a
 * principal with no merchant tenant root gets nothing instead of everything.
 *
 * THE EXPIRY LOOP IS KEPT, SCOPED. Removing it would be the simpler change and the wrong one: a
 * merchant's own expired campaign would stop being marked expired, which is behaviour this fix has
 * no business removing. It now runs over the same scoped query, so it can only ever write to rows
 * the caller may already see. A test asserts both halves — another tenant's row unchanged, the
 * caller's own still marked.
 *
 * ===========================================================================================
 *  THE VEHICLE: A BINDING, BECAUSE VENDORED CODE IS NOT EDITED
 * ===========================================================================================
 *
 * Bound over the module's class in `AppServiceProvider`, which is the class the module's own
 * controller injects (`CampaignsController::index(AllCampaignsData $dataTable, Request $request)`),
 * so Laravel resolves this subclass. That is the mechanism P1-SEC-75 already uses for the three
 * Helpdesk lists, and it leaves `packages/workdo/` untouched.
 *
 * ONLY `query()` IS REPLACED. `dataTable()`, the columns, the HTML builder and the filename stay
 * the vendored ones — this is a tenancy fix, not a re-implementation, and every cell the module
 * renders is unchanged. `parent::query()` is NOT called: it would run the unscoped expiry loop
 * before this method could narrow anything.
 */
final class TenantScopedCampaignsData extends AllCampaignsData
{
    public function query(Campaigns $model, Request $request): QueryBuilder
    {
        $user = Auth::user();

        $query = $model->newQuery();
        $query->with('stores', 'users');

        // The vendored filters, unchanged: each is an equality on a column, from the filter bar.
        foreach (['user', 'store', 'product'] as $field) {
            if (! empty($request->{$field})) {
                $query->where($field, $request->{$field});
            }
        }

        if (! empty($request->start_date)) {
            $query->where('start_date', '>=', $request->start_date);
        }

        if (! empty($request->end_date)) {
            $query->where('end_date', '<=', $request->end_date);
        }

        $query->orderBy('id', 'desc');

        /*
         * THE TENANT PREDICATE, FOR EVERY PRINCIPAL BUT THE OPERATOR, AND IT FAILS CLOSED.
         *
         * The vendored line asked `type == 'admin'` — a merchant OWNER's type — so staff fell
         * through it and got no predicate.
         *
         * `merchantTenantRoot()` AND NOT `creatorId()`, which is the correction an earlier version
         * of this guard needed. `creatorId()` returns the OPERATOR's id for a principal whose root
         * is the operator: staff the operator created, and a merchant who changed their own `type`
         * to a role name — which the product allows, and which CLAUDE.md records as having been an
         * account takeover when `created_by = 1` was read as a tenant. Scoping such a principal to
         * `creatorId()` would show them the OPERATOR's campaigns:
         *
         *   the vendored line   ->  no predicate: every tenant's campaigns
         *   `creatorId()`       ->  the operator's campaigns
         *   this                ->  nothing, until their own type is a tenant root
         *
         * `merchantTenantRoot()` returns `creatorId()` only when it names a type-`admin` merchant
         * and null otherwise — the helper the P1-SEC-66/67 family built for this — and a null,
         * like a missing principal, binds a tenant no row can have.
         */
        $tenant = UserPolicy::merchantTenantRoot($user);

        if (! UserPolicy::isOperator($user)) {
            $query->where('created_by', $tenant ?? 0);
        }

        // The vendored expiry, over the SCOPED query: a listing may not write outside its tenant.
        $currentDate = now();

        $query->get()->each(function ($campaign) use ($currentDate) {
            if ($campaign->end_date !== null && $currentDate->greaterThan($campaign->end_date)) {
                $campaign->status = 2;
                $campaign->save();
            }
        });

        return $query;
    }
}
