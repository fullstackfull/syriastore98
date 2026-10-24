<?php

namespace App\Services\SocialIdentity;

/**
 * The result of a SUCCESSFUL provider verification: an identity the server established, not one the
 * caller asserted.
 *
 * Constructed only by a verifier in this namespace. Nothing here comes from the request body --
 * that is the whole point of the type. A handler holding one of these may trust `providerId` as the
 * account key; a handler holding a `$request->google_id` may not.
 */
final class VerifiedSocialIdentity
{
    public function __construct(
        /** 'google' | 'apple' | 'facebook' */
        public readonly string $provider,
        /** The provider's stable subject identifier. Google/Apple `sub`, Facebook app-scoped id. */
        public readonly string $providerId,
        /** The address the provider asserted, or null when it asserted none. */
        public readonly ?string $email,
        /**
         * Whether the PROVIDER said it had verified that address.
         *
         * Never defaulted to true. An unverified provider email must not be treated as proof of
         * control of a mailbox, because that is how a social sign-in becomes a way to claim
         * somebody else's email-registered account.
         */
        public readonly bool $emailVerified,
    ) {
    }
}
