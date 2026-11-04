<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 `staff-card-a11y` — the staff card on /users had three accessible-name / structure defects.
 * =================================================================================================
 *
 * MEASURED IN REAL CHROMIUM, not inferred from markup. The baseline below is Chromium's own
 * accessible-name computation, read off `Accessibility.getFullAXTree` over CDP against the live
 * merchant server on 127.0.0.1:8899, signed in as merchant@example.test, `/users` answering 200
 * with three staff cards:
 *
 *     [button] name="U+E89A" len=1  from=contents      x3   <- the kebab, once per card
 *     [link]   name=""       len=0  from=              x3   <- the avatar link
 *     DOM headings in .dash-content: H2 "المستخدمون", then (H6 "Staff", H4 "<name>") x3,
 *                                    then H6 "إنشاء مستخدم جديد"
 *
 * (a) THE UNNAMED KEBAB — AND IT WAS NOT EMPTY, WHICH IS THE WHOLE DIFFICULTY. The actions button
 *     carried no `aria-label`, no `title` and no text node; its only child was
 *     `<i class="feather icon-more-vertical">`, and `public/assets/fonts/feather.css:278` gives
 *     `.icon-more-vertical:before` the content `"\e89a"`. CSS generated content is step 2F of the
 *     accessible-name computation, so the name was the single Private-Use codepoint U+E89A —
 *     silence on VoiceOver, garbage on a braille display. A test asserting "the name is non-empty"
 *     would have PASSED while the control was broken, which is why every rule below compares the
 *     name against an EXPECTED STRING. That kebab is the sole route to Edit, Reset Password, Delete
 *     and Login-enable, so it is the most consequential control on the screen a merchant manages
 *     staff from.
 *
 *     THE SAME LEAK WAS IN ALL FOUR MENU ITEMS, measured on the open dropdown:
 *     "U+EA98 تحرير", "U+EAC7 إعادة تعيين كلمة المرور", "U+ECDD إيقاف تسجيل الدخول", "U+EB41 احذف".
 *     Those four `<i class="ti ti-*">` carried no `aria-hidden`, so every item in the menu was
 *     announced with a junk codepoint in front of its label. Same file, same mechanism, one
 *     attribute each.
 *
 * (b) THE AVATAR IMAGE HAD NO `alt` ATTRIBUTE AT ALL — not `alt=""`. It is the only content of the
 *     `<a target="_blank">` that wraps it, so the LINK had no accessible name either: Chromium
 *     computed `name="" len=0 from=` for all three, i.e. no name source at all. A link with no
 *     accessible name fails SC 4.1.2 and SC 2.4.4. (CORRECTION to the finding as written: it
 *     claimed the absence makes assistive technology "fall back to announcing the file path".
 *     Chromium's AX name is EMPTY, not the path — the path fallback is screen-reader behaviour
 *     layered on top, not the computed name. The defect stands; the mechanism quoted did not.)
 *
 *     `alt=""` MUST NOT SATISFY THIS TEST. An empty alt is the correct markup for decoration, and
 *     it would leave the link nameless — a strictly worse outcome than the status quo, because it
 *     reads as deliberate. `test_an_empty_alt_does_not_satisfy_the_image_rule` pins that.
 *
 * (c) THE HEADING OUTLINE, AND IT IS WORSE THAN THE FINDING SAID. `layouts/app.blade.php:517`
 *     (NOT :498, which is the skip link — the line numbers in that finding were stale) emits the
 *     page heading as `<h2 class="h3 mb-0 ss-scope">@yield('page-title')</h2>`. So the measured
 *     outline was h2 then (h6, h4) per card: the first step is a THREE-level skip (h2 -> h6), not
 *     one, and the second is a reversal (h6 -> h4). The staff member's NAME is the card's subject
 *     and is now its heading, at h3, directly under the page's h2. The type badge is a badge, not
 *     a section heading, so its `<h6>` wrapper is gone — and the "Create New User" card's own `h6`
 *     moved to h3 with it, because leaving it behind would have left the outline h2, h3, h3, h3,
 *     h6 and the skip merely shortened.
 *
 * WHY `aria-label` HERE AND A VISUALLY-HIDDEN SPAN ELSEWHERE. WCAG 2.5.3 Label in Name is violated
 * by an `aria-label` that does not contain the control's visible text, so a sibling repair in
 * `partision/header.blade.php` had to use an additive `.visually-hidden` span. The kebab is
 * genuinely icon-only — no text node, no visible label — so there is no visible text for an
 * `aria-label` to contradict, and `aria-label` is the right instrument.
 *
 * CORRECTION TO THE FINDING, SECOND ONE, and it would have caused a NEW defect if taken at face
 * value: the finding stated that P2-05-5 "already gave the two TOOLBAR controls both aria-label and
 * aria-hidden on their icons". Measured, that is true of only ONE of them. The List View control
 * (:20-25) is icon-only and carries `aria-label`. The Add User control (:27-33) carries
 * `aria-hidden` on its icon and a VISIBLE `<span>Add User</span>` — and NO `aria-label`, correctly,
 * because adding one there is exactly the 2.5.3 failure the constraint warns about.
 * `test_the_toolbar_controls_keep_the_names_they_already_had` therefore asserts the two controls
 * DIFFERENTLY rather than asserting a uniform rule neither of them follows.
 */
class StaffCardAccessibleNamesTest extends TestCase
{
    private const VIEW = 'resources/views/users/index.blade.php';

    private const LAYOUT = 'resources/views/layouts/app.blade.php';

    private const KEBAB_KEY = 'Actions for :name';

    private const PHOTO_KEY = 'Profile photo of :name';

    /** Stand-ins for the loop's row data; the Arabic fixture really is on the dev server. */
    private const FIXTURE_NAME = 'Rami Haddad';

    private const FIXTURE_TYPE = 'staff';

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Blade comments stripped, and only Blade comments — the same limitation every other
     * source-reading ratchet in this suite carries. This file's own prose quotes `aria-label`,
     * `alt=` and `<h6` repeatedly, so without this an assertion could be satisfied by the
     * commentary describing the defect.
     */
    private function source(string $relative): string
    {
        $path = $this->root() . '/' . $relative;
        $this->assertFileExists($path);

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
    }

    /** The `@foreach ($users as $user)` body, and nothing outside it. */
    private function card(): string
    {
        $this->assertSame(1, preg_match(
            '/@foreach\s*\(\s*\$users\s+as\s+\$user\s*\)(.*?)@endforeach/s',
            $this->source(self::VIEW),
            $m
        ), 'premise: the staff-card loop was not found in ' . self::VIEW . ', so every rule below '
            . 'this point is vacuous — the view was restructured and this row needs re-measuring');

        return $m[1];
    }

    /** Everything inside `@section('content')`, which is where the card grid lives. */
    private function contentSection(): string
    {
        $this->assertSame(1, preg_match(
            "/@section\('content'\)(.*?)@endsection/s",
            $this->source(self::VIEW),
            $m
        ), 'premise: @section(\'content\') was not found in ' . self::VIEW);

        return $m[1];
    }

    /** Everything inside `@section('action-button')`: the page toolbar. */
    private function toolbar(): string
    {
        $this->assertSame(1, preg_match(
            "/@section\('action-button'\)(.*?)@endsection/s",
            $this->source(self::VIEW),
            $m
        ), 'premise: @section(\'action-button\') was not found in ' . self::VIEW);

        return $m[1];
    }

    // ---------------------------------------------------------------------------------------
    // The accessible-name model. Deliberately NOT a grep for "aria-label".
    // ---------------------------------------------------------------------------------------

    /**
     * The codepoint an icon class injects through `:before`, read from the font stylesheet itself
     * rather than hard-coded, so a font upgrade re-measures instead of silently passing.
     */
    private function glyph(string $iconClass): string
    {
        $sheets = [
            'public/assets/fonts/feather.css',
            'public/assets/fonts/tabler-icons.min.css',
        ];

        foreach ($sheets as $sheet) {
            $css = (string) file_get_contents($this->root() . '/' . $sheet);
            $pattern = '/\.' . preg_quote($iconClass, '/') . ':before\s*\{\s*content:\s*"\\\\([0-9a-f]{4})"/i';

            if (preg_match($pattern, $css, $m) === 1) {
                return mb_chr((int) hexdec($m[1]), 'UTF-8');
            }
        }

        $this->fail("premise: no stylesheet gives .{$iconClass}:before any generated content, so the "
            . 'glyph-leak half of this row no longer has a mechanism — re-measure before trusting '
            . 'this file');
    }

    /** Resolve the Blade echoes this screen actually uses, against the REAL en.json. */
    private function render(string $text): string
    {
        $en = json_decode((string) file_get_contents($this->root() . '/resources/lang/en.json'), true);

        $text = (string) preg_replace_callback(
            '/\{\{\s*__\(\s*\'([^\']+)\'\s*(?:,\s*\[[^\]]*\]\s*)?\)\s*\}\}/',
            function (array $m) use ($en): string {
                $value = $en[$m[1]] ?? $m[1];

                return str_replace([':name', ':thing'], [self::FIXTURE_NAME, 'User'], (string) $value);
            },
            $text
        );

        $text = str_replace('{{ $user->name }}', self::FIXTURE_NAME, $text);
        $text = (string) preg_replace('/\{\{\s*ucfirst\(\$user->type\)\s*\}\}/', ucfirst(self::FIXTURE_TYPE), $text);

        /*
         * Echoes inside an attribute that CANNOT contribute to a name — `href`, `src`, every
         * `data-*` — are neutralised rather than resolved. The alternative was to teach this
         * method `route()`, `asset()`, `Crypt::encrypt()` and a ternary over `$user->profile_image`,
         * none of which a screen reader ever reads. The naming attributes below are deliberately
         * NOT in this list, so an unresolved echo in one of them still trips the guard.
         */
        $naming = ['aria-label', 'aria-labelledby', 'aria-hidden', 'alt', 'title', 'class'];

        return (string) preg_replace_callback(
            '/([a-zA-Z@:-]+)="([^"]*)"/',
            function (array $m) use ($naming): string {
                if (in_array(strtolower($m[1]), $naming, true)) {
                    return $m[0];
                }

                return $m[1] . '="' . preg_replace('/\{\{.*?\}\}/s', '#', $m[2]) . '"';
            },
            $text
        );
    }

    /**
     * The accessible name Chromium computes for one element, modelled from its attributes and
     * subtree in the order the AccName spec applies them.
     *
     *  - 2C  `aria-label` wins outright.
     *  - 2A  an `aria-hidden="true"` subtree contributes nothing.
     *  - 2F  CSS generated content from an icon class DOES contribute. This is the step every
     *        "is there an aria-label" grep misses, and the one this screen's defect lived in.
     *  - 2D  an `<img>` contributes its `alt`. No `alt` attribute at all contributes NOTHING,
     *        which is what Chromium measured (`name="" len=0 from=`).
     */
    private function accessibleName(string $element, bool $dropAriaHidden = false): string
    {
        $element = $this->render($element);

        if ($dropAriaHidden) {
            $element = (string) preg_replace('/\s+aria-hidden="true"/', '', $element);
        }

        $this->assertSame(0, preg_match('/\{\{|\{!!/', $element), sprintf(
            'the name model left an unresolved Blade echo in %s, so the computed name below is '
            . 'not what the browser would see — teach render() the new construct',
            var_export(mb_substr($element, 0, 160), true)));

        // 2C on the element itself.
        if (preg_match('/^<[a-z]+\b[^>]*\baria-label\s*=\s*"([^"]*)"/i', $element, $m) === 1) {
            return trim((string) preg_replace('/\s+/u', ' ', $m[1]));
        }

        return trim((string) preg_replace('/\s+/u', ' ', $this->nameFromContents($element)));
    }

    /** Name from contents, recursing into the subtree. */
    private function nameFromContents(string $element): string
    {
        // Strip the element's own opening and closing tag, keeping the subtree.
        $inner = (string) preg_replace('/^<([a-z]+)\b[^>]*>(.*)<\/\1>$/is', '$2', $element);

        $name = '';
        $offset = 0;
        $length = strlen($inner);

        while ($offset < $length) {
            $next = strpos($inner, '<', $offset);

            if ($next === false) {
                $name .= ' ' . substr($inner, $offset) . ' ';
                break;
            }

            $name .= ' ' . substr($inner, $offset, $next - $offset) . ' ';

            if (preg_match('/\G<([a-z]+)\b([^>]*?)(\/?)>/i', $inner, $tag, 0, $next) !== 1) {
                $offset = $next + 1;

                continue;
            }

            [$raw, $tagName, $attrs, $selfClosing] = [$tag[0], strtolower($tag[1]), $tag[2], $tag[3] === '/'];
            $void = $selfClosing || in_array($tagName, ['img', 'br', 'input', 'hr'], true);

            $child = $void
                ? $raw
                : $this->elementAt($inner, $next, $tagName);

            $offset = $next + strlen($child);

            // 2A.
            if (preg_match('/aria-hidden\s*=\s*"true"/', $attrs) === 1) {
                continue;
            }

            // 2D — an <img>'s alt. The ABSENCE of the attribute contributes nothing at all.
            if ($tagName === 'img') {
                if (preg_match('/\balt\s*=\s*"([^"]*)"/', $attrs, $alt) === 1) {
                    $name .= ' ' . $alt[1] . ' ';
                }

                continue;
            }

            // 2C on a descendant.
            if (preg_match('/\baria-label\s*=\s*"([^"]*)"/', $attrs, $label) === 1) {
                $name .= ' ' . $label[1] . ' ';

                continue;
            }

            // 2F — generated content from an icon class.
            if (preg_match('/class\s*=\s*"([^"]*)"/', $attrs, $classAttr) === 1) {
                foreach (preg_split('/\s+/', trim($classAttr[1])) as $class) {
                    if (preg_match('/^(icon-|ti-)/', $class) === 1) {
                        $name .= $this->glyph($class);
                    }
                }
            }

            $name .= ' ' . $this->nameFromContents($child) . ' ';
        }

        return $name;
    }

    /** The full outer HTML of the element whose opening tag starts at `$start`. */
    private function elementAt(string $html, int $start, string $tagName): string
    {
        $depth = 0;
        $offset = $start;
        $pattern = '/<(\/?)' . preg_quote($tagName, '/') . '\b[^>]*?(\/?)>/i';

        while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $at = $m[0][1];
            $len = strlen($m[0][0]);

            if ($m[1][0] === '/') {
                $depth--;

                if ($depth === 0) {
                    return substr($html, $start, $at + $len - $start);
                }
            } elseif ($m[2][0] !== '/') {
                $depth++;
            }

            $offset = $at + $len;
        }

        return substr($html, $start);
    }

    /** The kebab `<button>` inside `.card-option`, as outer HTML. */
    private function kebab(): string
    {
        $card = $this->card();

        $this->assertSame(1, preg_match('/<div class="btn-group card-option">/', $card),
            'premise: the .card-option group is gone from the staff card');

        $at = strpos($card, '<button');
        $this->assertNotFalse($at, 'premise: the staff card no longer contains a <button>, so the '
            . 'kebab has been restructured and this row needs re-measuring');

        return $this->elementAt($card, $at, 'button');
    }

    /** The avatar `<a>` and the `<img>` it wraps, as outer HTML. */
    private function avatarLink(): string
    {
        $card = $this->card();

        $this->assertSame(1, preg_match('/<div class="img-fluid rounded-circle card-avatar[^"]*">\s*(<a\b)/', $card, $m, PREG_OFFSET_CAPTURE),
            'premise: the avatar wrapper is gone from the staff card');

        return $this->elementAt($card, (int) $m[1][1], 'a');
    }

    /**
     * The heading levels a region emits, in document order, with the text each one carries.
     *
     * @return list<array{level:int, text:string}>
     */
    private function headings(string $region): array
    {
        preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/s', $region, $m, PREG_SET_ORDER);

        return array_map(fn (array $h): array => [
            'level' => (int) $h[1],
            'text' => trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->render($h[2])))),
        ], $m);
    }

    // ---------------------------------------------------------------------------------------
    // PREMISES AND NEGATIVE CONTROLS — these come first because without them the rules below
    // can pass for the wrong reason, which is the failure mode this project keeps hitting.
    // ---------------------------------------------------------------------------------------

    public function test_the_glyph_leak_is_still_a_real_mechanism(): void
    {
        /*
         * The codepoints this row was measured against, read back out of the font sheets. If a
         * font upgrade moves them, this fails and the measurements above get re-taken rather
         * than silently becoming folklore.
         */
        $measured = [
            'icon-more-vertical' => 'e89a',
            'ti-edit' => 'ea98',
            'ti-key' => 'eac7',
            'ti-road-sign' => 'ecdd',
            'ti-trash' => 'eb41',
        ];

        foreach ($measured as $class => $codepoint) {
            $this->assertSame($codepoint, strtolower(dechex(mb_ord($this->glyph($class), 'UTF-8'))),
                ".{$class} no longer injects U+" . strtoupper($codepoint) . ' — re-measure this row');
        }
    }

    public function test_the_name_model_detects_an_unnamed_icon_only_button(): void
    {
        /*
         * THE CONTROL THAT PROVES THIS TEST CAN SEE THE DEFECT, fed the PRE-REPAIR markup verbatim.
         * The model must compute exactly U+E89A for it: not empty, which is why an
         * assertNotEmpty-style rule would have shipped this screen as passing.
         */
        $before = '<button type="button" class="btn dropdown-toggle" data-bs-toggle="dropdown"'
            . "\n" . '    aria-haspopup="true" aria-expanded="false">' . "\n"
            . '    <i class="feather icon-more-vertical"></i>' . "\n"
            . '</button>';

        $name = $this->accessibleName($before);

        $this->assertSame($this->glyph('icon-more-vertical'), $name,
            'the name model no longer computes the Private-Use glyph for the pre-repair kebab, so '
            . 'it has gone blind to the defect this file exists to catch and every rule below is '
            . 'decoration');
        $this->assertSame(1, mb_strlen($name),
            'the pre-repair kebab name was measured in Chromium as exactly one character (len=1); '
            . 'the model now disagrees with the browser');
        $this->assertNotSame('', $name,
            'a non-empty assertion PASSES for the broken control — that is the trap this test '
            . 'documents, and it must stay demonstrable');
    }

    public function test_the_name_model_detects_a_stripped_aria_label(): void
    {
        /*
         * Take the REPAIRED kebab and remove its aria-label: the name must collapse back to the
         * glyph. This is the revert half — if the model reports a good name for a control with no
         * label, the rule below passes against a reverted fix.
         */
        $repaired = $this->kebab();
        $stripped = (string) preg_replace('/\s+aria-label="[^"]*"/', '', $repaired, 1);

        $this->assertNotSame($repaired, $stripped,
            'the repaired kebab carries no aria-label to strip');

        /*
         * EMPTY, not the glyph — and the first draft of this assertion got that wrong, which is
         * worth leaving written down. The repair has TWO halves: the `aria-label` and the
         * `aria-hidden` on the `<i>`. Remove only the label and step 2A still suppresses the
         * glyph, so the name goes to nothing. The glyph comes back only when BOTH are gone, which
         * is what `test_the_kebab_glyph_is_hidden_so_it_is_not_a_second_name_source` drives.
         */
        $this->assertSame('', $this->accessibleName($stripped),
            'with its aria-label removed the kebab should have no accessible name left at all; the '
            . 'model is finding a name from somewhere else and so cannot see a reverted repair');
    }

    public function test_an_empty_alt_does_not_satisfy_the_image_rule(): void
    {
        /*
         * `alt=""` is CORRECT markup for a decorative image and WRONG here: the <img> is the only
         * content of the link, so an empty alt leaves the link nameless while looking deliberate —
         * worse than the status quo. Both the no-alt and the empty-alt shapes must model as
         * nameless, or the rule below could be satisfied by the wrong repair.
         */
        $noAlt = '<a href="/x" target="_blank"><img class="img-user" src="/x.png"></a>';
        $emptyAlt = '<a href="/x" target="_blank"><img class="img-user" src="/x.png" alt=""></a>';

        $this->assertSame('', $this->accessibleName($noAlt),
            'the model gives a name to a link whose only child is an <img> with no alt — Chromium '
            . 'measured name="" len=0 from= for exactly that markup');
        $this->assertSame('', $this->accessibleName($emptyAlt),
            'the model gives a name to a link whose only child is an alt="" image, so it cannot '
            . 'tell the correct repair from the plausible-looking wrong one');
    }

    public function test_the_page_heading_level_the_outline_hangs_off_is_still_h2(): void
    {
        /*
         * PREMISE for the outline rule. The finding named layouts/app.blade.php:498; the real line
         * is 517 and :498 is the skip link. Asserted by CONTENT, not by line number, because the
         * line number is exactly what went stale.
         */
        $layout = $this->source(self::LAYOUT);

        $this->assertSame(1, preg_match('/<h2 class="h3 mb-0 ss-scope">@yield\(\'page-title\'\)<\/h2>/', $layout),
            'the shared layout no longer emits the page heading as an <h2 class="h3">, so the level '
            . 'the staff cards must hang off has changed — re-measure the outline before trusting '
            . 'the h3 below');
    }

    public function test_the_toolbar_controls_keep_the_names_they_already_had(): void
    {
        /*
         * NEGATIVE CONTROL for the whole file: P2-05-5's repair of the two toolbar controls must
         * survive this change, and the two are asserted DIFFERENTLY because they are different
         * shapes — the finding's claim that both carry aria-label is wrong, and following it would
         * have put an aria-label on a control with visible text (WCAG 2.5.3).
         */
        $toolbar = $this->toolbar();

        $at = strpos($toolbar, '<a');
        $this->assertNotFalse($at, 'premise: the toolbar has no anchors left');
        $listView = $this->elementAt($toolbar, $at, 'a');

        // ICON-ONLY: aria-label is the instrument, and removing it must be visible here.
        $this->assertSame('List View', $this->accessibleName($listView),
            'the List View toolbar control has lost the accessible name P2-05-5 gave it');

        /*
         * MEASURED, and not what a first draft of this assertion expected: strip List View's
         * aria-label and the name goes EMPTY, not to the ti-list glyph — because P2-05-5 also put
         * `aria-hidden="true"` on that icon, and step 2A runs before step 2F. The kebab's name
         * collapsed to the glyph precisely because its `<i>` had no aria-hidden. Two controls, two
         * different broken names, and a uniform assertion would have been wrong about one of them.
         */
        $withoutLabel = $this->accessibleName(
            (string) preg_replace('/\s+aria-label="[^"]*"/', '', $listView, 1)
        );
        $this->assertSame('', $withoutLabel,
            'stripping List View\'s aria-label no longer empties its computed name, so this '
            . 'control is not actually being checked');

        // HAS VISIBLE TEXT: it must be named BY that text and must NOT carry an aria-label.
        $addAt = strpos($toolbar, '<a', $at + 1);
        $this->assertNotFalse($addAt, 'premise: the Add User toolbar control is gone');
        $addUser = $this->elementAt($toolbar, $addAt, 'a');

        $this->assertStringContainsString('<span>{{ __(\'Add User\') }}</span>', $addUser,
            'the Add User control has lost its visible text label');
        $this->assertSame(0, preg_match('/aria-label/', $addUser),
            'the Add User control gained an aria-label, which OVERRIDES its visible "Add User" '
            . 'text — a WCAG 2.5.3 Label-in-Name failure traded for nothing, and precisely the '
            . 'mistake the finding\'s own description of this file invites');
        $this->assertSame('Add User', $this->accessibleName($addUser),
            'the Add User control is no longer named by its visible text');
    }

    // ---------------------------------------------------------------------------------------
    // (a) THE KEBAB
    // ---------------------------------------------------------------------------------------

    public function test_the_actions_kebab_is_named_and_names_the_staff_member(): void
    {
        $expected = 'Actions for ' . self::FIXTURE_NAME;
        $name = $this->accessibleName($this->kebab());

        $this->assertSame($expected, $name, sprintf(
            'the staff card\'s actions kebab computes the accessible name %s. It is icon-only — no '
            . 'text node, no title, no aria-label — and .icon-more-vertical injects U+E89A, so '
            . 'Chromium measured name="U+E89A" len=1 on all three cards. It is the sole route to '
            . 'Edit, Reset Password, Delete and Login-enable.',
            var_export($name, true)));

        // The ordinal/identity half: three kebabs named identically satisfy SC 4.1.2 and are still
        // unusable. The name must carry the row's subject, as ProductGalleryImageActionNamesTest
        // already requires of the gallery tiles.
        $this->assertStringContainsString(self::FIXTURE_NAME, $name,
            'the kebab name does not identify which staff member it acts on, so all N cards '
            . 'announce the same control');
    }

    public function test_the_kebab_glyph_is_hidden_so_it_is_not_a_second_name_source(): void
    {
        $kebab = $this->kebab();

        $this->assertSame(1, preg_match('/<i class="feather icon-more-vertical" aria-hidden="true">/', $kebab),
            'the kebab\'s <i> still lacks aria-hidden. With the button named this is no longer the '
            . 'whole name, but it is still a name source: strip the aria-label and it reappears, '
            . 'and any descendant-driven recomputation picks U+E89A back up.');

        /*
         * BOTH halves have to come off for the glyph to return: `aria-label` wins at step 2C
         * before `aria-hidden` is ever consulted, so dropping only the latter proves nothing. With
         * both gone the name must be exactly the pre-repair U+E89A measured in Chromium.
         */
        $bare = (string) preg_replace('/\s+aria-label="[^"]*"/', '', $kebab, 1);

        $this->assertSame(
            $this->glyph('icon-more-vertical'),
            $this->accessibleName($bare, dropAriaHidden: true),
            'stripping BOTH halves of the repair no longer reproduces the pre-repair name, so the '
            . 'model cannot see this half of the defect at all');
    }

    public function test_every_menu_item_icon_is_hidden_from_the_name(): void
    {
        /*
         * MEASURED on the open dropdown in Chromium before the repair:
         *   "U+EA98 تحرير" / "U+EAC7 إعادة تعيين كلمة المرور" / "U+ECDD إيقاف تسجيل الدخول" /
         *   "U+EB41 احذف"
         * Every item in the menu was announced with a junk codepoint in front of its label.
         */
        $card = $this->card();

        $this->assertSame(1, preg_match('/<div class="dropdown-menu dropdown-menu-end">(.*?)<\/div>\s*<\/div>\s*<\/div>/s', $card, $m),
            'premise: the staff card\'s dropdown menu was not found');

        $menu = $m[1];
        preg_match_all('/<i class="ti ti-[a-z-]+"[^>]*>/', $menu, $icons);

        $this->assertGreaterThanOrEqual(4, count($icons[0]), sprintf(
            'premise: expected at least the Edit / Reset Password / Delete / Login-enable icons in '
            . 'the staff menu, found %d', count($icons[0])));

        foreach ($icons[0] as $icon) {
            $this->assertStringContainsString('aria-hidden="true"', $icon, sprintf(
                '%s leaks its Private-Use codepoint into the menu item\'s accessible name — the '
                . 'item is announced as "<junk> Edit" rather than "Edit"', $icon));
        }
    }

    // ---------------------------------------------------------------------------------------
    // (b) THE AVATAR
    // ---------------------------------------------------------------------------------------

    public function test_the_avatar_image_has_a_real_alt_and_so_the_link_is_named(): void
    {
        $link = $this->avatarLink();

        /*
         * MATCHED AS A LITERAL, not with `/<img\b[^>]*alt=/`. Both this tag's `src` and the
         * anchor's `href` are Blade ternaries over `$user->profile_image`, and `->` contains a
         * `>`, so a `[^>]*` hop stops INSIDE the echo and never reaches `alt`. A first draft of
         * this assertion reported the repaired markup as still unrepaired for exactly that reason;
         * the same trap is why `render()` neutralises non-naming attributes before parsing.
         */
        $this->assertStringContainsString(
            'alt="{{ __(\'' . self::PHOTO_KEY . '\', [\'name\' => $user->name]) }}"',
            $link,
            'the avatar <img> still has NO alt attribute — not alt="", absent. Chromium measured '
            . 'name="" len=0 from= for the <img> and for the <a target="_blank"> it is the only '
            . 'child of, so the link has no accessible name at all (SC 4.1.2, SC 2.4.4).');

        $expected = 'Profile photo of ' . self::FIXTURE_NAME;

        $this->assertSame($expected, $this->accessibleName($link), sprintf(
            'the avatar link computes the accessible name %s; it must be named after the staff '
            . 'member whose photo it opens, so that the three cards\' links are distinguishable',
            var_export($this->accessibleName($link), true)));

        // And the name must come from the image's alt, not from an aria-label on the anchor: the
        // <img> itself is announced in image lists and must not be the nameless one.
        $this->assertSame(0, preg_match('/<a\b[^>]*aria-label/', $link),
            'the avatar link is named by an aria-label on the anchor, which leaves the <img> '
            . 'itself nameless in an image list — put the name in the img\'s alt, where it names '
            . 'both');
    }

    // ---------------------------------------------------------------------------------------
    // (c) THE HEADING OUTLINE
    // ---------------------------------------------------------------------------------------

    public function test_the_staff_member_name_is_the_card_heading(): void
    {
        $headings = $this->headings($this->card());

        $this->assertNotSame([], $headings, 'the staff card emits no heading at all, so the card '
            . 'has no accessible subject');

        $this->assertSame(1, count($headings), sprintf(
            'the staff card emits %d headings (%s). The card has ONE subject — the staff member — '
            . 'and the type badge is a badge, not a section heading: the measured outline was '
            . '(h6 "Staff", h4 "<name>") per card, a three-level skip followed by a reversal.',
            count($headings),
            implode(', ', array_map(fn ($h) => "h{$h['level']} \"{$h['text']}\"", $headings))));

        $this->assertSame(3, $headings[0]['level'],
            'the staff card\'s heading is not an h3. The shared layout emits the page title as an '
            . 'h2, so h3 is the only level that neither skips nor reverses.');
        $this->assertSame(self::FIXTURE_NAME, $headings[0]['text'],
            'the staff card\'s heading is not the staff member\'s name');
    }

    public function test_the_whole_content_outline_neither_skips_nor_reverses(): void
    {
        /*
         * The rule the finding did not state, and the reason the card fix alone is not enough:
         * the "Create New User" tile carried its own h6. Repairing only the cards would have left
         * the outline h2, h3, h3, h3, h6 — the skip shortened from four levels to three rather
         * than removed.
         */
        $levels = array_map(fn (array $h): int => $h['level'], $this->headings($this->contentSection()));

        $this->assertNotSame([], $levels, 'premise: @section(\'content\') emits no headings');

        $previous = 2; // the layout's <h2 class="h3 mb-0 ss-scope">@yield('page-title')</h2>

        foreach ($levels as $i => $level) {
            $this->assertLessThanOrEqual($previous + 1, $level, sprintf(
                'heading #%d in @section(\'content\') is an h%d under an h%d — a skipped level. '
                . 'The full outline this screen emits is h2 then %s.',
                $i + 1, $level, $previous, implode(', ', array_map(fn ($l) => "h{$l}", $levels))));

            $previous = max($previous, $level);
        }

        $this->assertSame([], array_filter($levels, fn (int $l): bool => $l < 3), sprintf(
            '@section(\'content\') emits a heading at h1 or h2, which competes with the page title '
            . 'the layout already emits. Levels seen: %s',
            implode(', ', array_map(fn ($l) => "h{$l}", $levels))));
    }

    public function test_the_type_badge_is_no_longer_a_heading(): void
    {
        $card = $this->card();

        $this->assertSame(0, preg_match('/<h[1-6][^>]*>\s*<div class="badge/s', $card),
            'the type badge is still wrapped in a heading. It is a status badge, not a section '
            . 'heading — and a <div> inside an <h6> is not even valid, since a heading\'s content '
            . 'model is phrasing content.');

        // The badge itself must survive, at the same typographic scale: Bootstrap's `.badge`
        // font-size is `0.75em`, i.e. relative to its PARENT, so dropping the h6 without keeping
        // its scale would shrink every badge on the screen.
        $this->assertSame(1, preg_match('/<div class="h6 mb-0">\s*<div class="badge p-2 px-3 rounded-1 bg-primary">/s', $card),
            'the badge wrapper no longer carries the .h6 utility, so the badge\'s 0.75em font-size '
            . 'resolves against a different parent size and the badge changes size on screen — a '
            . 'visual regression smuggled in by a semantics fix');
    }

    // ---------------------------------------------------------------------------------------
    // TRANSLATIONS
    // ---------------------------------------------------------------------------------------

    public function test_both_new_keys_are_in_both_language_files_with_real_arabic(): void
    {
        $en = json_decode((string) file_get_contents($this->root() . '/resources/lang/en.json'), true);
        $ar = json_decode((string) file_get_contents($this->root() . '/resources/lang/ar.json'), true);

        foreach ([self::KEBAB_KEY, self::PHOTO_KEY] as $key) {
            $this->assertArrayHasKey($key, $en, "resources/lang/en.json is missing \"{$key}\"");
            $this->assertArrayHasKey($key, $ar, "resources/lang/ar.json is missing \"{$key}\" — an "
                . 'Arabic merchant would hear an English control name on their own staff screen');
            $this->assertNotSame('', trim((string) $ar[$key]));
            $this->assertNotSame($key, $ar[$key], "\"{$key}\" is not actually translated");
            $this->assertStringContainsString(':name', (string) $en[$key],
                "\"{$key}\" lost its :name placeholder in en.json, so every card gets the same name");
            $this->assertStringContainsString(':name', (string) $ar[$key],
                "\"{$key}\" lost its :name placeholder in ar.json, so every card gets the same name");
        }

        /*
         * The ar-only orphan ceiling TranslationFileIntegrityTest ratchets on. A key added to
         * ar.json alone raises this past 31 and fails that gate — asserted here too so this file
         * fails first, with a message that says why.
         */
        $orphans = array_diff(array_keys($ar), array_keys($en));
        $this->assertLessThanOrEqual(31, count($orphans), sprintf(
            'ar.json now declares %d key(s) en.json does not (the measured ceiling is 31): %s',
            count($orphans), implode(' | ', array_slice($orphans, 0, 6))));
    }
}
