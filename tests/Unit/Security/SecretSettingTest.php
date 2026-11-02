<?php

namespace Tests\Unit\Security;

use App\Support\SecretSetting;
use Tests\TestCase;

/**
 * F117 — the two halves of "never shown, never silently erased".
 *
 * The display half and the persistence half are tested together because using one without the
 * other is the bug: masking the box over an unconditional `$post[$k] = $request->$k` ERASES the
 * credential on the next save of anything else on the same panel, and the merchant's integration
 * then stops working for a reason nothing on screen explains.
 *
 * NO REAL SECRET APPEARS IN THIS FILE OR IN ANY FAILURE MESSAGE. The fixtures are obvious
 * inventions, and the assertions below compare values without interpolating a stored credential
 * into an assertion message — a failing test must not print one into CI output.
 */
class SecretSettingTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: string, 2: string}> */
    public static function resolveCases(): array
    {
        return [
            'blank keeps the stored value'        => ['',        'kept-value', 'kept-value'],
            'null keeps the stored value'         => [null,      'kept-value', 'kept-value'],
            'whitespace keeps the stored value'   => ['   ',     'kept-value', 'kept-value'],
            'a typed value replaces'              => ['new-one', 'kept-value', 'new-one'],
            'a typed value is trimmed'            => ['  new-one  ', 'kept-value', 'new-one'],
            'blank with nothing stored is blank'  => ['',        '',           ''],
            'typed with nothing stored is stored' => ['first',   '',           'first'],
            'a non-string is treated as untyped'  => [['x'],     'kept-value', 'kept-value'],
        ];
    }

    /**
     * @dataProvider resolveCases
     */
    public function test_resolve_keeps_the_stored_value_unless_something_was_typed($submitted, string $stored, string $expected): void
    {
        $this->assertSame($expected, SecretSetting::resolve($submitted, $stored));
    }

    /**
     * WHITESPACE IS "UNTYPED", and that is a decision rather than an accident. A credential is
     * never whitespace, so treating `'   '` as a real value would store garbage the integration
     * then fails on — and it would do so while LOOKING like the merchant had set something.
     */
    public function test_a_whitespace_only_submission_does_not_overwrite(): void
    {
        $this->assertSame('kept-value', SecretSetting::resolve("\t\n ", 'kept-value'));
    }

    public function test_display_never_returns_the_stored_value(): void
    {
        $this->assertSame('', SecretSetting::display());
    }

    public function test_is_set_reports_existence_only(): void
    {
        $this->assertTrue(SecretSetting::isSet('anything'));
        $this->assertFalse(SecretSetting::isSet(''));
        $this->assertFalse(SecretSetting::isSet('   '));
        $this->assertFalse(SecretSetting::isSet(null));
    }

    /**
     * THE PAIR, driven as a save would drive it: render, save without touching the box, render
     * again. The credential must survive and must never appear in what the page emits.
     */
    public function test_a_save_that_does_not_touch_the_box_preserves_the_credential(): void
    {
        $stored = 'sk-INVENTED-not-a-real-key';

        // What the page would put in the input on the first render.
        $this->assertNotSame($stored, SecretSetting::display());

        // The merchant changes an unrelated field; the secret box posts empty.
        $afterSave = SecretSetting::resolve('', $stored);
        $this->assertSame($stored, $afterSave);

        // And the second render still shows nothing.
        $this->assertSame('', SecretSetting::display());
    }
}
