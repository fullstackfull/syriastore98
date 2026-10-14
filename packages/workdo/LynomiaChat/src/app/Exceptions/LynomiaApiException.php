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

    /**
     * Another attempt on this store is already in flight.
     *
     * Not a failure of the integration and not something the merchant did wrong -- it is the
     * answer a second concurrent request gets while the first one is still talking to
     * Lynomia. Separate from CONFLICT because it is temporary and the correct advice is
     * "wait", not "this cannot be done".
     */
    public const KIND_IN_PROGRESS = 'in_progress';

    /**
     * @param string|null $heldBy For a channel-claim conflict: what the OTHER holder is called on
     *                            this side. It is the integration's own reference -- a store id --
     *                            not anything of Lynomia's, so surfacing it leaks nothing about
     *                            another workspace, and without it a merchant is told "somebody
     *                            has this number" and cannot act.
     */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $heldBy = null
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

    /**
     * @param string|null $errorCode A short machine-readable reason this integration defines on
     *                               BOTH sides of the wire. It is matched, never rendered: the
     *                               sentence a merchant reads is written here, in their
     *                               language, and survives any rewording on the Lynomia side.
     */
    public static function fromStatus(int $status, ?string $errorCode = null, ?string $heldBy = null): self
    {
        // The two link-code refusals are the only remote failures a MERCHANT can act on
        // differently, and the difference matters: one says "that code is dead, make another",
        // the other says "that code is alive but belongs to a different integration -- you
        // pasted the wrong one, and nothing was consumed". A single "invalid request" for both
        // sends a merchant to support for a mistake they could fix in ten seconds.
        // A channel-claim conflict names the other holder, which is the one thing a merchant can
        // act on: "store B already has this number" sends them somewhere, "conflict" does not.
        if ($status === 409 && $errorCode === 'channel_claimed_elsewhere') {
            return new self(
                self::KIND_CONFLICT,
                __('That channel is already connected to another one of your stores.'),
                $status,
                is_string($heldBy) && $heldBy !== '' ? $heldBy : null
            );
        }

        if ($status === 422 && $errorCode !== null) {
            $message = match ($errorCode) {
                'wrong_platform_app' => __('That connection code was created for a different integration. Generate one for Store Syria in Lynomia and try again.'),
                'invalid_code' => __('That connection code is not valid, has already been used, or has expired. Please generate a new one in Lynomia.'),
                default => null,
            };

            if ($message !== null) {
                return new self(self::KIND_CONFLICT, $message, $status);
            }
        }

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
            // TRANSLATED, UNLIKE THEIR NEIGHBOURS, BECAUSE THESE TWO ARE THE ONLY ONES A MERCHANT READS.
            // Every other kind below is replaced by a merchant-facing sentence in merchantMessage();
            // KIND_CONFLICT alone passes getMessage() straight through, because most of its carriers
            // (AccountLinker, IdentityProvisioner) already build a translated sentence. These two do not,
            // so without __() an Arabic dashboard shows an English developer note.
            $status === 409 => new self(self::KIND_CONFLICT, __('Lynomia reported a conflict.'), $status),
            $status === 422 => new self(self::KIND_CONFLICT, __('Lynomia rejected the request as invalid.'), $status),
            $status === 429 => new self(self::KIND_RATE_LIMITED, 'Lynomia is rate limiting this app.', $status),
            default => new self(self::KIND_REMOTE_ERROR, "Lynomia returned HTTP {$status}.", $status),
        };
    }

    /** True when retrying the same call could plausibly succeed. */
    public function isTransient(): bool
    {
        return in_array($this->kind, [self::KIND_UNREACHABLE, self::KIND_RATE_LIMITED, self::KIND_IN_PROGRESS], true)
            || ($this->status !== null && $this->status >= 500);
    }
}
