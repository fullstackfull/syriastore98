<?php

namespace App\Support\Marketing;

/**
 * One person-and-channel, reduced to a comparable form. D8 / MKT-008.
 *
 * CONSENT IS KEYED ON A HASH OF THE NORMALISED ADDRESS, NOT ON THE ADDRESS. A lookup needs only
 * equality, and the events table is evidence that outlives the contact: storing the raw address in
 * a second place would duplicate personal data for no enforcement benefit. The customer, where
 * there is one, is recorded alongside, so a human audit can still say who it was.
 *
 * THE HASH IS NOT A SECRET and is not presented as one. It is unsalted per person on purpose (the
 * sender must be able to recompute it from the address alone), and an email address is guessable
 * from a dictionary. What it buys is that the table does not read like an address book. It is
 * namespaced by store and channel so the same person at two merchants, or on two channels, is two
 * independent decisions -- consent is never shared across tenants.
 *
 * KNOWN LIMIT, recorded rather than smoothed over. Phone numbers are compared as the digits the
 * merchant stored. `0912345678` and `963912345678` are the same handset and two different keys
 * here; making them one needs the customer's country, which this repository does not reliably
 * hold. The failure is in the safe direction for marketing -- a mismatch reads as "no consent" and
 * the send is held -- and a customer who opted in under one spelling is simply not recognised under
 * the other until the spelling is reconciled.
 */
final class ContactAddress
{
    /** @return string|null the comparable form, or null when the input cannot be an address on this channel */
    public static function normalise(Channel $channel, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        return match ($channel) {
            Channel::Email => self::email($raw),
            Channel::Sms, Channel::Whatsapp => self::phone($raw),
            Channel::Push => self::deviceToken($raw),
        };
    }

    public static function hash(int $storeId, Channel $channel, string $normalised): string
    {
        return hash('sha256', $storeId.'|'.$channel->value.'|'.$normalised);
    }

    /** A form safe to show a person or print in an audit view: enough to recognise, not enough to use. */
    public static function mask(Channel $channel, string $normalised): string
    {
        if ($channel === Channel::Email && str_contains($normalised, '@')) {
            [$local, $domain] = explode('@', $normalised, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        $length = mb_strlen($normalised);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4).mb_substr($normalised, -4);
    }

    private static function email(string $raw): ?string
    {
        $email = mb_strtolower($raw);

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private static function phone(string $raw): ?string
    {
        // `+963 912-345 678`, `00963912345678` and `(963) 912 345 678` are all the same digits.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $length = strlen($digits);

        return ($length >= 7 && $length <= 15) ? $digits : null;
    }

    private static function deviceToken(string $raw): ?string
    {
        $length = strlen($raw);

        return ($length >= 16 && $length <= 4096) ? $raw : null;
    }
}
