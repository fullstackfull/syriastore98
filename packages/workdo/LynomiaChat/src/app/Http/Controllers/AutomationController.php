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

    /**
     * Commerce automation and consent: one page with two tabs.
     *
     * TOGETHER BECAUSE THEY ARE TWO HALVES OF ONE QUESTION -- what the store sends on its own, and
     * whom it is allowed to send it to. A merchant setting up an order-shipped message and a
     * merchant recording that a customer asked to stop are the same person on the same afternoon,
     * and splitting them across two menu entries invites the first to be configured without the
     * second ever being looked at.
     *
     * WHETHER THIS PERSON MAY RECORD A CONSENT DECISION IS PASSED FOR LAYOUT ONLY. It hides a tab
     * they cannot use; the routes behind it carry their own permission, and the test suite proves
     * they refuse. A hidden tab is not an authorization control.
     */
    public function commerce(Request $request): View
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return view('lynomia-chat::merchant.commerce-automation', [
            'workspaceName' => $link?->lynomia_account_name,
            'canManageConsent' => $request->user()->isAbleTo('Manage Lynomia Consent'),
        ]);
    }

    /**
     * The visual builder for one flow.
     *
     * THE FLOW ID IS PASSED STRAIGHT THROUGH AND IS NOT TRUSTED. Nothing is read with it here; the page
     * ships it to the browser, which asks the BFF, which forwards it on a credential scoped to this store's
     * workspace server side. Another store's flow id is a perfectly real id and resolves to nothing, which
     * is a fact about the credential rather than a check written here that could be forgotten.
     */
    public function builder(Request $request, int $flow): View
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return view('lynomia-chat::merchant.builder', [
            'flowId' => $flow,
            'workspaceName' => $link?->lynomia_account_name,
        ]);
    }
}
