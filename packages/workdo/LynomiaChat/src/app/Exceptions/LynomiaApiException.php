<?php

namespace Workdo\LynomiaChat\app\Exceptions;

use RuntimeException;

/**
 * A typed failure from the Lynomia Platform API.
 *
 * The retired integration threw a bare \Exception carrying the provider's raw response body,
 * and every controller rendered that body straight back to the merchant's browser as a 422.
 * That leaked Lynomia's internal error text to a merchant and gave the caller nothing to
 * branch on. This carries a machine-readable `kind` for the caller and a safe, short summary
 * for the operator; the provider body is never attached.
 */
class LynomiaApiException extends RuntimeException
{
    public const KIND_NOT_CONFIGURED = 'not_configured';
    public const KIND_DISABLED = 'disabled';
    public const KIND_UNAUTHORIZED = 'unauthorized';
    public const KIND_NOT_PERMISSIBLE = 'not_permissible';
    public const KIND_NOT_FOUND = 'not_found';
    public const KIND_CONFLICT = 'conflict';
    public const KIND_RATE_LIMITED = 'rate_limited';
    public const KIND_UNREACHABLE = 'unreachable';
    public const KIND_REMOTE_ERROR = 'remote_error';
    public const KIND_INVALID_RESPONSE = 'invalid_response';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $status = null
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $what): self
    {
        return new self(
            self::KIND_NOT_CONFIGURED,
            "Lynomia integration is not configured: {$what} is missing."
        );
    }

    public static function disabled(): self
    {
        return new self(
            self::KIND_DISABLED,
            'Lynomia integration is disabled (LYNOMIA_INTEGRATION_ENABLED is false).'
        );
    }

    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status === 401 || $status === 403 => new self(
                // The Platform API answers 401 for a bad token and, from
                // validate_platform_app_permissible, "Non permissible resource" for an account
                // this app was never granted. Both arrive as 401 there, so they cannot be told
                // apart by status alone -- say so rather than guess.
                self::KIND_UNAUTHORIZED,
                'Lynomia rejected the platform credential, or this app is not permitted on that account.',
                $status
            ),
            $status === 404 => new self(self::KIND_NOT_FOUND, 'The Lynomia resource does not exist.', $status),
            $status === 409 => new self(self::KIND_CONFLICT, 'Lynomia reported a conflict.', $status),
            $status === 422 => new self(self::KIND_CONFLICT, 'Lynomia rejected the request as invalid.', $status),
            $status === 429 => new self(self::KIND_RATE_LIMITED, 'Lynomia is rate limiting this app.', $status),
            default => new self(self::KIND_REMOTE_ERROR, "Lynomia returned HTTP {$status}.", $status),
        };
    }

    /** True when retrying the same call could plausibly succeed. */
    public function isTransient(): bool
    {
        return in_array($this->kind, [self::KIND_UNREACHABLE, self::KIND_RATE_LIMITED], true)
            || ($this->status !== null && $this->status >= 500);
    }
}
