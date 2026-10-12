<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Route-level guard for the finance surface.
 *
 * WHY THIS EXISTS RATHER THAN `permission:` OR `can:`.
 *
 * 1. Laratrust's `permission:<name>` middleware resolves strictly through the
 *    role/permission pivot tables and has NO super-admin bypass. The six finance
 *    permission names are created by nothing: Utility::addNewData() is the only
 *    permission-creation mechanism in the application and contains zero
 *    occurrences of the string "Finance". On a database built from source the
 *    rows do not exist, so approve/reject/pay withdrawal were 403 for EVERY
 *    user, the platform operator included. That is a lockout, not a defence.
 *
 * 2. Laravel's own `can:finance,View Finance Dashboard` does NOT work here, and
 *    fails in a way that `php -l` cannot see. Authorize::getModel() resolves a
 *    non-class parameter as `$request->route($model, null)`, falling back to a
 *    QUOTED-string regex. An unquoted literal is neither, so it arrives at the
 *    gate as NULL -- which fataled with
 *    "Argument #2 ($permission) must be of type string, null given"
 *    on the first real request. Verified by execution, not by inspection.
 *    `can:finance,'View Finance Dashboard'` does work, but the behaviour hinges
 *    entirely on quote characters inside a middleware string: drop them and the
 *    route 500s instead of denying. That is too fragile for an authorization
 *    control, so the permission name is passed as an ordinary, explicitly typed
 *    middleware parameter instead.
 *
 * The decision itself still lives in one place -- the `finance` gate in
 * AuthServiceProvider -- so the web routes, the controller methods and the
 * /api/admin/wallet/* endpoints cannot drift apart.
 */
class FinancePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();

        if ($user === null) {
            // These routes sit behind `auth`, so this is defence in depth. It is
            // an explicit 401 rather than a redirect because reaching here at all
            // means the auth middleware was removed or reordered.
            return $this->deny($request, __('Unauthenticated.'), 401);
        }

        if (!Gate::forUser($user)->allows('finance', $permission)) {
            return $this->deny(
                $request,
                __('Permission denied.') . ' (' . $permission . ')',
                403
            );
        }

        return $next($request);
    }

    /**
     * A CLEAR refusal.
     *
     * The permission name is named in the message on purpose. The failure this
     * replaces was a bare 403 from Laratrust with no indication of which
     * permission was missing, which is precisely why a missing SEED looked
     * identical to a denied PERMISSION and went unnoticed.
     */
    protected function deny(Request $request, string $message, int $status)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status'  => 0,
                'message' => 'fail',
                'data'    => ['message' => $message],
            ], $status);
        }

        abort($status, $message);
    }
}
