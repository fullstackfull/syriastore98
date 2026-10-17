<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\User;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * Issues a one-way Store Syria -> Lynomia sign-in, as the person who clicked.
 *
 * ONE-WAY, AND NOTHING HERE MAKES IT TWO-WAY. There is no reverse endpoint, no "Sign in with
 * Store Syria" button on Lynomia's login page, and no token Lynomia can present back. The
 * only direction is: an authenticated Store Syria user, on a store their tenant owns, with an
 * active link, is redirected into that store's Lynomia workspace -- as themselves.
 *
 * AS THEMSELVES IS THE PART THAT CHANGED. This used to open one shared identity recorded on
 * the link row, so every member of a merchant's team arrived as the same administrator and
 * nothing anyone did in Lynomia was attributable to the person who did it. The identity is now
 * resolved per caller (IdentityProvisioner), carries their own name and their own role, and
 * the link minted for it is confined to one workspace.
 *
 * WHY NO OIDC. Lynomia already mints exactly the right artefact:
 * GET /platform/api/v1/users/{id}/login?account_id= returns a URL carrying a
 * SecureRandom.hex(32) token held in Redis for five minutes, invalidated on first redemption,
 * bound to one user AND to one account (app/models/concerns/sso_authenticatable.rb;
 * devise_overrides/sessions_controller.rb; app/controllers/concerns/ensure_current_account_helper.rb).
 * That is single-use, short-lived, server-to-server, scoped, and already authorized by
 * platform_app_permissibles. Standing up an OIDC provider would be net-new work -- the Lynomia
 * Gemfile carries omniauth as a RELYING party and no Doorkeeper -- for no gain in a one-way,
 * single-IdP flow.
 *
 * THE URL IS A CREDENTIAL FOR FIVE MINUTES. It is handed straight to the browser as a
 * redirect and is never written to the database, never put in a flash message, never rendered
 * into a page and never logged. The audit record notes who was signed in, into what, and as
 * which remote identity -- not the URL.
 */
class SsoLinkIssuer
{
    public function __construct(
        private readonly PlatformApiClient $api,
        private readonly IdentityProvisioner $identities
    ) {
    }

    /**
     * @param LynomiaStoreLink $link A link the CALLER has already proven belongs to a store
     *                               the actor's tenant owns. This method re-checks link state,
     *                               not ownership -- ownership is the route's job and is done
     *                               by EnsureStoreOwnsLink before anything here runs.
     * @param User             $actor The person signing in. Their own identity is used, and
     *                               their own role: there is no parameter here that could ask
     *                               for somebody else's.
     */
    public function issueFor(LynomiaStoreLink $link, User $actor): string
    {
        if (! $link->allowsSso()) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'This store has no active Lynomia workspace to open.'
            );
        }

        $identity = $this->identities->identityFor($link, $actor);

        // SCOPED TO THIS WORKSPACE AND NO OTHER. Lynomia verifies the pairing before honouring
        // it, so this is a request for a boundary rather than an assertion of one.
        $url = $this->api->issueSsoUrl(
            (int) $identity->lynomia_user_id,
            (int) $link->lynomia_account_id
        );

        $identity->forceFill(['last_sso_at' => now()])->save();

        AuditLog::record('lynomia_sso_issued', $link, [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'actor_id' => (int) $actor->id,
            // The remote identity and role, so a Lynomia-side action can be traced back to a
            // Store Syria person without asking anyone to correlate timestamps.
            'lynomia_user_id' => (int) $identity->lynomia_user_id,
            'role' => $identity->role,
        ]);

        return $url;
    }
}
