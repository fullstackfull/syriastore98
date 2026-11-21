<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-80, round 4 -- the OTP settings page writes the answer of its "send a test" button as TEXT.
 * ===================================================================================================
 *
 * `OtpIntegrationController::test` answers `{success, message}`, and its message can be the remote gateway's own body or an exception's text (the handler's docblock says so). The page
 * concatenated it into `'<div class="alert ...">' + message + '</div>'` and wrote that with `.html()`. The gateway's address is a setting somebody else may have saved, so the text is not
 * the reader's own.
 *
 * A SOURCE PIN, and it says so: nothing in the suite runs a page's JavaScript. It pins the two shapes -- the message goes through `.text()`, and no `.html(` in this view takes anything but
 * a constant string. It does not see a message written by a script outside this view.
 */
final class OtpTestResultIsWrittenAsTextTest extends TestCase
{
    private static function view(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/setting/otp_setting.blade.php');

        return preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;
    }

    public function test_both_messages_are_written_with_text(): void
    {
        $view = self::view();

        $this->assertStringContainsString(".text(response.message == null ? '' : String(response.message))", $view, 'the success branch must write the answer\'s message as text');
        $this->assertStringContainsString(".text(message == null ? '' : String(message))", $view, 'the error branch must write the error body\'s message as text');
    }

    public function test_every_html_call_in_the_view_takes_a_constant(): void
    {
        $view = self::view();
        $this->assertSame(1, preg_match_all('/\.html\(\s*((?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|\s|\+)*)\)/s', $view, $constant), 'premise: the view still has its one constant .html() call (the "enter a phone number" notice)');
        $this->assertSame(1, substr_count($view, '.html('), 'a second .html( call appeared in the OTP settings view: write a server or gateway message with .text(), or build nodes');
        $this->assertStringNotContainsString('+', preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/', '', $constant[1][0]) ?? '+', 'the one .html( call concatenates something into its markup');
    }

    public function test_the_pin_sees_the_shape_it_exists_to_forbid(): void
    {
        $old = "\$('#otp_test_result').html(\n'<div class=\"alert alert-danger\">' +\nmessage +\n'</div>'\n);";

        $this->assertSame(0, preg_match('/\.html\(\s*((?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|\s|\+)*)\)/s', $old), 'premise: the constant-only pattern must NOT match the shape that was found (a variable between the quotes)');
    }
}
