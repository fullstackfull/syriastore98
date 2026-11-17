<?php

namespace Tests\Unit\Marketing;

use App\Support\Marketing\Channel;
use App\Support\Marketing\ContactAddress;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * HOW AN ADDRESS BECOMES A KEY. D8 / MKT-008.
 *
 * Consent is looked up by a hash of the normalised address, so these rules decide whether the person
 * who opted in is recognised when a message is later addressed to them. Two failure directions exist
 * and only one is acceptable: failing to recognise someone who DID consent holds a message that could
 * have gone (safe for marketing), whereas recognising someone who did NOT -- two different people
 * sharing a key -- sends marketing without permission. The tests below are mostly about the second.
 */
class ContactAddressTest extends TestCase
{
    public function test_email_is_trimmed_and_lowercased(): void
    {
        $this->assertSame('a.b@example.com', ContactAddress::normalise(Channel::Email, '  A.B@Example.COM '));
    }

    #[DataProvider('notEmails')]
    public function test_a_non_email_is_refused(string $raw): void
    {
        $this->assertNull(ContactAddress::normalise(Channel::Email, $raw));
    }

    public static function notEmails(): array
    {
        return ['empty' => [''], 'spaces' => ['   '], 'no at' => ['example.com'], 'no domain' => ['a@'], 'two ats' => ['a@b@c.com'], 'phone' => ['0912345678']];
    }

    public function test_null_is_refused_on_every_channel(): void
    {
        foreach (Channel::cases() as $channel) {
            $this->assertNull(ContactAddress::normalise($channel, null), $channel->value);
        }
    }

    public function test_the_same_phone_in_common_spellings_is_one_key(): void
    {
        foreach ([Channel::Sms, Channel::Whatsapp] as $channel) {
            foreach (['+963 912-345 678', '00963912345678', '(963) 912 345 678', '963912345678'] as $spelling) {
                $this->assertSame('963912345678', ContactAddress::normalise($channel, $spelling), $channel->value.' '.$spelling);
            }
        }
    }

    public function test_a_local_spelling_is_a_DIFFERENT_key_and_that_is_the_safe_direction(): void
    {
        $this->assertNotSame(
            ContactAddress::normalise(Channel::Sms, '0912345678'),
            ContactAddress::normalise(Channel::Sms, '963912345678'),
            'Documented limit: without the customer\'s country the two cannot be unified. The failure is a held message, never a leaked one.'
        );
    }

    #[DataProvider('notPhones')]
    public function test_a_non_phone_is_refused(string $raw): void
    {
        $this->assertNull(ContactAddress::normalise(Channel::Sms, $raw));
    }

    public static function notPhones(): array
    {
        return ['too short' => ['12345'], 'too long' => ['1234567890123456'], 'letters' => ['call me'], 'email' => ['a@b.com']];
    }

    public function test_a_device_token_is_kept_exactly_and_must_be_plausible(): void
    {
        $token = str_repeat('aB3-', 10);

        $this->assertSame($token, ContactAddress::normalise(Channel::Push, '  '.$token.' '));
        $this->assertNull(ContactAddress::normalise(Channel::Push, 'short'));
    }

    public function test_the_hash_is_namespaced_by_store_and_by_channel(): void
    {
        $a = ContactAddress::hash(1, Channel::Email, 'x@example.com');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertNotSame($a, ContactAddress::hash(2, Channel::Email, 'x@example.com'),
            'the same person at two stores must be two independent decisions');
        $this->assertNotSame(ContactAddress::hash(1, Channel::Sms, '963912345678'), ContactAddress::hash(1, Channel::Whatsapp, '963912345678'),
            'the same handset on two channels must be two independent decisions');
    }

    public function test_the_boundary_between_store_and_address_cannot_be_shifted(): void
    {
        // store 1 + "2|x" must not collide with store 12 + "|x"-style crafted inputs: the separator is part of the hash input.
        $this->assertNotSame(
            ContactAddress::hash(1, Channel::Email, '2|x@example.com'),
            ContactAddress::hash(12, Channel::Email, '|x@example.com')
        );
    }

    public function test_a_mask_never_reveals_the_whole_address(): void
    {
        $this->assertSame('j***@example.com', ContactAddress::mask(Channel::Email, 'jane.doe@example.com'));
        $this->assertSame('********5678', ContactAddress::mask(Channel::Sms, '963912345678'));
        $this->assertStringNotContainsString('jane.doe', ContactAddress::mask(Channel::Email, 'jane.doe@example.com'));
    }
}
