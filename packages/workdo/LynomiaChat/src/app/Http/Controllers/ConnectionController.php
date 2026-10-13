<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Http\Requests\LinkExistingAccountRequest;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\SsoLinkIssuer;

/**
 * Merchant-facing connection actions: provision, claim an existing workspace, open it.
 *
 * Every action takes its store from the request ATTRIBUTES set by EnsureStoreOwnsLink, never
 * from the request body. There is no store_id, no link id and no account id accepted from the
 * caller anywhere except the one field a claim genuinely needs, which is validated and then
 * checked against a unique index.
 */
class ConnectionController extends Controller
{
    public function __construct(
        private readonly AccountProvisioner $provisioner,
        private readonly AccountLinker $linker,
        private readonly SsoLinkIssuer $sso
    ) {
    }

    /** Create a brand-new Lynomia workspace for the current store. */
    public function provision(Request $request): RedirectResponse
    {
        $storeId = (int) $request->attributes->get(EnsureStoreOwnsLink::STORE_ATTRIBUTE);
        $store = Store::find($storeId);

        if (! $store) {
            return back()->with('error', __('No store is selected.'));
        }

        try {
            $this->provisioner->provision($store, $request->user());
        } catch (LynomiaApiException $e) {
            return back()->with('error', $this->merchantMessage($e));
        }

        return back()->with('success', __('Your Lynomia Chat workspace is ready.'));
    }

    /**
     * Open a claim on an existing workspace.
     *
     * The verification code is flashed ONCE so the merchant can present it to support. It is
     * deliberately not persisted anywhere readable: only its hash is stored.
     */
    public function requestLink(LinkExistingAccountRequest $request): RedirectResponse
    {
        $storeId = (int) $request->attributes->get(EnsureStoreOwnsLink::STORE_ATTRIBUTE);
        $store = Store::find($storeId);

        if (! $store) {
            return back()->with('error', __('No store is selected.'));
        }

        try {
            $result = $this->linker->request(
                $store,
                $request->user(),
                (int) $request->validated('lynomia_account_id')
            );
        } catch (LynomiaApiException $e) {
            return back()->with('error', $this->merchantMessage($e));
        }

        return back()
            ->with('success', __('Your connection request has been recorded and is awaiting approval.'))
            ->with('lynomia_verification_code', $result['verification_code']);
    }

    /**
     * Redirect into the merchant's Lynomia workspace.
     *
     * The SSO URL is used as a redirect target and nothing else: it is not rendered into the
     * page, not flashed and not logged. It is single-use and expires in five minutes.
     */
    public function openWorkspace(Request $request): RedirectResponse
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        if (! $link) {
            return back()->with('error', __('This store is not connected to Lynomia Chat.'));
        }

        try {
            $url = $this->sso->issueFor($link, $request->user());
        } catch (LynomiaApiException $e) {
            return back()->with('error', $this->merchantMessage($e));
        }

        return redirect()->away($url);
    }

    /**
     * A merchant-safe sentence.
     *
     * The retired integration echoed the provider's raw response body to the browser. A
     * merchant can act on "not configured yet" and on "already connected"; they can do
     * nothing with Lynomia's internal error text, and it should not be shown to them.
     */
    private function merchantMessage(LynomiaApiException $e): string
    {
        return match ($e->kind) {
            LynomiaApiException::KIND_DISABLED,
            LynomiaApiException::KIND_NOT_CONFIGURED =>
                __('Lynomia Chat is not available yet. Please contact support.'),
            LynomiaApiException::KIND_CONFLICT => $e->getMessage(),
            LynomiaApiException::KIND_RATE_LIMITED,
            LynomiaApiException::KIND_UNREACHABLE =>
                __('Lynomia Chat is temporarily unreachable. Please try again shortly.'),
            default => __('Lynomia Chat could not complete that request. Please contact support.'),
        };
    }
}
