<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Login throttling.
        //
        // This was perMinute(3)->by($request->ip()), which is a shared-IP
        // denial of service dressed up as a security control. Every merchant
        // behind one office NAT, one mobile carrier CGNAT or one corporate proxy
        // shares a single bucket, so three bad attempts by any one of them locks
        // out all of them for the rest of the minute -- and an attacker on a
        // different address is unaffected. It also punishes the wrong actor: a
        // password-guessing attack targets one ACCOUNT from many addresses,
        // which an ip()-only key does not even see.
        //
        // Keyed on ip() + the submitted email, so the bucket a brute-force
        // attack fills is its own, not a bystander's. The email is lowercased
        // and sha256'd: it ends up in the cache key, and a cache key is not a
        // place to store a raw user identifier.
        //
        // 10/min is still restrictive for a human (a real typo-then-retry needs
        // two or three) while leaving room for a shared address. It matters more
        // than it looks: the shipped Flutter app maps HTTP 429 to a generic
        // error with no retry affordance, so a throttled merchant sees an
        // unexplained failure rather than "try again in a minute".
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            $key = $request->ip() . '|' . hash('sha256', $email);

            return Limit::perMinute(10)->by($key);
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}