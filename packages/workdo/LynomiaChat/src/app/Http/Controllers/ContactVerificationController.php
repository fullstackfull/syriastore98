<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Services\MerchantContactVerification;

/**
 * A MERCHANT CONFIRMING THEIR OWN NUMBER (B4).
 *
 * Two actions and nothing else: send me a code, here is the code. Both act on the
 * AUTHENTICATED merchant and on the store they are currently in -- neither takes a user id, a
 * store id or any other identifier a caller could substitute, which is the same shape every
 * other merchant surface in this module has and the reason none of them has an IDOR.
 *
 * NOT ON THE CORE PROFILE PAGE, where the number is typed. That page keeps working exactly as
 * it did; what it now does is clear the verified flag when the value changes. The proving
 * happens here because the reason it matters is this integration.
 */
class ContactVerificationController extends Controller
{
    public function __construct(private readonly MerchantContactVerification $verification)
    {
    }

    /** Send a code to the number the merchant says is theirs. */
    public function send(Request $request): RedirectResponse
    {
        $request->validate(['mobile' => ['required', 'string', 'max:20']]);

        try {
            $challenge = $this->verification->request(
                $request->user(),
                $this->currentStore($request),
                (string) $request->input('mobile')
            );
        } catch (LynomiaApiException $e) {
            return back()->with('error', $e->getMessage());
        }

        // A code nobody could send is not a code. Said plainly rather than leaving the
        // merchant watching a phone that will never ring -- most often this installation has
        // no platform workspace configured at all.
        if ($challenge->delivery_state !== 'accepted') {
            return back()->with('error', __('The code could not be sent. An operator needs to look at platform communications.'));
        }

        return back()->with('success', __('We sent a code to that number. Enter it below.'));
    }

    /** Take the code back. */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:12']]);

        try {
            $confirmed = $this->verification->confirm($request->user(), (string) $request->input('code'));
        } catch (LynomiaApiException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $confirmed
            ? back()->with('success', __('Your mobile number is confirmed.'))
            : back()->with('error', __('That code does not match. Check it and try again.'));
    }

    /**
     * The store the merchant is in.
     *
     * From the session, never from the request: `current_store` is the same source every
     * other tenant-scoped surface here resolves from, and the service checks the merchant
     * owns it before doing anything.
     */
    private function currentStore(Request $request): Store
    {
        return Store::findOrFail((int) $request->user()->current_store);
    }
}
