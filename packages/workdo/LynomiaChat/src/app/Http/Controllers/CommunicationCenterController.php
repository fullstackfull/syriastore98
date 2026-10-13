<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
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
        ]);
    }
}
