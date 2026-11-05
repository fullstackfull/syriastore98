<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * `info` IS NOT THE INFO LINE. IT IS ONE OF THREE.  P2.5-WAVE-9
 * =================================================================================================
 *
 * The DataTables language block translated `info` and had done for some time. MEASURED on the
 * Arabic build, driving the real application:
 *
 *     /product  32 rows    sInfo          -> "تظهر 1 إلى 10 من 32 إدخالات"        correct
 *     /coupon    0 rows    sInfoEmpty     -> "Showing 0 to 0 of 0 entries"        ENGLISH
 *     any search           sInfoFiltered  -> "(filtered from _MAX_ total entries)" ENGLISH
 *
 * DataTables swaps between three keys depending on the state of the table, and only the one that
 * shows on a populated unfiltered table had been translated. `infoEmpty` is what an EMPTY list
 * shows -- the first screen a new merchant ever sees -- and `infoFiltered` is appended the moment
 * anyone types in the search box.
 *
 * THE DIAGNOSIS TOOK FOUR WRONG TURNS, all of them mine, and the detail matters because each one
 * would have produced a confident wrong answer:
 *
 *   1. I recorded this as "the info string is untranslated". The string WAS translated.
 *   2. I checked the four keys in ar.json and found all four present, and nearly closed it as
 *      already-fixed.
 *   3. I found two `->language([...])` calls on the same builder and assumed the second was
 *      overwriting the first. Reading the config out of the BROWSER showed `sInfo` arriving
 *      correctly in Arabic -- the merge was fine.
 *   4. Two probes before that reported "(absent)" and "[]" for the element. They were measuring
 *      an UNAUTHENTICATED page: the login was answering 419 because the dev server was still
 *      holding state from before the fixture was restored. Those readings meant nothing.
 *
 * Only reading `oLanguage` out of the live DataTable settings showed which of the three keys was
 * actually in play.
 *
 * `infoEmpty` NEEDED NO NEW TRANSLATION: it is the same four keys with literal zeroes.
 *
 * AND A THIRD, FOUND WHILE LOOKING: `loadingRecords` DEFAULTS TO `zeroRecords` inside DataTables
 * itself. With P2.5-WAVE-2's richer empty state that meant a table still LOADING announced
 * "Nothing matches your search or filters. Clear them to see everything again." -- a filter
 * result stated before any result exists. Measured: `sLoadingRecords` carried the zeroRecords
 * markup verbatim.
 */
class DataTableInfoLocalisationTest extends TestCase
{
    private const HELPER = 'app/Helper/helper.php';

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** All three info-family keys are set, not just the one that shows on a full table. */
    public function test_every_info_variant_datatables_can_show_is_configured(): void
    {
        $helper = $this->read(self::HELPER);

        foreach (['info', 'infoEmpty', 'infoFiltered'] as $key) {
            $this->assertMatchesRegularExpression(
                '/"' . $key . '"\s*=>/',
                $helper,
                "`{$key}` is not set, so DataTables falls back to its built-in English for the "
                    . 'table state that uses it'
            );
        }
    }

    /** The loading message is a loading message, not a filter result. */
    public function test_the_loading_message_is_not_the_filtered_empty_message(): void
    {
        $helper = $this->read(self::HELPER);

        $this->assertMatchesRegularExpression(
            '/"loadingRecords"\s*=>\s*__\(\s*"Loading"/',
            $helper,
            'loadingRecords is unset, so DataTables defaults it to zeroRecords and a loading table '
                . 'announces that nothing matches a filter nobody applied'
        );
    }

    /** Every key these strings ask for exists in BOTH files, or one language renders the key. */
    public function test_every_key_the_info_strings_ask_for_exists_in_both_languages(): void
    {
        $needed = ['Showing', 'to', 'of', 'entries', 'filtered from', 'total entries', 'Loading'];

        foreach (['resources/lang/ar.json', 'resources/lang/en.json'] as $file) {
            $map = json_decode($this->read($file), true);
            $this->assertIsArray($map, "{$file} is not valid JSON");

            foreach ($needed as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $map,
                    "{$file} has no `{$key}`, so that language renders the key itself inside the "
                        . 'table info line'
                );
            }
        }
    }

    /** The Arabic values are actually Arabic, not the English copied across. */
    public function test_the_new_arabic_values_are_arabic(): void
    {
        $ar = json_decode($this->read('resources/lang/ar.json'), true);

        foreach (['filtered from', 'total entries', 'Loading'] as $key) {
            $this->assertMatchesRegularExpression(
                '/[\x{0600}-\x{06FF}]/u',
                $ar[$key],
                "ar.json's `{$key}` contains no Arabic characters, so it is the English value "
                    . 'sitting in the Arabic file'
            );
        }
    }
}
