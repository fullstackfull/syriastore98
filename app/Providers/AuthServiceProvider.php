<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\User::class  => \App\Policies\UserPolicy::class,
        \App\Models\Role::class  => \App\Policies\RolePolicy::class,
        \App\Models\Order::class => \App\Policies\OrderPolicy::class,
        // NEW-PLAN-ORDER-SHOW-IDOR-01. Enforced on the route through
        // App\Http\Middleware\PlanOrderViewGate, because the handler
        // (BankTransferController::show) is a plan-purchase gateway controller and belongs to
        // the payments workstream -- the same division PlatformOperatorOnly documents for
        // order.approve/order.reject on that same controller.
        \App\Models\PlanOrder::class => \App\Policies\PlanOrderPolicy::class,
        // P2-04-9. Registered so `$user->can('view', $savedView)` resolves; the screen-scoped
        // abilities (`viewAny`, `create`) take a string and are called on the policy instance
        // through the gate, which Laravel supports for a policy bound to the model class.
        \App\Models\SavedView::class => \App\Policies\SavedViewPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // SEC-F5. Theme/module installation unpacks an attacker-supplied archive
        // into the running application, so it is a platform-operator action with
        // no per-model subject -- a Gate, not a Policy.
        //
        // The contrast in AddonController is damning: :21 and :153 restrict the
        // methods that merely LIST themes to 'super admin', while ThemeInstall,
        // which extracts an uploaded zip, was open to every authenticated user.
        \Illuminate\Support\Facades\Gate::define('manage-platform-addons', function ($user) {
            return $user !== null && $user->type === \App\Policies\UserPolicy::PLATFORM_ADMIN;
        });

        // THE PERMISSION-SEEDING PROBLEM.
        //
        // The finance surface gates on six permission names:
        //
        //   View Finance Dashboard, Add Finance Balance, Deduct Finance Balance,
        //   Request Finance Withdrawal, View Finance Withdrawal Requests,
        //   Edit Finance Payment Status
        //
        // Utility::addNewData() is the ONLY mechanism in this application that
        // creates permission rows, and it NOW SEEDS ALL SIX of them
        // (app/Models/Utility.php, the FINANCE block inside $arrPermissions --
        // measured: `grep -c -i finance app/Models/Utility.php` === 18). It
        // creates the Permission ROWS ONLY and grants them to NO ROLE, which is
        // deliberate: four of the six move real money, so which role holds them
        // by default was left to the owner. The loop is additive and idempotent
        // and runs on every login, so a from-source database converges on the
        // six rows existing and nobody being silently handed a money power.
        //
        // CORRECTED 2026-11-04 (finance null-store guard slice). This note used
        // to say that addNewData() "contains ZERO occurrences of the string
        // 'Finance' (verified: `grep -c -i finance app/Models/Utility.php` === 0).
        // So on any database built from source, none of these six rows exists",
        // and it went on to call the seeding a pending cross-agent dependency
        // owned by "Agent 2". THAT IS NO LONGER TRUE: the seeding landed. The
        // same stale claim was also corrected in StoreFinanceController::
        // canFinance() in this change. A census of where it still stands, all
        // four REPORTED rather than edited because this slice does not own them:
        //
        //   app/Http/Middleware/FinancePermission.php     the same "contains zero
        //                                                 occurrences" wording.
        //   routes/web.php, above the withdrawal-review   its "Utility.php:
        //   routes                                        2881-2886" reference has
        //                                                 drifted to :3001-3006.
        //   tests/Feature/Security/FinancePermissionTest   class docblock -- and its
        //                                                 OWN assertion already
        //                                                 contradicts it, asserting
        //                                                 substr_count('finance') > 0.
        //   tests/Feature/Commerce/                       financeActor()'s docblock.
        //   WalletChangeIdempotencyTest
        //
        // LEAVING IT WAS THE REAL RISK, which is why it is corrected rather than
        // deleted. A frozen justification that has gone false reads as live
        // evidence: it says the delegated limb below is dead code on every
        // from-source database, so the next reader has a written reason not to
        // test a delegated user at all -- and the delegated user is exactly the
        // principal the null-store guard in StoreFinanceController is about, the
        // only one who can hold a Finance permission and resolve no store.
        //
        // WHAT HAS NOT CHANGED, AND WHY THE OPERATOR LIMB STAYS. Laratrust's
        // `permission:` middleware and User::isAbleTo() still have NO super-admin
        // bypass: they resolve strictly through the role/permission pivot tables,
        // so a row that exists but is granted to no role still denies EVERY user
        // including the platform operator. Seeding the rows did not make the
        // operator limb redundant -- it made the delegated limb reachable.
        //
        //   * super admin            -> allowed, WITHOUT consulting any seeded row
        //                               or grant. The same shape
        //                               OrderPolicy::allow() uses.
        //   * anyone else            -> the normal Laratrust check. The row now
        //                               exists, so a delegated user who has been
        //                               granted it is correctly allowed and one
        //                               who has not is correctly denied.
        \Illuminate\Support\Facades\Gate::define(
            'finance',
            function (?\App\Models\User $user, string $permission) {
                if ($user === null) {
                    return false;
                }

                // Seed-independent operator path.
                if ($user->type === \App\Policies\UserPolicy::PLATFORM_ADMIN) {
                    return true;
                }

                return $user->isAbleTo($permission);
            }
        );
    }
}
