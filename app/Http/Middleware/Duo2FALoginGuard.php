<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-SEC-35 -- `/duologin` LOGGED AN ANONYMOUS CALLER IN AS ANY USER (EXECUTED, tests/Feature/Security/Duo2FALoginForgeryTest).
 * ============================================================================================================================
 *
 * The vendored Duo2FA module's `Route::any('duologin')` is CSRF-exempt and carries no authentication. Its handler checks a "Duo response" made
 * of two HMAC halves and then calls `Auth::loginUsingId()` for the e-mail INSIDE the response:
 *
 *   - the AUTH half is keyed with `stores.duo_secret_key` of the store of the user named in the REQUEST's `user_email` -- NULL for every store
 *     that never configured Duo, which hash_hmac() takes as the empty key;
 *   - the APP half is keyed with a constant committed to the repository (LaravelDuo::get_akey ignores its argument);
 *   - the halves are compared only with each other. Nothing ties the user they name to the request, to a session, or to anything the caller
 *     does not choose.
 *
 * So anyone could mint a response naming ANY account (the platform operator included), send `user_email` of an account whose store has no Duo
 * key (their own), and be signed in as the victim -- no password, no second factor. Where Duo IS configured it was a bypass of the second factor
 * for the same reason. Nothing in the legitimate flow needs any of it: the Duo form (showDuoForm) is shown only to a user who is ALREADY signed
 * in with a password, only when the store has Duo switched on with an integration key, a secret key and an application key, and it posts that
 * user's own e-mail.
 *
 * packages/workdo/ is vendored, so the control is host-side and keyed on the route. Four conditions, each a refusal that is a redirect with an
 * error (a JSON caller gets a 403) and never a 401:
 *
 *   1. a signed-in `web` user -- the legitimate flow always has one;
 *   2. the request's `user_email` is that user's own e-mail;
 *   3. BOTH halves of `sig_response` name that same user. The halves are read WITHOUT trusting them (the value is split and base64-decoded
 *      here; the module still verifies the signatures afterwards), which also turns every malformed value into a refusal instead of the
 *      handler's 500;
 *   4. the user's store has a Duo integration key AND a secret key configured. An empty key makes the AUTH half a signature anyone can make,
 *      and a store in that state is never offered the Duo form, so no legitimate response can exist for it.
 *
 * What remains: a signed-in user of a store that HAS configured Duo can still sign a response naming themselves with their store's own secret
 * key. That is the module's trust model (whoever holds the key signs), not a cross-account step, and it is the store owner's key.
 *
 * NOT CHANGED, deliberately: the route stays CSRF-exempt (bootstrap/app.php). With conditions 1-4 a forged cross-site POST cannot name a victim
 * it could not already be; removing the exemption needs the real Duo browser flow observed first, and is recorded on the task.
 */
class Duo2FALoginGuard
{
    /** The handler that signs the caller in. Matched by ACTION, not name: it is the action that does the login (the premise test pins that the route still has it). */
    private const ACTION_SUFFIX = 'Duo2FAController@duologin';

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route || ! $this->isTarget($route)) {
            return $next($request);
        }

        $user = auth('web')->user();

        if (! $user) {
            return $this->deny($request, route('login'));
        }

        // EXACT comparison, no trim and no case folding: the module resolves the login target from the raw string in the response with its own
        // `User::where('email', $U)`, and the guard must accept only a spelling that lookup cannot read as a different row. Both values are read
        // through the module's own accessor (Request::__get reads all(), i.e. input AND files), so the guard validates what the handler reads.
        $email = (string) $user->email;

        if ($email === '' || $request->user_email !== $email) {
            return $this->deny($request, route('duo2fa.form'));
        }

        $named = $this->namedUsers($request->sig_response);

        if ($named === null || $named[0] !== $email || $named[1] !== $email) {
            return $this->deny($request, route('duo2fa.form'));
        }

        $store = Store::find($user->current_store);

        if (! $store || trim((string) $store->duo_integration_key) === '' || trim((string) $store->duo_secret_key) === '') {
            return $this->deny($request, route('duo2fa.form'));
        }

        return $next($request);
    }

    private function isTarget($route): bool
    {
        return str_ends_with((string) $route->getActionName(), self::ACTION_SUFFIX);
    }

    /**
     * The users the two halves of a Duo response claim to be for, read without verifying anything.
     *
     * @return array{0:string,1:string}|null null for any value that is not `AUTH|<b64>|<sig>:APP|<b64>|<sig>`
     */
    private function namedUsers(mixed $response): ?array
    {
        if (! is_string($response) || $response === '') {
            return null;
        }

        $halves = explode(':', $response);

        if (count($halves) !== 2) {
            return null;
        }

        $users = [];

        foreach ($halves as $half) {
            $parts = explode('|', $half);

            if (count($parts) !== 3) {
                return null;
            }

            $payload = base64_decode($parts[1], true);

            if ($payload === false) {
                return null;
            }

            $fields = explode('|', $payload);

            if (count($fields) !== 3 || $fields[0] === '') {
                return null;
            }

            $users[] = $fields[0];
        }

        return $users;
    }

    private function deny(Request $request, string $to): Response
    {
        $message = __('Two-factor sign-in could not be completed.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect($to)->with('error', $message);
    }
}
