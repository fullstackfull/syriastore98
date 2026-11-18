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
 * THE HASH IS PART OF EVERY STORED ROW, SO ITS RECIPE IS PART OF THE DATA. `hash()` and `normalise()` together decide which
 * stored decision a message is checked against. Changing either -- most likely to make phone numbers canonical, below --
 * silently re-keys every grant, withdrawal and suppression already written: the old rows become unreachable, the contact
 * reads as `none` (held, which is safe), but an old WITHDRAWAL or SUPPRESSION is then invisible and a fresh opt-in under the
 * new key reads `granted` (which is not). `ContactAddressTest::test_the_recipe_is_pinned_by_known_answers` fails on any such
 * change, so it is made on purpose, together with a migration that re-keys the stored rows.
 *
 * KNOWN LIMIT, recorded rather than smoothed over, and an earlier version of this paragraph understated it. Phone numbers
 * are compared as the digits the merchant stored. `0912345678` and `963912345678` are the same handset and two different
 * keys here; making them one needs the customer's country, which this repository does not reliably hold and for which no
 * phone-number library is installed. The two directions are NOT alike:
 *
 *   * a missing GRANT under the other spelling reads as "no consent" and the send is held. That is the safe direction.
 *   * a WITHDRAWAL or SUPPRESSION recorded under one spelling (a provider's STOP webhook arrives in international form, a
 *     merchant types the local form) is invisible to a send addressed under the other, so a person who opted out is still
 *     `granted` to the sender. That is NOT safe, and it is why no phone channel may be switched to `enforce` until phone
 *     identity is made canonical (an owner decision: a store-level default country plus a library such as libphonenumber).
 *
 * An unsubscribe TOKEN does not have the problem, because it is issued for the exact normalised address the message went to.
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

    /** The longest run of `*` a mask shows. A push token can be 4096 characters; the hint column holds 191. */
    private const MASK_MAX_STARS = 12;

    /** A form safe to show a person or print in an audit view: enough to recognise, not enough to use. Always fits `address_hint`. */
    public static function mask(Channel $channel, string $normalised): string
    {
        if ($channel === Channel::Email && str_contains($normalised, '@')) {
            [$local, $domain] = explode('@', $normalised, 2);

            return mb_substr(mb_substr($local, 0, 1).'***@'.$domain, 0, 191);
        }

        $length = mb_strlen($normalised);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', min($length - 4, self::MASK_MAX_STARS)).mb_substr($normalised, -4);
    }

    private static function email(string $raw): ?string
    {
        // VALIDATE THE STRING AS GIVEN, THEN FOLD CASE. The first version lower-cased first, and `mb_strtolower` maps the KELVIN SIGN
        // (U+212A) to an ASCII `k`, so a non-ASCII address validated as a different, ASCII one and shared its key. `FILTER_VALIDATE_EMAIL`
        // accepts no non-ASCII character, so validating first refuses it; and once the string is known to be ASCII, `strtolower` is exact.
        if (filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return strtolower($raw);
    }

    private static function phone(string $raw): ?string
    {
        // `+963 912-345 678`, `00963912345678` and `(963) 912 345 678` are all the same digits. Arabic-Indic, Persian and full-width
        // digits are digits too: `\D` below works on BYTES (no `u` modifier) and would DELETE every one of them, turning an all-Arabic
        // number into nothing and a partly-Arabic one into a shorter, valid-looking number that is somebody else's key.
        $digits = preg_replace('/\D+/', '', self::asciiDigits($raw)) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $length = strlen($digits);

        return ($length >= 7 && $length <= 15) ? $digits : null;
    }

    /** Arabic-Indic (U+0660..), Extended Arabic-Indic (U+06F0.., Persian and Urdu) and full-width (U+FF10..) digits to 0-9. */
    private static function asciiDigits(string $text): string
    {
        static $map = null;

        if ($map === null) {
            $map = [];

            foreach ([0x0660, 0x06F0, 0xFF10] as $zero) {
                for ($digit = 0; $digit < 10; $digit++) {
                    $map[mb_chr($zero + $digit, 'UTF-8')] = (string) $digit;
                }
            }
        }

        return strtr($text, $map);
    }

    private static function deviceToken(string $raw): ?string
    {
        $length = strlen($raw);

        return ($length >= 16 && $length <= 4096) ? $raw : null;
    }
}
