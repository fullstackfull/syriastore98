<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * The Campaigns page: a shell the browser fills from the BFF.
 *
 * NOTHING IS RENDERED SERVER-SIDE. A campaign's status changes while the page is open -- that
 * is what a running campaign does -- so a server-rendered list is stale before the merchant
 * has finished reading it.
 */
class CampaignController extends Controller
{
    public function index(Request $request): View
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return view('lynomia-chat::merchant.campaigns', [
            'workspaceName' => $link?->lynomia_account_name,
        ]);
    }
}
