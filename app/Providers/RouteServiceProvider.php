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
     * The form a rate-limit key is built from: the form the DATABASE compares in.
     *
     * The lookups behind these limiters (`stores.slug`, `orders.product_order_id`, `users.email`) run
     * under MySQL's utf8mb4_unicode_ci, which is case- AND accent-insensitive -- verified on a real
     * server by tests/Support/Security/bin/verify-refund-freshness-lock-and-limiter-keys-on-mysql.php,
     * where `MyShop`, `mYSHOP` and `myshóp` all match the one `myshop` row. A key built from the raw
     * string hands an attacker a fresh bucket per spelling of the same row, so every key is folded to
     * lowercase ASCII first. (SQLite compares bytes, so no PHPUnit test can see the difference; the
     * unit tests pin the folding itself.)
     */
    public static function foldForRateLimit(string $value): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii(trim($value)));
    }

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
            // is_scalar(): `email[]=x` is request input, `(string) array` is an ErrorException, and
            // this closure runs BEFORE the handler -- so an array-shaped email was a 500 on every
            // route carrying `throttle:auth` (found when P1-ORD-08 put it on order-track). It now
            // falls into the same bucket as an absent email, which is still throttled.
            $submitted = $request->input('email');
            $email = self::foldForRateLimit(is_scalar($submitted) ? (string) $submitted : '');

            $key = $request->ip() . '|' . hash('sha256', $email);

            return Limit::perMinute(10)->by($key);
        });

        // Guest order lookup (P1-ORD-08). `auth` above bounds ONE ADDRESS swept across order numbers;
        // this bounds the other axis -- one ORDER NUMBER (seen on a receipt or a screenshot) tried
        // against many guessed addresses, which gets a fresh `auth` bucket per guess. Keyed on IP +
        // store slug + number, hashed like the email above so a cache key holds no raw identifier.
        // `product_order_id` has no index, so every miss is a table scan: this also bounds that.
        RateLimiter::for('order-track', function (Request $request) {
            $number = $request->input('order_number');
            $number = self::foldForRateLimit(is_scalar($number) ? (string) $number : '');
            $slug = self::foldForRateLimit((string) $request->route('storeSlug'));

            return Limit::perMinute(10)->by($request->ip() . '|' . $slug . '|' . hash('sha256', $number));
        });

        // Storefront message relay (P1-SEC-22 a). `POST {storeSlug}/get-massage` is anonymous and, for `data[type]=telegram`, makes the SERVER post
        // one message to the merchant's Telegram chat per call; with no limit anyone could flood a merchant's bot (and burn its API quota)
        // from one address. Keyed on IP + store slug so one noisy address cannot starve the same store's other shoppers, and one store's traffic
        // cannot starve another's. 12/min: a shopper sends one message per checkout, and a shared (NAT) address needs room.
        RateLimiter::for('storefront-relay', function (Request $request) {
            $slug = self::foldForRateLimit((string) $request->route('storeSlug'));

            return Limit::perMinute(12)->by($request->ip() . '|' . $slug);
        });

        /**
         * P1-SEC-22(e). `POST generate/response` spends an OpenAI key and carried no throttle at all, so
         * bounding the spend PER CALL would have left the spend per minute unbounded -- the same bill in
         * smaller pieces.
         *
         * Keyed on the authenticated user, not the IP: this route sits behind `auth`, several staff of one
         * merchant legitimately share an office IP, and an attacker here is a signed-in principal rather
         * than an anonymous one. Falls back to the IP when no user resolves, so the limiter can never key
         * every caller onto one bucket.
         */
        RateLimiter::for('ai-generate', function (Request $request) {
            $actor = $request->user();

            return Limit::perMinute(20)->by($actor ? 'u' . $actor->id : 'ip' . $request->ip());
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