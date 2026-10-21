<?php

namespace App\Services;

/**
 * Removes payment-gateway SECRETS from a payment-method entry before it leaves the server. P1-SEC-15.
 *
 * `Api\ApiController::payment_list` builds the checkout's method list and, for each gateway, puts the
 * merchant's live secret next to the display fields (`stripe_secret_key`, `paystack_secret`,
 * `paytm_merchant_key` ...). The route is reachable by any Sanctum token and `POST /api/{slug}/register`
 * hands one to anybody, so any visitor could name a victim store's slug and read its keys.
 *
 * A client never needs a secret to LIST payment methods -- the server charges -- so every value whose
 * NAME marks it as one is replaced by null. The KEY stays: a client that decodes the entry into a typed
 * model keeps its shape. Publishable / public keys, client and merchant IDENTIFIERS, modes, names and
 * images are the legitimate payload and are untouched.
 *
 * Classification is by name, and PINNED: tests/Feature/Security/PaymentListSecretDisclosureTest
 * harvests every field the endpoint can return and fails on any name that is in neither its SECRET nor
 * its KEEP list, so a gateway added tomorrow cannot reach clients unclassified.
 */
final class GatewaySecretRedactor
{
    private const SECRET_NAME = '/secret|private|salt|auth_code|auth_token|access_token|token_key|company_token|api_key|server_key|transaction_key|signature|password|merchant_key|integration_key/i';

    /** Names that are secrets but carry none of the words above. */
    private const SECRET_EXACT = ['cashfree_key'];

    public static function isSecret(string $name): bool
    {
        return in_array($name, self::SECRET_EXACT, true) || preg_match(self::SECRET_NAME, $name) === 1;
    }

    /** @param array<string, mixed> $entry */
    public static function redact(array $entry): array
    {
        foreach ($entry as $name => $value) {
            if (self::isSecret((string) $name)) {
                $entry[$name] = null;
            }
        }

        return $entry;
    }

    /** @param array<int|string, mixed> $entries */
    public static function redactAll(array $entries): array
    {
        return array_map(fn ($entry) => is_array($entry) ? self::redact($entry) : $entry, $entries);
    }
}
