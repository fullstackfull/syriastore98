<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * The Audiences page: a shell the browser fills from the BFF.
 *
 * NOTHING ABOUT AN AUDIENCE IS RENDERED SERVER-SIDE, for the reason the Studio gives. An
 * audience list changes as audiences are created and deleted from this very page, so a
 * server-rendered table is stale the moment the first action completes. And rendering it here
 * would need a Lynomia call during the page request, which turns a slow Lynomia into a
 * merchant who cannot open their dashboard rather than one who sees an error inside a page
 * that loaded.
 *
 * NO CAPABILITY FLAGS. Whether this person may manage audiences is not a Store Syria question
 * with a local answer: Lynomia treats sharing an audience as an administrator act and only the
 * store owner holds that identity. The page asks and shows what comes back, which is the only
 * way it can be right when the two sides disagree.
 */
class AudienceController extends Controller
{
    public function index(Request $request): View
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return view('lynomia-chat::merchant.audiences', [
            'workspaceName' => $link?->lynomia_account_name,
        ]);
    }
}
