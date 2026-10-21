<?php

namespace Tests\Unit;

use App\Services\SanitisedVisitor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-13. The URL half of the visit scrub, driven directly so that EACH credential-looking
 * parameter family is pinned on its own (the feature test only exercises token / email / otp).
 * No framework, no database.
 */
class SanitisedVisitorTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function sensitiveParameters(): array
    {
        $names = ['token', 'password', 'passwd', 'pwd', 'otp', 'code', 'secret', 'signature', 'sig', 'api_key', 'key',
            'email', 'mail', 'auth', 'access_auth', 'cookie', 'session', 'cvv', 'card', 'card_number', 'pin',
            'pw', 'cvc', 'hash', 'nonce', 'jwt', 'bearer', 'credential', 'ticket', 'sessid', 'PHPSESSID', 'iban', 'account_number', 'sid', 'cc'];

        $cases = [];
        foreach ($names as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    #[DataProvider('sensitiveParameters')]
    public function test_the_value_of_a_credential_looking_parameter_is_replaced(string $name): void
    {
        $redacted = SanitisedVisitor::redact("https://shop.test/x?{$name}=VALUE-SENTINEL&page=2");

        $this->assertStringNotContainsString('VALUE-SENTINEL', $redacted, "'{$name}' kept its value.");
        $this->assertStringContainsString('page=2', $redacted, 'A harmless parameter was lost.');
    }

    public function test_the_name_is_matched_case_insensitively(): void
    {
        $this->assertStringNotContainsString('V-SENTINEL', SanitisedVisitor::redact('https://shop.test/x?Token=V-SENTINEL&EMAIL=V-SENTINEL'));
    }

    public function test_a_nested_parameter_is_redacted_where_it_sits(): void
    {
        $redacted = SanitisedVisitor::redact('https://shop.test/x?user[token]=V-SENTINEL&user[name]=keepme');

        $this->assertStringNotContainsString('V-SENTINEL', $redacted);
        $this->assertStringContainsString('keepme', $redacted);
    }

    public function test_the_path_the_fragment_and_a_query_free_url_survive_untouched(): void
    {
        $this->assertSame('https://shop.test/a/b', SanitisedVisitor::redact('https://shop.test/a/b'));
        $this->assertSame('https://shop.test/a/b#top', SanitisedVisitor::redact('https://shop.test/a/b#top'));
        $this->assertSame('https://shop.test/a?page=2#top', SanitisedVisitor::redact('https://shop.test/a?page=2#top'));
        $this->assertSame('https://shop.test/a', SanitisedVisitor::redact('https://shop.test/a?'));
    }

    public function test_a_secret_hidden_in_the_fragment_position_of_a_query_is_still_redacted(): void
    {
        // The fragment is split off BEFORE the query is parsed, so '#' cannot smuggle a value past the parser.
        $redacted = SanitisedVisitor::redact('https://shop.test/x?token=V-SENTINEL#frag');

        $this->assertStringNotContainsString('V-SENTINEL', $redacted);
        $this->assertStringEndsWith('#frag', $redacted);
    }

    /** A Laravel Crypt::encrypt() payload in a PATH is a capability link (order details, receipts, labels). */
    public function test_an_encrypted_payload_in_the_path_is_redacted_wherever_the_route_puts_it(): void
    {
        $cipher = 'eyJpdiI6Im5SZEJBTGJ3cWhFUjZGS1pRUzdmQXc9PSIsInZhbHVlIjoiYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoiLCJtYWMiOiIxMjM0NTY3ODkwIiwidGFnIjoiIn0%3D';

        foreach (['/shop/order/', '/shop/customerorder/', '/shop/order-view/', '/lottery-order-view/'] as $prefix) {
            $redacted = SanitisedVisitor::redact('https://shop.test' . $prefix . $cipher . '?page=2');

            $this->assertStringNotContainsString('eyJpdiI6', $redacted, "{$prefix}: the ciphertext was kept.");
            $this->assertStringContainsString($prefix, $redacted, 'The route prefix is analytics; it must survive.');
            $this->assertStringContainsString('page=2', $redacted);
        }
    }

    public function test_an_ordinary_path_segment_is_not_mistaken_for_ciphertext(): void
    {
        $this->assertSame('https://shop.test/collections/eyJ-fashion', SanitisedVisitor::redact('https://shop.test/collections/eyJ-fashion'));
        $this->assertSame('https://shop.test/product/summer-dress-2026', SanitisedVisitor::redact('https://shop.test/product/summer-dress-2026'));
    }

    /** `request_data` of the Easebuzz / AamarPay return carries the whole checkout as one JSON value. */
    public function test_a_json_or_oversized_value_is_replaced_whatever_its_name(): void
    {
        $json = SanitisedVisitor::redact('https://shop.test/x?request_data=' . rawurlencode('{"name":"V-SENTINEL","phone":"555"}') . '&page=2');
        $this->assertStringNotContainsString('V-SENTINEL', $json);
        $this->assertStringContainsString('page=2', $json);

        $list = SanitisedVisitor::redact('https://shop.test/x?blob=' . rawurlencode('["V-SENTINEL"]'));
        $this->assertStringNotContainsString('V-SENTINEL', $list);

        $long = SanitisedVisitor::redact('https://shop.test/x?note=' . str_repeat('V-SENTINEL', 30));
        $this->assertStringNotContainsString('V-SENTINEL', $long);
    }

    /** `?next=/reset-password?token=T` -- the inner URL's query is parsed too. */
    public function test_a_url_valued_parameter_has_its_own_query_redacted(): void
    {
        $redacted = SanitisedVisitor::redact('https://shop.test/x?next=' . rawurlencode('/reset-password?token=V-SENTINEL&page=2'));

        $this->assertStringNotContainsString('V-SENTINEL', $redacted);
        $this->assertStringContainsString('reset-password', rawurldecode($redacted));
    }

    public function test_invalid_utf8_is_scrubbed_so_a_later_json_encode_or_insert_cannot_fail(): void
    {
        $redacted = SanitisedVisitor::redact("https://shop.test/x?q=\xFF\xFE&page=2");

        $this->assertTrue(mb_check_encoding($redacted, 'UTF-8'), 'The redacted URL still contains invalid UTF-8.');
        $this->assertStringContainsString('page=2', $redacted);
    }

    /** The path is the one place raw invalid bytes survive (the query is re-encoded), so it is scrubbed -- review round 3. */
    public function test_invalid_utf8_in_the_path_is_scrubbed(): void
    {
        $redacted = SanitisedVisitor::redact("https://shop.test/p\xFFq/page?x=1");

        $this->assertTrue(mb_check_encoding($redacted, 'UTF-8'), 'The path still contains invalid UTF-8.');
        $this->assertStringContainsString('?x=1', $redacted);
    }

    /** Scrubbing BEFORE the split turned a bad path byte into a '?', which became the separator and shifted every name. */
    public function test_a_bad_path_byte_does_not_become_the_query_separator(): void
    {
        $redacted = SanitisedVisitor::redact("https://shop.test/p\xFFq?sid=V-SENTINEL&page=2");

        $this->assertStringNotContainsString('V-SENTINEL', $redacted, 'The exact-name parameter `sid` kept its value.');
        $this->assertStringContainsString('page=2', $redacted);
    }

    /** Route matching percent-decodes the path, so the capability link can be spelled with an encoded 'e'. */
    public function test_a_percent_encoded_capability_link_is_redacted_too(): void
    {
        $cipher = 'eyJpdiI6Im5SZEJBTGJ3cWhFUjZGS1pRUzdmQXc9PSIsInZhbHVlIjoiYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoiLCJtYWMiOiIxMjM0NTY3ODkwIiwidGFnIjoiIn0%3D';
        $encoded = '%65' . substr($cipher, 1);   // %65 = 'e'

        foreach (['/shop/order/' . $encoded, '/shop/order%2F' . $cipher] as $path) {
            $redacted = SanitisedVisitor::redact('https://shop.test' . $path);

            $this->assertStringNotContainsString('eyJpdiI6', $redacted, "{$path}: the ciphertext was kept.");
            $this->assertStringNotContainsString('pdiI6', $redacted, "{$path}: part of the ciphertext was kept.");
        }
    }

    /** Crypt::encrypt() of a small scalar is EXACTLY 200 characters, so `strlen > 200` missed it in a query value. */
    public function test_a_ciphertext_or_jwt_value_is_redacted_whatever_its_length_or_name(): void
    {
        $cipher200 = 'eyJpdiI6' . str_repeat('A', 192);
        $this->assertSame(200, strlen($cipher200), 'Fixture premise: exactly the boundary length.');

        foreach (['anything', 'next', 'ref'] as $name) {
            $this->assertStringNotContainsString('eyJpdiI6', SanitisedVisitor::redact("https://shop.test/x?{$name}={$cipher200}"), "{$name}: the value was kept.");
        }

        // A JWT-shaped fixture BUILT AT RUNTIME: the secret scan's jwt-literal rule has no fixture exemption (on purpose), and
        // a literal `eyJ...eyJ...` in source would be reported as a credential. This one is the canonical example header/payload.
        $b64 = fn (string $json) => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $jwt = $b64('{"alg":"HS256"}') . '.' . $b64('{"sub":"1"}') . '.fixture-signature';
        $this->assertStringStartsWith('eyJ', $jwt, 'Fixture premise: it must look like a JWT.');
        $this->assertStringNotContainsString('eyJhbGci', SanitisedVisitor::redact('https://shop.test/x?t=' . $jwt));
        $this->assertStringContainsString('q=eyelet', SanitisedVisitor::redact('https://shop.test/x?q=eyelet'), 'A harmless value that merely starts with "ey" must survive.');
    }
}
