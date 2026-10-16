<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Collection;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaContactVerification;
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Support\MerchantContact;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;

/**
 * The merchant's Communication & Marketing Center.
 *
 * PHASE 1 SCOPE, STATED HONESTLY IN THE UI. This renders the connection state and the
 * onboarding actions, and nothing else. The inbox, customers, channels, campaigns, flows and
 * reports sections of the approved navigation are later phases; the page says so rather than
 * showing empty shells that imply a feature exists. No placeholder route, controller or view
 * is created for them.
 */
class CommunicationCenterController extends Controller
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    public function index(Request $request): View
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        return view('lynomia-chat::merchant.overview', [
            'link' => $link,
            // Drives the "not available yet" banner. Exposes a boolean only -- never the
            // base URL and never the token.
            'integrationConfigured' => $this->api->isConfigured(),
            'verificationCode' => session('lynomia_verification_code'),
            'identities' => $this->identitiesFor($link),
            // B4. Whether the platform can reach this merchant at all, and the challenge in
            // flight if they are part way through proving it. Shown here rather than on the
            // core profile page because the reason it matters is this integration: an
            // installation that breaks is reported to a number nobody has confirmed, or to
            // nobody.
            'contact' => MerchantContact::of($request->user()),
            'contactChallenge' => LynomiaContactVerification::where('user_id', $request->user()?->id)->first(),
        ]);
    }

    /**
     * Who from this store has a seat in the workspace, and with what role.
     *
     * SCOPED BY THE WORKSPACE THE LINK POINTS AT NOW, which is the same key the rest of the
     * module uses: a store re-pointed at a different Lynomia account must not show the
     * previous one's people. The middleware has already established the link belongs to the
     * caller's tenant, so no further ownership check is needed or would mean anything here.
     *
     * The person's name is read from Store Syria, not from Lynomia -- it is the same person
     * and this side is the one that knows them.
     */
    private function identitiesFor(?LynomiaStoreLink $link): Collection
    {
        if ($link?->lynomia_account_id === null) {
            return collect();
        }

        // 'administrator' sorts before 'agent', which puts the owner at the top without a
        // CASE expression or a second column to maintain.
        $identities = LynomiaStoreIdentity::forWorkspace((int) $link->lynomia_account_id)
            ->orderBy('role')
            ->orderBy('id')
            ->get();

        $names = User::whereIn('id', $identities->pluck('user_id'))->pluck('name', 'id');

        return $identities->each(
            fn (LynomiaStoreIdentity $identity) => $identity->person_name =
                $names[$identity->user_id] ?? __('Removed user')
        );
    }
}
