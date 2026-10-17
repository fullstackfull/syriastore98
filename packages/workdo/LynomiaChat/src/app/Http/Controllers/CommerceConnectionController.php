<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\CommerceActivator;

/**
 * The merchant's two commerce actions: finish the setup, and check it is still working.
 *
 * NO IDENTIFIERS ARE ACCEPTED, like every other merchant-facing action in this module. The link
 * arrives as a request ATTRIBUTE from EnsureStoreOwnsLink, which authorised the store from the
 * session before looking a link up at all; the Lynomia account id and the store's external
 * identity are read off that row. There is no store_id, no account_id and no token in the
 * request body, so there is no IDOR surface here because there is no identifier to tamper with.
 *
 * AND NO CREDENTIAL IS EVER FLASHED. The Super Admin panel reveals a token once, because an
 * operator sometimes genuinely has to carry one. A merchant never does -- that is the whole
 * point of this controller -- so nothing here puts one in a session, a response or a log.
 */
class CommerceConnectionController extends Controller
{
    public function __construct(private readonly CommerceActivator $activator)
    {
    }

    /** Connect this store's orders and customers to its workspace. */
    public function connect(Request $request): RedirectResponse
    {
        return $this->attempt($request, verify: false);
    }

    /**
     * Re-confirm the connection rather than assume it.
     *
     * The same idempotent operation as connect(): an already-connected store is answered
     * `applied: false` with nothing written on either side, which is the safe no-op a merchant
     * pressing this on a working integration deserves. It also repairs a connection whose
     * credential Lynomia had rejected, which is why it is not a read-only endpoint.
     */
    public function verify(Request $request): RedirectResponse
    {
        return $this->attempt($request, verify: true);
    }

    /**
     * Stop Lynomia reading this store's orders and customers.
     *
     * Ends the STORE-DATA half only: the workspace, the conversations and the team stay, and
     * reconnecting is one button. The credential is revoked here and that is what actually stops
     * the reads -- see CommerceActivator::disconnect for why that half goes first and why
     * Lynomia not hearing about it is not reported as a failure.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        if (! $link) {
            return back()->with('error', __('This store is not connected to Lynomia Chat.'));
        }

        try {
            $this->activator->disconnect($link, $request->user());
        } catch (LynomiaApiException $e) {
            return back()->with('error', $this->merchantMessage($e));
        }

        return back()->with('success', __('Your store data is no longer shared with Lynomia Chat. You can connect it again at any time.'));
    }

    private function attempt(Request $request, bool $verify): RedirectResponse
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        if (! $link) {
            return back()->with('error', __('This store is not connected to Lynomia Chat.'));
        }

        try {
            $result = $verify
                ? $this->activator->verify($link, $request->user())
                : $this->activator->activate($link, $request->user());
        } catch (LynomiaApiException $e) {
            return back()->with('error', $this->merchantMessage($e));
        }

        if ($verify && ! $result['applied']) {
            return back()->with('success', __('Your store data is connected and working.'));
        }

        return back()->with('success', __('Your store orders and customers are now connected to Lynomia Chat.'));
    }

    /**
     * A merchant-safe sentence, chosen the same way ConnectionController chooses one.
     *
     * KIND_CONFLICT passes its own message through because that is the one kind whose message is
     * written for a merchant to read -- and it is where the commerce refusals Lynomia defines
     * land after LynomiaApiException::fromStatus maps them: a plan without the commerce module, a
     * store already connected to another workspace, a plan with no room for another store. Those
     * say what to do. Everything else gets a category, because there is nothing in it a merchant
     * could act on and the provider's own prose must not reach a browser.
     */
    private function merchantMessage(LynomiaApiException $e): string
    {
        return match ($e->kind) {
            LynomiaApiException::KIND_DISABLED,
            LynomiaApiException::KIND_NOT_CONFIGURED =>
                __('Lynomia Chat is not available yet. Please contact support.'),
            LynomiaApiException::KIND_CONFLICT,
            LynomiaApiException::KIND_IN_PROGRESS => $e->getMessage(),
            LynomiaApiException::KIND_RATE_LIMITED,
            LynomiaApiException::KIND_UNREACHABLE =>
                __('Lynomia Chat is temporarily unreachable. Please try again shortly.'),
            default => __('Store data could not be connected just now. You can try again.'),
        };
    }
}
