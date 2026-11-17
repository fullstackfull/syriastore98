<?php

namespace App\Services\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingUnsubscribeToken;
use App\Support\Marketing\Channel;
use App\Support\Marketing\ContactAddress;
use InvalidArgumentException;

/**
 * ISSUES AND REDEEMS THE CAPABILITY TO UNSUBSCRIBE. D8 / GAP-200 / MKT-008.
 *
 * THE TOKEN IS 256 BITS OF RANDOMNESS, looked up by its SHA-256 -- not a signed URL. `APP_KEY` is in
 * git history and unrotated, so anything signed with it is forgeable by anyone who has read the
 * repository (CLAUDE.md, "Routing"). A random token cannot be forged without the database, and because
 * only the hash is stored a read of the table yields no working link.
 *
 * WHAT A TOKEN CAN DO IS EXACTLY ONE THING: withdraw the contact it was issued for, at the store it was
 * issued for, on the channel it was issued for. It cannot grant, cannot name another contact and cannot
 * be turned into the address (the row holds a hash and a masked hint). Redeeming it twice is a no-op in
 * effect -- withdrawing consent that is withdrawn changes nothing -- and is still recorded as evidence.
 *
 * ONE ROW PER ISSUED LINK. A deterministic token would avoid the growth but would need a server-side
 * secret that is not in git, and that is a key-management decision for the owner; a random one needs
 * none. The cost is a row per recipient per message, recorded here rather than discovered later.
 */
final class UnsubscribeTokens
{
    /** 32 random bytes in URL-safe base64 without padding is exactly 43 characters. */
    private const TOKEN_LENGTH = 43;

    public function __construct(private readonly ConsentLedger $ledger) {}

    /** @return string the PLAIN token. It is shown once, here, and never stored. */
    public function issue(
        int $storeId,
        Channel $channel,
        string $address,
        ?int $customerId = null,
        string $purpose = ConsentLedger::PURPOSE_MARKETING,
    ): string {
        $normalised = ContactAddress::normalise($channel, $address);

        if ($storeId <= 0 || $normalised === null) {
            throw new InvalidArgumentException('An unsubscribe link needs a store and a usable address.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        MarketingUnsubscribeToken::create([
            'store_id' => $storeId,
            'channel' => $channel->value,
            'purpose' => $purpose,
            'address_hash' => ContactAddress::hash($storeId, $channel, $normalised),
            'address_hint' => ContactAddress::mask($channel, $normalised),
            'customer_id' => $customerId,
            'token_hash' => self::hashOf($token),
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * The row a token opens, or null. A malformed value is refused BEFORE it is hashed or queried.
     *
     * `mixed`, not `?string`: a value read from a request can be an ARRAY (`?token[]=x`), and a type error
     * there would be a 500 on a public page. Anything that is not a well-formed string is simply no token.
     */
    public function find(mixed $token): ?MarketingUnsubscribeToken
    {
        if (! is_string($token) || strlen($token) !== self::TOKEN_LENGTH || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        return MarketingUnsubscribeToken::query()->where('token_hash', self::hashOf($token))->first();
    }

    /**
     * Withdraw the contact the token names. Returns the recorded event, or null for an unknown token.
     * `$evidence` is whatever the redeeming request can honestly say about itself (ip, user agent, one-click or page).
     */
    public function redeem(mixed $token, string $source = 'unsubscribe_link', array $evidence = []): ?MarketingConsentEvent
    {
        $row = $this->find($token);

        if ($row === null) {
            return null;
        }

        $channel = Channel::tryFrom((string) $row->channel);

        if ($channel === null) {
            return null;
        }

        $event = $this->ledger->withdrawByHash(
            (int) $row->store_id,
            $channel,
            (string) $row->address_hash,
            $row->address_hint,
            $source,
            $evidence,
            $row->customer_id === null ? null : (int) $row->customer_id,
            (string) $row->purpose,
        );

        if ($row->used_at === null) {
            $row->forceFill(['used_at' => now()])->save();
        }

        return $event;
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}
