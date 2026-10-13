<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\User;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * Issues a one-way Store Syria -> Lynomia sign-in.
 *
 * ONE-WAY, AND NOTHING HERE MAKES IT TWO-WAY. There is no reverse endpoint, no "Sign in with
 * Store Syria" button on Lynomia's login page, and no token Lynomia can present back. The
 * only direction is: an authenticated Store Syria merchant, on a store they own, with an
 * active link, is redirected into their own Lynomia workspace.
 *
 * WHY NO OIDC. Lynomia already mints exactly the right artefact:
 * GET /platform/api/v1/users/{id}/login returns a URL carrying a SecureRandom.hex(32) token
 * held in Redis for five minutes and invalidated on first redemption, bound to one user
 * (app/models/concerns/sso_authenticatable.rb; devise_overrides/sessions_controller.rb).
 * That is single-use, short-lived, server-to-server, and already authorized by
 * platform_app_permissibles. Standing up an OIDC provider would be net-new work -- the
 * Lynomia Gemfile carries omniauth as a RELYING party and no Doorkeeper -- for no gain in a
 * one-way, single-IdP flow.
 *
 * THE URL IS A CREDENTIAL FOR FIVE MINUTES. It is handed straight to the browser as a
 * redirect and is never written to the database, never put in a flash message, never
 * rendered into a page and never logged. The audit record notes that a link was issued and
 * for which store -- not the URL.
 */
class SsoLinkIssuer
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    /**
     * @param LynomiaStoreLink $link A link the CALLER has already proven belongs to a store
     *                               the actor owns. This method re-checks link state, not
     *                               ownership -- ownership is the route's job and is done by
     *                               EnsureStoreOwnsLink before anything here runs.
     */
    public function issueFor(LynomiaStoreLink $link, User $actor): string
    {
        if (! $link->allowsSso()) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'This store has no active Lynomia workspace to open.'
            );
        }

        $url = $this->api->issueSsoUrl((int) $link->lynomia_user_id);

        AuditLog::record('lynomia_sso_issued', $link, [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'actor_id' => (int) $actor->id,
        ]);

        return $url;
    }
}
