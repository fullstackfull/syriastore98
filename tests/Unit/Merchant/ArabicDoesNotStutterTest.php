<?php

namespace Tests\Unit\Merchant;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 — eight Arabic strings said the same word twice in a row.
 * =================================================================================================
 *
 * FOUND BY READING A SCREEN, not the file. `/refund-request` was captured for the Phase 2.5
 * matrix and its Arabic page title came back **"طلب طلب استرداد"** — *"request request refund"*.
 * The English is `Order Refund Request`, and the machine had translated "Order" to "طلب" and
 * "Refund Request" to "طلب استرداد" and concatenated them.
 *
 * A census over the whole file then found **8 of 3,509**, and every one was a defect rather than a
 * false positive:
 *
 *     Add Addon                     إضافة إضافة          "add add"
 *     Add New Addon                 إضافة إضافة جديدة     "add add new"
 *     Enter SVG icon code …         رمز رمز الرمز         "code code the-code"
 *     Order Refund Request          طلب طلب استرداد       "request request refund"
 *     OTP has been not matched.     طلب طلب التسجيل      wrong outright; never mentions a code
 *     View Demo                     عرض عرض              "view view"
 *     View Live Demo                عرض عرض مباشر         "view view live"
 *     … attribute option of attribute …   سمة سمة        "attribute attribute"
 *
 * A DOUBLED WORD IS NOT A STYLE QUESTION. Arabic does not repeat a word for emphasis the way this
 * pattern produces, and every instance here came from gluing two translations of overlapping
 * English phrases together — which is a specific, mechanical failure with a specific, mechanical
 * detection.
 *
 * WHY THIS IS A RATCHET AND NOT A ONE-OFF FIX. The eight are repaired; the ninth arrives the next
 * time somebody machine-translates a key. This test costs one pass over a 3,509-entry file.
 *
 * WHAT IT DELIBERATELY DOES NOT FLAG: a repeated placeholder (`:thing :thing`), which is the
 * caller's shape rather than a translation, and a single-character repeat, which in Arabic can be
 * a legitimate particle.
 */
class ArabicDoesNotStutterTest extends TestCase
{
    private function arabicStrings(): array
    {
        $path = dirname(__DIR__, 3) . '/resources/lang/ar.json';

        $this->assertFileExists($path, 'the Arabic translation file is gone');

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'resources/lang/ar.json is not valid JSON any more');

        return $decoded;
    }

    public function test_the_census_still_covers_a_real_file(): void
    {
        // THE PREMISE. A check that runs over an empty or tiny array passes and measures nothing.
        // The file held 3,509 keys when this was written; the floor is deliberately well below it
        // so that ordinary growth or pruning does not trip it, and a collapse does.
        $this->assertGreaterThan(
            3000,
            count($this->arabicStrings()),
            'resources/lang/ar.json has shrunk to a size this census cannot be measuring'
        );
    }

    public function test_no_arabic_string_repeats_a_word_immediately(): void
    {
        $offenders = [];

        foreach ($this->arabicStrings() as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            for ($i = 1; $i < count($words); $i++) {
                $previous = $words[$i - 1];

                if ($words[$i] !== $previous) {
                    continue;
                }

                // A repeated PLACEHOLDER is the caller's shape, not a translation.
                if (str_starts_with($previous, ':')) {
                    continue;
                }

                // A single character can be a legitimate Arabic particle.
                if (mb_strlen($previous) < 2) {
                    continue;
                }

                $offenders[] = "$key => $value";
                break;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An Arabic string says the same word twice in a row. Every instance of this found so "
            . "far came from gluing two translations of overlapping English phrases together -- "
            . "`Order Refund Request` became \"request request refund\" and shipped as a page "
            . "title.\n  "
            . implode("\n  ", $offenders)
        );
    }
}
