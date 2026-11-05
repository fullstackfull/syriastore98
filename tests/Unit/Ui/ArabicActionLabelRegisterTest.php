<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-19 — the Arabic action labels use one register, and "Create" uses the right word.
 * ================================================================================================
 *
 * MEASURED ACROSS resources/lang/ar.json: of the 23 common action-button labels present, twenty
 * used the VERBAL NOUN (المصدر) -- إضافة، تحديث، تحرير، إلغاء، إغلاق، تطبيق، تأكيد، طباعة، عرض --
 * which is both this file's own dominant convention and the Arabic UI norm. Five did not, and each
 * was visible on a merchant screen:
 *
 *   Create  خلق      the WRONG WORD, not merely the wrong register. خلق is creation ex nihilo,
 *                    the theological sense. It was the only key in the entire file with that
 *                    value, while its EIGHT compound siblings already read "إنشاء ..." -- so the
 *                    file disagreed with itself, and the bare key was the odd one out. Measured on
 *                    the running page: the Create button in every Arabic modal footer read خلق.
 *   Save    احفظ     imperative
 *   Delete  احذف     imperative
 *   Submit  أرسلها   imperative WITH an object pronoun -- "send it"
 *   Show    أظهر     imperative, and the wrong sense besides: every `__('Show')` in the merchant
 *                    views is the tooltip on a view-detail eye action, which is عرض (what `View`
 *                    already maps to), not إظهار (reveal something hidden).
 *
 * WHY THIS IS APPLYING AN EXISTING DECISION, NOT MAKING A NEW ONE. SavedViewInterfaceTest:227
 * already asserts the composed label حفظ العرض, so حفظ is the form this project ships; the eight
 * Create compounds already ship إنشاء. The five keys below were simply never brought into line.
 *
 * DELIBERATELY NOT TOUCHED, and recorded so the next reader does not think they were missed:
 *   - Export الصادرات is a KNOWN mistranslation with its own written decision. coupon/index
 *     explains why it is tolerated in a tooltip and must not become a visible word. Out of scope.
 *   - Reset إعادة التشغيل means restart-a-machine rather than reset-a-form, Back إلى الخلف reads
 *     oddly for a nav control, and Download/Search/Approve carry definite articles the rest of the
 *     file does not. Real, but each needs its own look at where it renders.
 */
class ArabicActionLabelRegisterTest extends TestCase
{
    /**
     * The five corrected keys. Asserted by VALUE, so a revert or a well-meaning re-translation
     * fails here with the reason attached rather than quietly shipping.
     */
    private const CORRECTED = [
        'Create' => 'إنشاء',
        'Save'   => 'حفظ',
        'Delete' => 'حذف',
        'Submit' => 'إرسال',
        'Show'   => 'عرض',

        // FOUND BY THIS FILE'S OWN khalq TEST, not by the census that preceded it: the census
        // compared whole VALUES and so saw only the bare `Create` key, while these two carry خلق
        // inside a phrase. Both render -- `Created At` is a table column header on
        // delivery-syria/index and the Newsletter and Menu DataTables, and `Pos create form.` is
        // a success string returned beside the rendered cart (PosController:260). The latter was
        // word-for-word machine output: بوس is a KISS, شكل is a SHAPE. It is the only value in
        // the file containing بوس; every other POS string keeps the acronym, so this one does too.
        'Created At'       => 'تاريخ الإنشاء',
        'Pos create form.' => 'نموذج إنشاء POS.',

        // AND A THIRD, found the same way. Three of the file's four "create(d) successfully"
        // toasts already read تم إنشاء ... بنجاح (Plan, Tag, Webhook setting); this one alone
        // followed its broken English word for word. Again the convention existed and this key
        // had simply never been brought into it.
        'Testimonial create successfully.' => 'تم إنشاء الشهادة بنجاح.',
    ];

    /** The siblings that already shipped the right word, and whose agreement is the argument. */
    private const CREATE_COMPOUNDS = [
        'Create Role', 'Create Coupon', 'Create Testimonial', 'Create Language',
        'Create New Plan', 'Create New Menu', 'Create Feature Block', 'Create New Webhook',
    ];

    public function test_each_corrected_label_carries_the_agreed_arabic(): void
    {
        $ar = $this->arabic();

        foreach (self::CORRECTED as $key => $expected) {
            $this->assertArrayHasKey($key, $ar, $key . ' is gone from ar.json.');
            $this->assertSame($expected, trim($ar[$key]),
                sprintf('ar.json["%s"] is "%s", not "%s". See this file\'s docblock for why.',
                    $key, trim($ar[$key]), $expected));
        }
    }

    /**
     * THE DEFECT, named so it cannot come back by a different route: خلق is the theological sense
     * of "create" and has no place on a button.
     */
    public function test_no_label_in_the_file_uses_khalq_for_create(): void
    {
        $offenders = [];

        foreach ($this->arabic() as $key => $value) {
            if (preg_match('/(^|\s)خلق(\s|$)/u', (string) $value)) {
                $offenders[] = $key . ' => ' . $value;
            }
        }

        $this->assertSame([], $offenders,
            "خلق is creation ex nihilo, not the UI sense of \"create\" -- use إنشاء:\n  "
            . implode("\n  ", $offenders));
    }

    /** The agreement that makes the correction a correction rather than a preference. */
    public function test_the_compound_create_labels_still_agree(): void
    {
        $ar = $this->arabic();
        $missing = [];
        $disagreeing = [];

        foreach (self::CREATE_COMPOUNDS as $key) {
            if (! array_key_exists($key, $ar)) {
                $missing[] = $key;
                continue;
            }
            if (! str_starts_with(trim($ar[$key]), 'إنشاء')) {
                $disagreeing[] = $key . ' => ' . $ar[$key];
            }
        }

        $this->assertSame([], $missing, 'These compound keys are gone: ' . implode(', ', $missing));
        $this->assertSame([], $disagreeing,
            "These no longer start with إنشاء, so the bare Create key no longer has siblings to "
            . "agree with and this slice's argument has lapsed:\n  " . implode("\n  ", $disagreeing));
    }

    /**
     * None of the five may be an ORPHAN. The project's ratchet holds ar-only keys at a fixed
     * ceiling, so a key without an English sibling would redden a different test far from here.
     */
    public function test_every_corrected_key_still_has_an_english_sibling(): void
    {
        $en = $this->english();

        foreach (array_keys(self::CORRECTED) as $key) {
            $this->assertArrayHasKey($key, $en,
                $key . ' has no en.json sibling, which pushes the orphan ratchet up.');
        }
    }

    private function arabic(): array
    {
        return $this->json('resources/lang/ar.json');
    }

    private function english(): array
    {
        return $this->json('resources/lang/en.json');
    }

    private function json(string $relative): array
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, $relative . ' is not valid JSON.');
        $this->assertNotEmpty($decoded, $relative . ' parsed to nothing.');

        return $decoded;
    }
}
