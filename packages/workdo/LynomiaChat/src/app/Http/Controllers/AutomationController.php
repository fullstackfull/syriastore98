<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * The Automation Studio page: a shell the browser fills from the BFF.
 *
 * NOTHING ABOUT A FLOW IS RENDERED SERVER-SIDE, for the reason the inbox gives and one more of
 * its own. A flow list changes as flows are published, disabled and duplicated from this very
 * page, so a server-rendered table would be stale the moment the first action completed and
 * would have to be replaced wholesale anyway. And rendering it here would need a Lynomia call
 * during the page request, which turns a slow or unreachable Lynomia into a merchant who
 * cannot open their dashboard at all rather than one who sees an error inside a page that
 * loaded.
 *
 * NO CAPABILITY FLAGS ARE PASSED, UNLIKE THE INBOX. Whether this person may manage automations
 * is not a Store Syria question with a local answer: Lynomia authorizes every flows action as
 * a workspace administrator, which only the store owner is. The page therefore asks, and shows
 * what comes back -- which is also the only way it can be right when the two sides disagree.
 */
class AutomationController extends Controller
{
    public function index(Request $request): View
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return view('lynomia-chat::merchant.automation', [
            'workspaceName' => $link?->lynomia_account_name,
        ]);
    }
}
