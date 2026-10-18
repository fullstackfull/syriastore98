<?php

namespace App\Services\SocialIdentity;

use RuntimeException;

/**
 * Every failure path of a provider verification, carrying a machine-readable reason.
 *
 * The reason exists so a handler can log precisely WHY a sign-in was refused without telling the
 * caller -- an attacker probing provider ids should not learn whether the failure was "no
 * credential configured for this store" or "signature invalid". Handlers return one generic
 * refusal; this reason goes to the log.
 */
class SocialIdentityVerificationException extends RuntimeException
{
    public const REASON_UNSUPPORTED_PROVIDER = 'unsupported_provider';
    public const REASON_NO_TOKEN = 'no_token';
    public const REASON_NOT_CONFIGURED = 'provider_not_configured_for_store';
    public const REASON_KEYS_UNAVAILABLE = 'provider_keys_unavailable';
    public const REASON_TOKEN_INVALID = 'token_invalid';
    public const REASON_WRONG_AUDIENCE = 'token_audience_mismatch';
    public const REASON_WRONG_ISSUER = 'token_issuer_mismatch';
    public const REASON_NO_SUBJECT = 'token_carries_no_subject';

    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    public static function notConfigured(string $provider): self
    {
        return new self(
            self::REASON_NOT_CONFIGURED,
            "No verification credential is configured for provider [{$provider}] in this store."
        );
    }
}
