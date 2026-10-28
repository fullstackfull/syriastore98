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
        // creates permission rows, and it contains ZERO occurrences of the string
        // "Finance" (verified: `grep -c -i finance app/Models/Utility.php` === 0).
        // So on any database built from source, none of these six rows exists.
        //
        // Laratrust's `permission:` middleware and User::isAbleTo() have NO
        // super-admin bypass: they resolve strictly through the role/permission
        // pivot tables. A missing row is therefore indistinguishable from a denied
        // permission, and approve/reject/pay withdrawal became 403 for EVERY user
        // including the platform operator -- a lockout, not a vulnerability.
        //
        // On the live database the rows may exist, created by hand (the same
        // pattern as the finance tables themselves, which no migration declares).
        // We cannot see the live database, so this gate is written to be correct
        // under BOTH possibilities:
        //
        //   * super admin            -> allowed, WITHOUT consulting any seeded row.
        //                               This is the same shape OrderPolicy::allow()
        //                               already uses (type === 'super admin').
        //   * anyone else            -> the normal Laratrust check. If the row was
        //                               seeded (live DB, or once Agent 2 seeds it)
        //                               a delegated user is correctly allowed; if it
        //                               was not, they are correctly denied.
        //
        // Seeding the six names in Utility::addNewData() remains the clean
        // completion of this fix and is reported as a cross-agent dependency
        // (Agent 2 owns Utility.php). This gate makes the platform operable
        // without it, and keeps the delegated path working once it lands.
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
