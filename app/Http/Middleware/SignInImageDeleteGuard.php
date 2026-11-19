<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\StoredPathPolicy;
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
 * and only afterwards write the new rows for the caller's store. `Setting` is not in config/tenancy.php's scoped_models, so `->first()` is the
 * OLDEST row of that name in the whole platform: the first tenant that ever saved the form. EVERY LATER TENANT'S SAVE DELETES THAT TENANT'S
 * IMAGE (executed). And the row's value is whatever the row holds: a merchant can write the row the handler will choose, and name in it any
 * file the web user may delete.
 *
 * THE MODULES ARE VENDORED AND ARE NOT EDITED, and a route re-declared in routes/web.php would lose route matching (the addon registers its
 * routes from `$this->app->booted()`, after routes/web.php is read -- bootstrap/app.php says so at NEW-BULKDELETE-01). So the control is a
 * middleware on the `web` group, and it goes where the harm is done: the DELETE.
 *
 * WHAT IT DOES, in the one place that knows the handler, the row it is about to use and who is asking:
 *
 *   1. It recognises the two handlers by their ACTION (`SignInWithFacebookController@facebookSettings`, `SignInWithLinkedInController@LinkedinSettings`),
 *      not by the route's name: every census keyed on a spelling later missed a second route to the same handler, and a vendored update that
 *      renamed a route or added an alias would otherwise have left the handler unguarded.
 *   2. It runs the handler's own lookup first (`Setting::where(['name' => ...])->first()`, the same query, so the same row) and asks whether that
 *      row belongs to the signed-in user's CURRENT store, or one its owner also runs. When it does not -- another tenant's row, or a row with no
 *      store -- the filesystem it binds refuses EVERY upload delete, including the one that would name the requester's own file, which a row
 *      planted by another tenant would otherwise have the requester's own save carry out.
 *   3. For the lifetime of the request it swaps the `File` facade's root for a StoreBoundFilesystem (which also judges the PLACE: resolved by the
 *      operating system, own upload folders and the framework's working areas only) and puts the original back in a `finally`, whatever the
 *      handler does.
 *
 * WHAT IT DOES NOT DO. It does not make the handler look the right row up: a tenant's OLD image is not deleted by a later save of its own when
 * another tenant's row is older (images accumulate: a leak of disk). It does not reach the other vendored deleters (AuctionProduct,
 * DigitalProduct: ModuleResourceOwnershipGuard and the write-side StoredPathPolicy rules own those); a third module with the same shape must be
 * added to HANDLERS by hand (grep `File::delete(base_path(` in packages/workdo finds it). It does not make a row that already exists safe to
 * read -- it makes it unable to cause a delete. And only `delete()` and the two directory calls are bound.
 */
final class SignInImageDeleteGuard
{
    /** The ACTIONS (suffix of `Route::getActionName()`) of the vendored handlers that unlink a file a settings row names, and the row each reads. */
    public const HANDLERS = [
        'SignInWithFacebookController@facebookSettings' => 'facebook_sign_in_image',
        'SignInWithLinkedInController@LinkedinSettings' => 'linkedin_sign_in_image',
    ];

    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();
        $name = $route === null || ! $request->isMethod('POST') ? null : $this->rowNamed((string) $route->getActionName());

        if ($name === null) {
            return $next($request);
        }

        // an unknown store is store 0, which owns nothing
        $store = (int) getCurrentStore();

        // the row the handler is about to use: the same lookup, so the same row (it is read BEFORE the handler writes anything of its own)
        $row = Setting::where(['name' => $name])->first();
        $mine = $row === null || StoredPathPolicy::sameTenantStores($row->store_id, $store);

        $original = File::getFacadeRoot();

        File::swap(new StoreBoundFilesystem($store, $mine));

        try {
            return $next($request);
        } finally {
            File::swap($original);
        }
    }

    private function rowNamed(string $action): ?string
    {
        foreach (self::HANDLERS as $suffix => $name) {
            if (str_ends_with($action, $suffix)) {
                return $name;
            }
        }

        return null;
    }
}
