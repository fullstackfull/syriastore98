<?php

namespace Workdo\LynomiaChat\app\Services;

use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * How an OUTBOUND commerce event proves it came from this store.
 *
 * THE KEY IS DERIVED FROM THE COMMERCE CREDENTIAL, WHICH BOTH SIDES ALREADY HOLD. The obvious
 * alternative -- a second shared secret -- would have to be distributed, which means a second
 * thing a merchant can paste wrong and a second thing to rotate. Lynomia stores the commerce
 * token to read this store's orders with; Store Syria can recompute it from the master key. So
 * the signing key is derived from it on both sides and nothing new is exchanged.
 *
 * THE TOKEN ITSELF IS NEVER SENT ON A DELIVERY. Only an HMAC made with a key derived FROM it,
 * under a label of its own, so a captured delivery discloses neither the token nor the key that
 * would let the holder read this store's customers. The label is also why a commerce credential
 * cannot be replayed as an event signature or the reverse.
 *
 * REVOCATION IS INHERITED, which is the real reason to derive rather than invent. Bumping
 * lynomia_store_links.commerce_credential_version changes the token, which changes this key, so
 * every delivery signed with the old one stops verifying the moment an operator revokes -- the
 * same instant the read credential stops working, through the same act, with nothing else to
 * remember to turn off.
 */
class CommerceEventSignature
{
    public const SIGNATURE_HEADER = 'X-Storesyria-Signature';
    public const TIMESTAMP_HEADER = 'X-Storesyria-Timestamp';
    public const DELIVERY_HEADER = 'X-Storesyria-Delivery';
    public const STORE_HEADER = 'X-Storesyria-Store';

    /** The purpose label inside the derived key; see the note on replay above. */
    private const PURPOSE = 'storesyria-commerce-events';

    /**
     * The signing key for a store, derived from the credential Lynomia holds for it.
     *
     * @throws \RuntimeException when the store has no link, which is a caller bug: nothing
     *                           records an event for a store that is not connected.
     */
    public static function keyFor(LynomiaStoreLink $link): string
    {
        return hash_hmac('sha256', self::PURPOSE, CommerceCredential::issue($link));
    }

    /** `sha256=<hex>` over "{timestamp}.{body}", the same shape as the inbound direction. */
    public static function sign(LynomiaStoreLink $link, string $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}", self::keyFor($link));
    }
}
