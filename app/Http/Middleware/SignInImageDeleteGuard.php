<?php

namespace App\Http\Middleware;

use App\Support\StoreBoundFilesystem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * THE TWO VENDORED SIGN-IN SETTINGS HANDLERS DELETE A FILE NAMED BY A ROW THEY CHOSE WITHOUT ASKING WHOSE IT IS.  P1-SEC-70.
 * ===========================================================================================================================
 *
 *   POST facebooklogin-setting   SignInWithFacebook  SignInWithFacebookController::facebookSettings()   route name facebooklogin.settings
 *   POST linkedin-setting        SignInWithLinkedIn  SignInWithLinkedInController::LinkedinSettings()   route name linkedinlogin.settings
 *
 * Both do, once the merchant's new image has been stored,
 *
 *     $Setting = Setting::where(['name' => '<provider>_sign_in_image'])->first();      // NO store predicate
 *     if (File::exists(base_path($Setting->value))) { File::delete(base_path($Setting->value)); }
 *
 * `Setting` is not in config/tenancy.php's scoped_models, so `->first()` is the OLDEST row of that name in the whole platform: the first
 * tenant that ever saved the form. EVERY LATER TENANT'S SAVE DELETES THAT TENANT'S IMAGE. (Executed: tenant B's save removed tenant A's
 * file; PlatformUploaderDoorsTest-style fixtures, the module loaded through the production loader.) And the row's value is whatever the row
 * holds: the Store Setting handlers take EVERY posted name/value into `settings` for the caller's store (StoreSettingsAccess), so a merchant
 * who writes their own `facebook_sign_in_image` row before anyone else has can name ANY file under `uploads/` -- another tenant's logo or a
 * product image -- and have this handler delete it. StoredPathPolicy::setting() (P1-SEC-28) keeps the value inside `uploads/`, but is not given the
 * store the row belongs to, so it cannot say whose folder it is.
 *
 * THE MODULES ARE VENDORED AND ARE NOT EDITED, and a route re-declared in routes/web.php would lose route matching (the addon registers its
 * routes from `$this->app->booted()`, after routes/web.php is read -- bootstrap/app.php says so at NEW-BULKDELETE-01). So the control is a
 * middleware on the `web` group, and it goes where the harm is done: the DELETE. For the lifetime of a POST to one of the two routes the
 * `File` facade's root is a StoreBoundFilesystem, which carries out a delete of a path under uploads/ only if StoredPathPolicy::isSafe() accepts it
 * for the signed-in user's CURRENT store (its own folder, or the folder of another store its owner runs), and passes every other call
 * through. It does not care how the row was chosen or written, so it holds against the first-row defect and against a row written to point
 * elsewhere. The original is put back in a `finally`, whatever the handler does.
 *
 * WHAT IT DOES NOT DO. It does not make the handler look the right row up: a tenant's OLD image is still never deleted by a later save of its
 * own (the handler finds the first tenant's row, whose file is the first tenant's), so images accumulate -- a leak of disk, not of anything
 * else. It does not reach the other vendored deleters (AuctionProduct, DigitalProduct: ModuleResourceOwnershipGuard and the write-side
 * StoredPathPolicy rules own those). And `$request->route()` names the two routes: a third module with the same shape must be added to ROUTES.
 */
final class SignInImageDeleteGuard
{
    /** The route NAMES of the vendored handlers that unlink a file a settings row names. */
    public const ROUTES = ['facebooklogin.settings', 'linkedinlogin.settings'];

    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();

        if (! $request->isMethod('POST') || $route === null || ! in_array($route->getName(), self::ROUTES, true)) {
            return $next($request);
        }

        $original = File::getFacadeRoot();

        // an unknown store is store 0, which owns nothing: StoredPathPolicy::isSafe(path, null) would mean "no store to check" and allow it
        File::swap(new StoreBoundFilesystem((int) getCurrentStore()));

        try {
            return $next($request);
        } finally {
            File::swap($original);
        }
    }
}
