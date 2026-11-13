<?php

namespace App\Support\Theme;

use InvalidArgumentException;

/**
 * PHASE 03 M3-C — THE CANONICAL FORM OF A THEME CONFIGURATION PAYLOAD, AND ITS HASH.
 * ================================================================================================
 *
 * WHY A CANONICAL FORM IS REQUIRED BEFORE ANYTHING USES `payload_hash`
 * ------------------------------------------------------------------------------------------------
 * Three claims in the M3 design rest on that hash: it is immutability evidence for a published
 * snapshot, it is `theme_publication_events`'s independent record of what was actually published,
 * and it is the O(1) answer to "is this draft identical to what is live". A review pointed out that
 * none of the three survives an undefined serialisation: key order, unicode and slash escaping, and
 * numeric-string normalisation were all unspecified, so two logically identical payloads could hash
 * differently and the editor would report a phantom change on every page load.
 *
 * THE RULE, AND THE ONE THING IT DELIBERATELY DOES NOT DO
 * ------------------------------------------------------------------------------------------------
 * A payload is a flat `name => value` map, exactly the shape `theme_customizes` holds and exactly
 * what `getThemeSetting()` merges over the theme's defaults. The canonical form is:
 *
 *   1. every key cast to string and the map sorted by key (`ksort`, byte order);
 *   2. every value cast to string, because `theme_customizes.value` is `longtext` and the storefront
 *      reads strings -- `1` and `'1'` are the same stored setting and must hash the same;
 *   3. `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and no pretty printing.
 *
 * **VALUES ARE NEVER RE-ENCODED.** That is the deliberate omission and it matters. 28 settings per
 * theme are `slider` type -- repeaters whose stored value is itself a JSON *string*, which the
 * storefront `json_decode()`s at render time. Normalising those by decoding and re-encoding would
 * reorder their inner keys and change their escaping, so a value byte-identical to what the merchant
 * saved would come back different. The canonical form therefore orders KEYS and leaves every VALUE
 * byte-exact.
 *
 * WHAT THE HASH DOES AND DOES NOT CERTIFY
 * ------------------------------------------------------------------------------------------------
 * It certifies the STORED state: these names mapped to these exact bytes. It says nothing about what
 * the merchant typed, because `app/Http/Middleware/XSS.php:35` deletes `javascript` and `alert` from
 * every request string before any of this runs (tracked as S-09 in
 * `docs/discovery/11-RELEASE-READINESS.md`; measured: `Smart alerts for shoppers` is stored as
 * `Smart s for shoppers`). A payload hash that claimed to represent merchant intent would be a lie;
 * this one represents the projection, which is the thing publish and rollback actually move.
 *
 * `htmlspecialchars_decode` in that same middleware is NOT IDEMPOTENT, which is why
 * `ThemeConfigurationService` publishes stored values directly and never re-submits a saved payload
 * through a web route: entities would progressively unescape across cycles and the hash would drift
 * for content nobody touched.
 */
final class ThemePayload
{
    /** Cap on a single stored value, so one pathological setting cannot blow up a snapshot. */
    public const MAX_VALUE_BYTES = 262144;      // 256 KiB; the largest shipped default is 1,437 B

    /** Cap on a whole payload. A full snapshot measured 31-35 KB across the three themes. */
    public const MAX_PAYLOAD_BYTES = 2097152;   // 2 MiB

    /**
     * The canonical array: string keys sorted, string values untouched.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<string, string>
     */
    public static function canonicalise(array $payload): array
    {
        $out = [];

        foreach ($payload as $name => $value) {
            $name = (string) $name;

            if ($name === '') {
                throw new InvalidArgumentException('A theme setting cannot have an empty name.');
            }

            if (is_array($value) || is_object($value)) {
                /*
                 * Refused rather than serialised. The projection column is `longtext` and every
                 * reader treats it as a string, so an array here means a caller is about to invent
                 * a second encoding for repeaters -- which is exactly what would break byte-exact
                 * round-tripping. A repeater's value arrives as a JSON STRING and stays one.
                 */
                throw new InvalidArgumentException(
                    "Theme setting '{$name}' was given a " . gettype($value) . '. A stored setting '
                    . 'is a string: a repeater arrives as a JSON string and must stay one, because '
                    . 're-encoding it would reorder its keys and change its escaping.');
            }

            if (is_bool($value)) {
                /* The `switch` type stores 1/0, which is what the themes' own defaults use. */
                $value = $value ? '1' : '0';
            }

            $value = (string) ($value ?? '');

            if (strlen($value) > self::MAX_VALUE_BYTES) {
                throw new InvalidArgumentException(
                    "Theme setting '{$name}' is " . strlen($value) . ' bytes, over the '
                    . self::MAX_VALUE_BYTES . '-byte ceiling.');
            }

            $out[$name] = $value;
        }

        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * The canonical JSON. This exact string is what gets hashed and what `theme_versions.payload`
     * stores, so a stored payload and its hash can always be re-derived from one another.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        $json = json_encode(
            self::canonicalise($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException(
                'The payload is ' . strlen($json) . ' bytes, over the ' . self::MAX_PAYLOAD_BYTES
                . '-byte ceiling. A full snapshot of a shipped theme measures 31-35 KB.');
        }

        /*
         * An empty payload encodes as `[]`, not `{}`, because PHP cannot tell an empty map from an
         * empty list. Forced to `{}` so a store with no overrides round-trips to an ARRAY rather
         * than decoding back as a list and breaking every `string => string` type assumption
         * downstream.
         */
        return $json === '[]' ? '{}' : $json;
    }

    /** @return array<string, string> */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('A theme payload must decode to an object.');
        }

        /* Re-canonicalised on the way out: a payload written before a ceiling changed, or by hand,
         * still yields the same shape every caller expects. */
        return self::canonicalise($decoded);
    }

    /**
     * SHA-256 of the canonical JSON, lower-case hex — the 64 characters `payload_hash` holds.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        return hash('sha256', self::encode($payload));
    }

    /** Do two payloads hold the same settings, whatever order they arrived in? */
    public static function equals(array $a, array $b): bool
    {
        return self::hash($a) === self::hash($b);
    }
}
