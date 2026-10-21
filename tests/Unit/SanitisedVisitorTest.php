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
}
