<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * The inbox page itself: a shell the browser fills from the BFF.
 *
 * NOTHING ABOUT A CONVERSATION IS RENDERED SERVER-SIDE. The page ships the layout, the
 * translations and the capability flags, and the browser asks /lynomia/inbox/api/* for the
 * rest. That is not a fashion: conversations change while the page is open, and a
 * server-rendered list would be stale the moment it arrived and would have to be replaced
 * wholesale on every update anyway.
 *
 * THE CAPABILITY FLAGS ARE FOR LAYOUT, NOT FOR AUTHORIZATION. `canReply` hides a composer the
 * staff member may not use; it does not stop them using it. Every one of those actions is
 * gated again on its route, because a hidden button is not an authorization control -- the
 * staff member types the path, and the test suite proves the path refuses them.
 */
class InboxController extends Controller
{
    public function index(Request $request): View
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);
        $user = $request->user();

        return view('lynomia-chat::merchant.inbox', [
            'link' => $link,
            'workspaceName' => $link?->lynomia_account_name,
            'canReply' => $user->isAbleTo('Reply Lynomia Chat'),
            'canAssign' => $user->isAbleTo('Assign Lynomia Chat'),
        ]);
    }
}
