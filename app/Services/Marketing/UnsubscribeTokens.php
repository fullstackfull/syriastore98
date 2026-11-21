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
 * be turned into the address (the row holds a hash and a masked hint).
 *
 * A TOKEN IS REUSABLE, BY DESIGN, and earlier versions of this comment and the design document called it
 * "single use". A recipient who clicks an old link, or a mail scanner that has already followed it, must not
 * find it dead: an unsubscribe link that stops working is a failure to honour an unsubscribe. So every
 * redemption withdraws again and is appended as evidence, and `used_at` records only the FIRST. Three things
 * follow, and they belong to the route that will call this, not to this class:
 *
 *   * a GET must never redeem. Link prefetchers and scanners follow GETs, and a replay of an old link
 *     after the person has opted in AGAIN withdraws the new opt-in. Redeem on POST (RFC 8058 one-click) or
 *     behind a confirmation step.
 *   * the route must be throttled. Every recipient holds a working link and each redemption appends a row to a
 *     table nothing may prune.
 *   * the page that opens a link passes the store it is served under as `$expectedStoreId`, which is a REQUIRED argument, so a
 *     token for store A cannot be honoured as if it had been opened at store B.
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

        ConsentLedger::assertPurpose($purpose);

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
     *
     * THE TOKEN IS A CAPABILITY, so this is the one query in the consent store that has no store predicate of its own: the
     * token names the store. The store the page is SERVED UNDER is therefore a required argument, not an optional one -- the safe
     * call is the only call -- and a token that belongs to another store is answered exactly like an unknown one (no hint that it
     * exists elsewhere).
     */
    public function find(mixed $token, int $expectedStoreId): ?MarketingUnsubscribeToken
    {
        if (! is_string($token) || strlen($token) !== self::TOKEN_LENGTH || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        $row = MarketingUnsubscribeToken::query()->where('token_hash', self::hashOf($token))->first();

        if ($row !== null && (int) $row->store_id !== $expectedStoreId) {
            return null;
        }

        return $row;
    }

    /**
     * Withdraw the contact the token names. Returns the recorded event, or null for an unknown token.
     * `$evidence` is whatever the redeeming request can honestly say about itself (ip, user agent, one-click or page);
     * the ledger makes it encodable, so a hostile header cannot stop the withdrawal from being recorded.
     */
    public function redeem(mixed $token, int $expectedStoreId, string $source = 'unsubscribe_link', array $evidence = []): ?MarketingConsentEvent
    {
        $row = $this->find($token, $expectedStoreId);

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

        // The FIRST use is stamped by one conditional UPDATE, so two redemptions that overlap cannot both believe they were first
        // and a later one cannot move the time. (A read-then-save here let both write, and the test that was meant to catch it
        // could not: two writes inside one second look identical to the date cast.)
        MarketingUnsubscribeToken::query()
            ->whereKey($row->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return $event;
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}
