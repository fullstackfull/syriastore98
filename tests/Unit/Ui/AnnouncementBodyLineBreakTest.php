<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHASE 06 `SUPPORT-002` — A VIEW'S COMMENT CLAIMED A CSS RULE THAT DID NOT EXIST.
 * =================================================================================================
 *
 * `resources/views/announcements/index.blade.php` explains, correctly, why it does **not** use
 * `nl2br()` on an announcement's body: the `<br>` would be escaped and the merchant would read the
 * tag, and emitting it raw would mean printing operator text through `{!! !!}` — which is the one
 * thing that view must not do, because `CTRL-003`'s write routes drop `xss` deliberately and
 * Blade's escaping is therefore the only control on that text.
 *
 * The comment then said `white-space: pre-line` on `.ss-announcement__body` gives the same result
 * from CSS. **It did not: the class had zero rules in `public/css/store-syria-ds.css`.** Found by
 * checking every `.ss-` class the new screens use against the stylesheet, not by any test — so this
 * file exists to make the claim and the rule move together. An operator who types a three-line
 * maintenance notice would have had it rendered as one paragraph, and nothing anywhere would have
 * said so.
 *
 * -------------------------------------------------------------------------------------------------
 * THE COMMENTS ARE STRIPPED FROM BOTH FILES BEFORE ANYTHING IS SEARCHED, AND THAT IS THE POINT.
 *
 * CLAUDE.md records **eight** occasions on which a census was satisfied — or broken — by prose
 * ABOUT the thing it measures, in three syntaxes, one of them a CSS comment recording that a draft
 * had used `position: relative`. The rule added for this slice carries a comment that NAMES
 * `.ss-announcement__body` twice while explaining the gap, so a census over the raw stylesheet
 * would pass with the rule deleted. `/* * /` is stripped from the CSS and `{{-- --}}` from the
 * Blade, and each strip is asserted to have removed something — a stripper that quietly stopped
 * working makes every assertion here a free pass, which reads exactly like a clean run.
 *
 * THE SEARCH IS SCOPED TO THE BLOCK, not to the 7,000-line stylesheet. CLAUDE.md records a mutation
 * dropping one component's 44px target surviving because another component had the same rule.
 */
class AnnouncementBodyLineBreakTest extends TestCase
{
    private const CSS = 'public/css/store-syria-ds.css';

    private const VIEW = 'resources/views/announcements/index.blade.php';

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function cssWithoutComments(): string
    {
        $raw = (string) file_get_contents($this->root() . '/' . self::CSS);

        $this->assertNotSame('', $raw);

        $stripped = (string) preg_replace('#/\*.*?\*/#s', '', $raw);

        $this->assertLessThan(
            strlen($raw),
            strlen($stripped),
            'The CSS comment strip removed nothing, so this census is reading prose as if it were '
            . 'a rule — which is how a deleted rule reads as present.'
        );

        return $stripped;
    }

    private function viewWithoutComments(): string
    {
        $raw = (string) file_get_contents($this->root() . '/' . self::VIEW);

        $this->assertNotSame('', $raw);

        $stripped = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);

        $this->assertLessThan(strlen($raw), strlen($stripped), 'The Blade comment strip removed nothing.');

        return $stripped;
    }

    /**
     * THE PREMISE THAT MAKES THE REST MEAN ANYTHING: the class really is named in both files'
     * comments, so a census that did not strip them would pass against a deleted rule.
     */
    #[Test]
    public function premise_the_class_is_named_in_a_comment_in_both_files(): void
    {
        $css = (string) file_get_contents($this->root() . '/' . self::CSS);

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($css, 'ss-announcement__body'),
            'The stylesheet should name this class in its comment as well as in its selector; if '
            . 'that comment has been rewritten, this premise can be re-pointed, but do not delete '
            . 'it — it is what proves the strip below is load-bearing.'
        );

        $this->assertStringNotContainsString(
            'ss-announcement__body',
            preg_replace('#\.ss-announcement__body\s*\{[^}]*\}#s', '', $this->cssWithoutComments()) ?? '',
            'With the comments stripped AND the rule removed, the class name should be gone from '
            . 'the stylesheet entirely. It is not, so there is a second mention this census has '
            . 'not accounted for.'
        );
    }

    #[Test]
    public function the_body_class_really_carries_the_line_break_rule(): void
    {
        $css = $this->cssWithoutComments();

        $this->assertMatchesRegularExpression(
            '/\.ss-announcement__body\s*\{[^}]*white-space\s*:\s*pre-line\s*;/s',
            $css,
            'The announcement body no longer keeps its line breaks. The view refuses `nl2br()` on '
            . 'purpose — the `<br>` would be escaped and printing operator text through `{!! !!}` '
            . 'is the one thing it must not do — so this CSS rule IS the behaviour. Without it an '
            . 'operator\'s three-line maintenance notice renders as one paragraph.'
        );

        /* `pre-line` AND NOT `pre-wrap`: runs of spaces and tabs must collapse, so an operator's
           stray indentation does not reach the merchant. */
        $this->assertDoesNotMatchRegularExpression(
            '/\.ss-announcement__body\s*\{[^}]*white-space\s*:\s*pre-wrap/s',
            $css
        );
    }

    #[Test]
    public function the_view_prints_the_body_in_that_class_and_never_raw(): void
    {
        $view = $this->viewWithoutComments();

        $this->assertStringContainsString(
            'class="ss-announcement__body mb-2">{{ $announcement[\'body\'] }}',
            $view,
            'The announcement body is no longer printed inside `.ss-announcement__body` through '
            . 'Blade\'s escaping `{{ }}`. Both halves matter: the class is where the line-break '
            . 'rule applies, and `{{ }}` is the only control on operator prose on this screen.'
        );

        $this->assertStringNotContainsString(
            '{!!',
            $view,
            'The announcement screen prints something raw. `CTRL-003`\'s write routes drop `xss` '
            . 'deliberately, so Blade\'s escaping is the control here and not a second line of '
            . 'defence.'
        );

        $this->assertStringNotContainsString(
            'nl2br',
            $view,
            '`nl2br()` emits a `<br>` that Blade escapes, so the merchant reads the tag; emitting '
            . 'it raw is the `{!! !!}` this screen must not use. The CSS rule is the fix.'
        );
    }
}
