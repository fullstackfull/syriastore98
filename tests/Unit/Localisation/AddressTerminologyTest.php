<?php

namespace Tests\Unit\Localisation;

use Tests\TestCase;

/**
 * P2.5-COPY-DECK — "State" and "Country" must not be the same Arabic word.
 * =============================================================================================
 *
 * `resources/lang/ar.json` translated BOTH `Country` and `State` as a word meaning country:
 * `Country => البلد`, `State => الدولة`. A store-address form renders them one under the other
 * (`AppSetting/index.blade.php:789`, `front_end/sections/addressbook_add.blade.php:33`), so an
 * Arabic merchant saw two differently-labelled fields that mean the same thing and no way to tell
 * which wanted what.
 *
 * `State` here is a SUB-NATIONAL division — a city belongs to one (`city/create.blade.php:17`) —
 * so the Syrian administrative term is **المحافظة**, and that is now what both the web and the
 * admin app use. It was corrected in the same change that filled the Flutter copy deck, because
 * the deck's own requirement was consistent terminology across the two surfaces, and copying a
 * wrong word would have made them consistently wrong.
 *
 * This test is about the DISTINCTION, not about one spelling: it fails if the two keys ever
 * collapse onto the same value again, whichever value that is.
 */
class AddressTerminologyTest extends TestCase
{
    /** @return array<string, string> */
    private function arabic(): array
    {
        $raw = file_get_contents(base_path('resources/lang/ar.json'));

        $this->assertNotFalse($raw, 'resources/lang/ar.json could not be read.');

        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded, 'resources/lang/ar.json is not valid JSON.');

        return $decoded;
    }

    public function test_state_and_country_are_different_arabic_words(): void
    {
        $ar = $this->arabic();

        foreach (['State', 'Country'] as $key) {
            $this->assertArrayHasKey($key, $ar, $key . ' lost its Arabic translation.');
        }

        $this->assertNotSame(
            $ar['Country'],
            $ar['State'],
            'Country and State translate to the same Arabic word, so a store-address form shows '
            . 'two fields with identical labels. State is a sub-national division here — a city '
            . 'belongs to one — and the Syrian term is المحافظة.'
        );
    }

    public function test_the_two_spellings_of_state_agree_with_each_other(): void
    {
        $ar = $this->arabic();

        // The file carries both `State` and `state`. They are the same word on the same forms, so
        // they must not drift apart; a merchant should not see two spellings of one label.
        $this->assertSame(
            $ar['State'] ?? null,
            $ar['state'] ?? null,
            'the two casings of the state key now translate differently.'
        );
    }

    public function test_the_admin_app_uses_the_same_word(): void
    {
        // CROSS-SURFACE, which is the requirement this came from. The Flutter copy deck was filled
        // with ar.json's own terms wherever it had them; State is the one place both sides had to
        // move. The app's map is in a separate repository, so this asserts the shared VALUE rather
        // than reading that file: if the web term changes, this test is where someone is told the
        // app must change with it.
        $ar = $this->arabic();

        $this->assertSame(
            'المحافظة',
            $ar['State'] ?? null,
            'the web and the admin app no longer agree on the word for State. The app sets '
            . "'State': 'المحافظة' in lib/translations/ar.dart; change both or neither."
        );
    }
}
