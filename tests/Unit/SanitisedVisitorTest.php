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
            'email', 'mail', 'auth', 'access_auth', 'cookie', 'session', 'cvv', 'card', 'card_number', 'pin'];

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
}
