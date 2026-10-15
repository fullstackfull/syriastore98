<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * The Message Templates page: a shell the browser fills from the BFF.
 *
 * NOTHING ABOUT A TEMPLATE IS RENDERED SERVER-SIDE. A template's status is Meta's and moves
 * without warning; rendering it during the page request would both stale it immediately and
 * turn a slow Lynomia into a merchant who cannot open their dashboard.
 */
class TemplateController extends Controller
{
    public function index(Request $request): View
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return view('lynomia-chat::merchant.templates', [
            'workspaceName' => $link?->lynomia_account_name,
        ]);
    }
}
