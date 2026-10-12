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
    }
}
