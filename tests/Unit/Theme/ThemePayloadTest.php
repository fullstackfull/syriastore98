<?php

namespace Tests\Unit\Theme;

use App\Support\Theme\ThemePayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M3-C — THE CANONICAL PAYLOAD FORM AND ITS HASH.
 *
 * WHY THIS IS NOT A FORMALITY. Three M3 claims rest on `payload_hash`: immutability evidence for a
 * published snapshot, `theme_publication_events`'s independent record of what was published, and the
 * O(1) "is this draft identical to what is live" check the editor needs on every page load. An
 * undefined serialisation breaks all three in the same way — two logically identical payloads hash
 * differently, so the editor reports a phantom change forever and the audit record stops meaning
 * anything.
 *
 * THE ONE THAT WOULD BE EASY TO GET WRONG, and which the design deliberately rejects: normalising
 * VALUES. 28 settings per theme are `slider` repeaters whose stored value is a JSON *string* the
 * storefront `json_decode()`s at render. Decoding and re-encoding those to "normalise" them reorders
 * their inner keys and changes their escaping, so a value byte-identical to what the merchant saved
 * comes back different. Keys are ordered; values are untouched. That asymmetry is the contract and
 * it is asserted below in both directions.
 */
class ThemePayloadTest extends TestCase
{
    /* ============================================================= order independence of the hash */

    #[Test]
    public function test_key_order_does_not_change_the_hash(): void
    {
        $a = ['site_title' => 'Shop', 'footer_description' => 'Fresh', 'header_search_status' => '1'];
        $b = ['header_search_status' => '1', 'site_title' => 'Shop', 'footer_description' => 'Fresh'];
        $c = ['footer_description' => 'Fresh', 'header_search_status' => '1', 'site_title' => 'Shop'];

        $this->assertSame(ThemePayload::hash($a), ThemePayload::hash($b));
        $this->assertSame(ThemePayload::hash($a), ThemePayload::hash($c));
        $this->assertTrue(ThemePayload::equals($a, $c));
    }

    #[Test]
    public function test_the_encoded_form_is_byte_identical_whatever_the_input_order(): void
    {
        $a = ['b' => '2', 'a' => '1', 'c' => '3'];
        $b = ['c' => '3', 'b' => '2', 'a' => '1'];

        $this->assertSame(ThemePayload::encode($a), ThemePayload::encode($b));
        $this->assertSame('{"a":"1","b":"2","c":"3"}', ThemePayload::encode($a));
    }

    /* ======================================================= equivalent scalar representations */

    /**
     * `theme_customizes.value` is `longtext`, so every stored setting is a string. `1` and `'1'`
     * are the same stored value and must hash the same, or a draft saved from a form (strings) will
     * never compare equal to one built in code (ints).
     */
    #[Test]
    #[DataProvider('equivalentScalarProvider')]
    public function test_equivalent_scalars_hash_the_same(mixed $a, mixed $b, string $why): void
    {
        $this->assertSame(
            ThemePayload::hash(['k' => $a]),
            ThemePayload::hash(['k' => $b]),
            $why);
    }

    public static function equivalentScalarProvider(): array
    {
        return [
            'int vs numeric string'  => [1, '1', 'a switch posted as 1 and stored as "1"'],
            'zero'                   => [0, '0', 'an off switch'],
            'float vs string'        => [1.5, '1.5', 'a numeric setting'],
            'true vs "1"'            => [true, '1', 'a checkbox posted as a boolean'],
            'false vs "0"'           => [false, '0', 'an unchecked checkbox'],
            'null vs empty string'   => [null, '', 'a cleared field'],
        ];
    }

    /* ================================================== values are NEVER re-encoded (the contract) */

    /**
     * THE ASYMMETRY THAT MATTERS. A repeater's JSON string must survive byte-exactly, including a
     * key order no sane serialiser would choose and escaping a re-encode would change.
     */
    #[Test]
    public function test_a_repeater_json_string_survives_byte_exactly(): void
    {
        // Deliberately: keys out of alphabetical order, an escaped slash, a unicode character.
        $repeater = '[{"link":"https:\/\/x.com\/shop","icon":"fab fa-x-twitter","platform":"المتجر"}]';

        $encoded = ThemePayload::encode(['footer_repeater' => $repeater]);
        $back = ThemePayload::decode($encoded);

        $this->assertSame($repeater, $back['footer_repeater'],
            'A repeater value was altered on the round trip. The storefront json_decode()s it at '
            . 'render time, so re-encoding reorders its inner keys and changes its escaping: a value '
            . 'byte-identical to what the merchant saved would come back different, and every '
            . 'payload_hash comparison would report a phantom change.');
    }

    #[Test]
    public function test_two_repeaters_differing_only_in_inner_key_order_hash_DIFFERENTLY(): void
    {
        $one = '[{"link":"https://a.test","icon":"fa-x"}]';
        $two = '[{"icon":"fa-x","link":"https://a.test"}]';

        $this->assertNotSame(ThemePayload::hash(['footer_repeater' => $one]),
            ThemePayload::hash(['footer_repeater' => $two]),
            'These two SHOULD hash differently. They are different stored bytes, and the contract is '
            . 'that values are preserved verbatim rather than normalised. Making them hash the same '
            . 'would require re-encoding, which is the thing the test above forbids. This asserts '
            . 'the cost of that decision is understood and deliberate.');
    }

    /* ================================================================================ the shape */

    #[Test]
    public function test_an_empty_payload_encodes_as_an_object_not_a_list(): void
    {
        $this->assertSame('{}', ThemePayload::encode([]),
            'PHP cannot tell an empty map from an empty list, so json_encode([]) is "[]". A payload '
            . 'stored as "[]" decodes back as a LIST and breaks every string=>string assumption '
            . 'downstream, which is why encode() forces "{}".');

        $this->assertSame([], ThemePayload::decode('{}'));
    }

    #[Test]
    public function test_an_array_value_is_refused_rather_than_serialised(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/repeater arrives as a JSON string/');

        ThemePayload::encode(['footer_repeater' => [['link' => 'https://a.test']]]);
    }

    #[Test]
    public function test_an_empty_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ThemePayload::encode(['' => 'value']);
    }

    #[Test]
    public function test_an_oversize_value_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/byte ceiling/');

        ThemePayload::encode(['k' => str_repeat('x', ThemePayload::MAX_VALUE_BYTES + 1)]);
    }

    /* ========================================================================== hash properties */

    #[Test]
    public function test_the_hash_is_sha256_of_exactly_the_stored_payload(): void
    {
        $payload = ['site_title' => 'Shop', 'a' => 'b'];
        $encoded = ThemePayload::encode($payload);

        $this->assertSame(hash('sha256', $encoded), ThemePayload::hash($payload),
            'The hash must be derivable from the stored payload column alone, or '
            . 'ThemeVersion::hashIsIntact() cannot detect tampering.');

        $this->assertSame(64, strlen(ThemePayload::hash($payload)),
            'payload_hash is CHAR(64); a different length would be silently truncated under '
            . "production's non-strict sql_mode.");
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ThemePayload::hash($payload));
    }

    #[Test]
    public function test_a_single_changed_character_changes_the_hash(): void
    {
        $this->assertNotSame(
            ThemePayload::hash(['site_title' => 'Shop']),
            ThemePayload::hash(['site_title' => 'Shoq']));
    }

    #[Test]
    public function test_the_decoded_form_is_always_string_to_string(): void
    {
        $back = ThemePayload::decode('{"a":"1","b":"","c":"text"}');

        foreach ($back as $k => $v) {
            $this->assertIsString($k);
            $this->assertIsString($v, "Setting '{$k}' decoded as " . gettype($v)
                . '; every reader treats a stored setting as a string.');
        }
    }

    /* ============================================================ a realistic full-size snapshot */

    /**
     * A real theme's defaults as a payload: 511-523 keys, 31-35 KB. This asserts the ceilings are
     * not accidentally below what a genuine snapshot needs — a ceiling that refuses real data is a
     * worse defect than no ceiling.
     */
    #[Test]
    public function test_a_full_theme_snapshot_fits_and_round_trips(): void
    {
        $path = dirname(__DIR__, 3) . '/themes/stylique/default_data/settings.php';
        $this->assertFileExists($path);

        $defaults = include $path;
        $this->assertGreaterThan(400, count($defaults),
            'Only ' . count($defaults) . ' default keys were loaded; measured at M3-A, stylique '
            . 'ships 511. This assertion is otherwise vacuous.');

        /* The themes ship a few non-scalar defaults (M3-A measured `team_slider` as a PHP array
         * against a declared string type), which encode() refuses by design. Those are not what a
         * merchant's override set contains, so they are filtered rather than accommodated. */
        $scalars = array_filter($defaults, static fn ($v) => ! is_array($v) && ! is_object($v));

        $encoded = ThemePayload::encode($scalars);
        $this->assertLessThan(ThemePayload::MAX_PAYLOAD_BYTES, strlen($encoded),
            'A full snapshot is ' . strlen($encoded) . ' bytes, over the payload ceiling.');

        $this->assertSame(
            ThemePayload::canonicalise($scalars),
            ThemePayload::decode($encoded),
            'A full theme snapshot did not survive the round trip.');
    }
}
