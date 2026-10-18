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
     * Lynomia has this installation but has never seen this customer.
     *
     * Separate from KIND_NOT_FOUND, and TRANSIENT, because it is a race rather than a
     * mistake: a shopper's checkout opt-in is dispatched the moment they tick the box, and
     * the customer link Lynomia resolves the subject through is created by the order event
     * that follows. Classed with the other 404s, a first-time shopper's opt-in was retired
     * as `rejected` on its first attempt and lost -- and the reconciler skipped the scope
     * believing the outbox still owned it, so the operator's drift count stayed 0.
     */
    public const KIND_SUBJECT_NOT_LINKED = 'subject_not_linked';

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
    /**
     * @param string|null $errorCode The machine-readable reason the far side gave, carried through
     *                               UNRENDERED. The message is already translated for a merchant to
     *                               read, which makes it the wrong thing to store or to branch on:
     *                               a caller that needs to RECORD why something failed needs a
     *                               token that means the same in every language, and a row read by
     *                               a whole tenant cannot hold one reader's sentence.
     */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $heldBy = null,
        public readonly ?string $errorCode = null
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
                is_string($heldBy) && $heldBy !== '' ? $heldBy : null,
                $errorCode
            );
        }

        // THE COMMERCE INSTALLATION REFUSALS, FOR THE SAME REASON AS THE TWO ABOVE: each one has
        // a different thing the merchant (or their operator) should do next, and "Lynomia
        // rejected the request as invalid" sends every one of them to support. The codes are
        // matched, never rendered -- the sentence is written here, in the reader's language, and
        // survives any rewording on the Lynomia side.
        if ($status === 409 && $errorCode === 'installation_claimed_elsewhere') {
            return new self(
                self::KIND_CONFLICT,
                __('Your store data is already connected to a different Lynomia Chat workspace. Please contact support.'),
                $status,
                null,
                $errorCode
            );
        }

        if ($status === 422 && $errorCode !== null) {
            $message = match ($errorCode) {
                'wrong_platform_app' => __('That connection code was created for a different integration. Generate one for Store Syria in Lynomia and try again.'),
                'invalid_code' => __('That connection code is not valid, has already been used, or has expired. Please generate a new one in Lynomia.'),
                // The merchant's Lynomia plan does not sell the commerce module. A real
                // entitlement question, not a fault, and the only one on this list they resolve
                // by upgrading rather than by retrying.
                'commerce_disabled' => __('Your Lynomia Chat plan does not include store data yet. Please contact support to enable it.'),
                // Store Syria's provider is switched off installation-wide in Lynomia, or the
                // credential no longer resolves to this store. Both are configuration on one
                // side or the other and neither is anything a merchant can act on.
                'provider_disabled', 'auth_invalid', 'platform_owned_account' =>
                    __('Store data could not be connected for this store. Please contact support.'),
                'store_limit_reached' => __('Your Lynomia Chat plan has no room for another connected store. Please contact support.'),
                default => null,
            };

            if ($message !== null) {
                return new self(self::KIND_CONFLICT, $message, $status, null, $errorCode);
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
            $status === 404 && $errorCode === 'subject_not_linked' => new self(
                self::KIND_SUBJECT_NOT_LINKED,
                'Lynomia has not seen this customer yet.',
                $status
            ),
            $status === 404 => new self(self::KIND_NOT_FOUND, 'The Lynomia resource does not exist.', $status),
            // TRANSLATED, UNLIKE THEIR NEIGHBOURS, BECAUSE THESE TWO ARE THE ONLY ONES A MERCHANT READS.
            // Every other kind below is replaced by a merchant-facing sentence in merchantMessage();
            // KIND_CONFLICT alone passes getMessage() straight through, because most of its carriers
            // (AccountLinker, IdentityProvisioner) already build a translated sentence. These two do not,
            // so without __() an Arabic dashboard shows an English developer note.
            $status === 409 => new self(self::KIND_CONFLICT, __('Lynomia reported a conflict.'), $status, null, $errorCode),
            $status === 422 => new self(self::KIND_CONFLICT, __('Lynomia rejected the request as invalid.'), $status, null, $errorCode),
            $status === 429 => new self(self::KIND_RATE_LIMITED, 'Lynomia is rate limiting this app.', $status),
            default => new self(self::KIND_REMOTE_ERROR, "Lynomia returned HTTP {$status}.", $status),
        };
    }

    /** True when retrying the same call could plausibly succeed. */
    public function isTransient(): bool
    {
        return in_array(
            $this->kind,
            [self::KIND_UNREACHABLE, self::KIND_RATE_LIMITED, self::KIND_IN_PROGRESS, self::KIND_SUBJECT_NOT_LINKED],
            true
        ) || ($this->status !== null && $this->status >= 500);
    }
}
