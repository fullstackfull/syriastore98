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
 * who opted in is recognised when a message is later addressed to them. TWO failure directions exist, and
 * an earlier version of this comment called one of them "the acceptable one" without saying when:
 *
 *   * failing to recognise someone who DID consent holds a message that could have gone. That is safe for a
 *     missing GRANT. For a WITHDRAWAL or SUPPRESSION it is the opposite: the person who opted out under one spelling is still
 *     `granted` under another;
 *   * recognising someone who did NOT -- two different people sharing a key -- sends marketing without permission.
 *
 * WHAT THESE TESTS DO AND DO NOT PIN. They pin the spellings that converge (one handset, many ways of typing it) and the recipe
 * that makes the key. They do NOT, and cannot, prevent two people sharing a key for a phone number that carries no country: national
 * digits are identical across countries ('5012 3456' is any Gulf number with those eight digits). That is a property of keying
 * without a country, recorded in the ContactAddress docblock and the design document, and it is why no phone channel may be
 * enforced until phone identity is canonical.
 */
class ContactAddressTest extends TestCase
{
    public function test_email_is_trimmed_and_lowercased(): void
    {
        $this->assertSame('a.b@example.com', ContactAddress::normalise(Channel::Email, '  A.B@Example.COM '));
    }

    public function test_a_non_ascii_character_that_lowercases_to_ascii_cannot_alias_an_ascii_address(): void
    {
        // U+212A KELVIN SIGN lower-cases to ASCII `k`. Folding before validating made it validate as, and share the key of, `kelvin@...`.
        $this->assertNull(ContactAddress::normalise(Channel::Email, "\u{212A}elvin@example.com"));
        $this->assertSame('kelvin@example.com', ContactAddress::normalise(Channel::Email, 'Kelvin@Example.com'));
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

    public function test_arabic_indic_persian_and_fullwidth_digits_are_digits(): void
    {
        // The byte-wise `\D` used to DELETE every one of these, so an all-Arabic number became nothing and could never be recorded.
        foreach ([Channel::Sms, Channel::Whatsapp] as $channel) {
            foreach (['+٩٦٣ ٩١٢ ٣٤٥ ٦٧٨', '۹۶۳۹۱۲۳۴۵۶۷۸', '＋９６３９１２３４５６７８', '+963 ٩١٢ 345 ٦٧٨', '٠٠٩٦٣٩١٢٣٤٥٦٧٨'] as $spelling) {
                $this->assertSame('963912345678', ContactAddress::normalise($channel, $spelling), $channel->value.' '.$spelling);
            }
        }
    }

    public function test_a_partly_arabic_number_is_no_longer_shrunk_into_somebody_elses_key(): void
    {
        // Before: '٠٩٤٤ 1234 567' kept only its ASCII digits and became '1234567', a valid-looking seven-digit key that belongs to
        // whoever really typed 1234567. Now the Arabic digits count, so it is the eleven-digit number it was.
        $this->assertSame('09441234567', ContactAddress::normalise(Channel::Sms, '٠٩٤٤ 1234 567'));
        $this->assertNotSame('1234567', ContactAddress::normalise(Channel::Sms, '٠٩٤٤ 1234 567'));
    }

    public function test_a_local_spelling_is_a_DIFFERENT_key_and_that_is_only_the_safe_direction_for_a_missing_grant(): void
    {
        $this->assertNotSame(
            ContactAddress::normalise(Channel::Sms, '0912345678'),
            ContactAddress::normalise(Channel::Sms, '963912345678'),
            'Documented limit: without the customer\'s country the two cannot be unified. For a missing GRANT that holds the message. '
            .'For a WITHDRAWAL or SUPPRESSION recorded under the other spelling it does NOT: the contact is still granted to the sender, '
            .'which is why no phone channel may be enforced until phone identity is canonical (ContactAddress docblock, design document 3).'
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
        // These two differ whether or not the hash input has separators, so this alone does not prove the separators matter; the
        // known-answer test below is what pins the exact recipe. It stays as the readable statement of the property.
        $this->assertNotSame(
            ContactAddress::hash(1, Channel::Email, '2|x@example.com'),
            ContactAddress::hash(12, Channel::Email, '|x@example.com')
        );
    }

    /**
     * THE RECIPE IS DATA. Every stored consent row is keyed on this exact function of (store, channel, normalised address), so a
     * change to it, or to `normalise()`, re-keys every decision ever recorded: old withdrawals and suppressions become unreachable
     * while a fresh opt-in reads `granted`. These answers were computed once, independently, as sha256("<store>|<channel>|<address>").
     * If this test fails, do not edit the constants -- write the migration that re-keys the stored rows, then update them with it.
     */
    public function test_the_recipe_is_pinned_by_known_answers(): void
    {
        $this->assertSame('550fb9f549971bf331b66d6892da1d42caa9cb65bb98460887a99ad59bc7ec7d', ContactAddress::hash(1, Channel::Email, 'a@example.com'));
        $this->assertSame('1a9f173551b7f516a7d13fb196ca74e392f9668c11106acd33e3a02d4190226a', ContactAddress::hash(12, Channel::Whatsapp, '963912345678'));
        $this->assertSame('9b10ba182a81a502035560a3b0df0fbc0de17523ac6d9b2934163a4f78c3caab', ContactAddress::hash(7, Channel::Sms, '0912345678'));

        // normalise() feeds the hash, so what it produces for these spellings is pinned too.
        $this->assertSame('a@example.com', ContactAddress::normalise(Channel::Email, "  A@Example.COM \n"));
        $this->assertSame('963912345678', ContactAddress::normalise(Channel::Whatsapp, '+963 (912) 345-678'));
        $this->assertSame('0912345678', ContactAddress::normalise(Channel::Sms, '0912 345 678'));
    }

    public function test_a_mask_always_fits_the_hint_column_even_for_a_very_long_address(): void
    {
        $this->assertSame('************abcd', ContactAddress::mask(Channel::Push, str_repeat('x', 4000).'abcd'));
        $this->assertLessThanOrEqual(191, mb_strlen(ContactAddress::mask(Channel::Email, 'a@'.str_repeat('d', 400).'.com')));
        $this->assertSame('********5678', ContactAddress::mask(Channel::Sms, '963912345678'), 'an ordinary phone is unchanged by the cap');
    }

    public function test_a_mask_never_reveals_the_whole_address(): void
    {
        $this->assertSame('j***@example.com', ContactAddress::mask(Channel::Email, 'jane.doe@example.com'));
        $this->assertSame('********5678', ContactAddress::mask(Channel::Sms, '963912345678'));
        $this->assertStringNotContainsString('jane.doe', ContactAddress::mask(Channel::Email, 'jane.doe@example.com'));
    }
}
