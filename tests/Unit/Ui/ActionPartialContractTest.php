<?php

namespace Tests\Unit\Ui;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * D-25 STEP 2 — the row-action partials, converted to the design system as one unit.
 * =================================================================================
 *
 * WHAT THE SLICE DID. `resources/views/<entity>/action.blade.php` is the cell every merchant list
 * renders at the end of each row. There are 37 of them. Seven were already on the design system;
 * the other 30 carried `btn btn-sm btn-<colour> btn-badge` with `title` as their only name. This
 * slice converted those 30 to `ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost` (or
 * `ss-btn--danger-quiet` for a delete), gave each control an `aria-label`, and marked every icon
 * decorative.
 *
 * MEASURED BEFORE: 159 `title` attributes against 25 `aria-label`s across the 37 files, so about
 * 134 controls had no accessible name at all — `title` is not one here, because
 * `data-bs-toggle="tooltip"` makes Bootstrap MOVE the attribute into `data-bs-original-title` and
 * blank it on initialisation. A merchant on a screen reader heard "link" 134 times.
 *
 * WHY THIS FILE AND NOT ONLY THE BROWSER CHECK. `check-row-actions-live.cjs` measures the rendered
 * result, and it is the authority on contrast and on the name surviving the tooltip plugin. But
 * the dev fixture merchant can reach only two of the thirty converted screens with data on them
 * (the rest redirect for want of a permission, or render an empty list — measured, by driving all
 * nine candidates). So the browser proves the CSS, which is shared, and this file proves the
 * per-file facts across all 37.
 *
 * THE DUPLICATE `class` ATTRIBUTE, which is the defect this slice found rather than introduced.
 * `deliveryboy/` and `store/` each carried TWO `class` attributes on ONE `<a>`:
 *
 *     <a href="#" class="btn btn-sm btn-danger bs-pass-para show_confirm" ...
 *        class="btn btn-sm btn-secondary" data-bs-toggle="tooltip" ...>
 *
 * MEASURED in a real Chromium: the parser keeps the FIRST and discards the second entirely —
 * `getAttribute('class')` returns the first, `classList` holds none of the second's tokens, and
 * the tag's other attributes survive. So `btn-secondary` had never applied to anything and the
 * control had always rendered red. The dead attribute is removed, and this file refuses a new one,
 * because a second `class` is a silent way for a conversion to look applied and not be.
 */
class ActionPartialContractTest extends TestCase
{
    use StripsSourceComments;

    /** Every tag that can be an action control in these partials. */
    private const TAG = '/<(button|a|input)\b((?:[^<>"\']|"[^"]*"|\'[^\']*\')*?)>/s';

    /** Bootstrap button classes the conversion removed. `btn-badge` is dead markup: grepped
     *  across every first-party and vendored css and js in the repository, it is selected by no
     *  script and styled by no stylesheet. */
    private const BOOTSTRAP_BUTTON = [
        'btn', 'btn-sm', 'btn-lg', 'btn-badge',
        'btn-info', 'btn-warning', 'btn-danger', 'btn-primary', 'btn-secondary', 'btn-success',
        'btn-light', 'btn-dark',
    ];

    /** @return list<string> */
    private function partials(): array
    {
        $found = glob(base_path('resources/views/*/action.blade.php')) ?: [];

        /*
         * A PREMISE, because every assertion below iterates this list and an empty or shrunken
         * one would pass all of them. 37 is the measured count at the time of the slice; the
         * assertion is a floor, so adding an entity does not fail the suite but losing most of
         * them does.
         */
        $this->assertGreaterThanOrEqual(
            37,
            count($found),
            'fewer action partials than the 30 this slice converted plus the 7 already on the DS'
            . ' — the glob is wrong, so nothing below is measuring anything'
        );

        return array_map(fn (string $p): string => str_replace(base_path() . '/', '', $p), $found);
    }

    public function test_every_design_system_action_control_has_an_accessible_name(): void
    {
        $unnamed = [];
        $named = 0;

        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            preg_match_all(self::TAG, $source, $tags, PREG_SET_ORDER);

            foreach ($tags as $tag) {
                if (! str_contains($tag[2], 'ss-btn')) {
                    continue;
                }

                if (preg_match('/\baria-label="\s*"/', $tag[2])
                    || ! str_contains($tag[2], 'aria-label=')) {
                    $unnamed[] = $relative . ': ' . trim(preg_replace('/\s+/', ' ', substr($tag[0], 0, 110)));
                    continue;
                }

                $named++;
            }
        }

        /*
         * A FLOOR ON THE COUNT, not just "none unnamed". Deleting every control in all 37 files
         * satisfies "none unnamed" perfectly.
         */
        $this->assertGreaterThanOrEqual(
            98,
            $named,
            'fewer named action controls than the 98 measured after the conversion'
        );

        $this->assertSame(
            [],
            $unnamed,
            "a design-system action control with no accessible name.\n"
            . "`title` is NOT a name on these controls: `data-bs-toggle=\"tooltip\"` makes Bootstrap\n"
            . "move it into `data-bs-original-title` and blank it, so the name has to be an\n"
            . "`aria-label`.\n  " . implode("\n  ", $unnamed)
        );
    }

    public function test_no_action_control_still_carries_a_bootstrap_button_class(): void
    {
        $left = [];

        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            preg_match_all('/\bclass="([^"]*)"/', $source, $attrs);

            foreach ($attrs[1] as $attr) {
                /* The whole point is the DS class REPLACING the bootstrap one, so a class
                   attribute that still holds both is the failure this looks for. */
                foreach (preg_split('/\s+/', trim($attr)) ?: [] as $class) {
                    if (in_array($class, self::BOOTSTRAP_BUTTON, true)) {
                        $left[] = $relative . ': class="' . $attr . '"';
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $left,
            "a bootstrap button class survived the design-system conversion.\n"
            . "The two coexisting is not harmless: bootstrap's `.btn` sets its own padding,\n"
            . "border-radius and background, so the control renders as neither one.\n  "
            . implode("\n  ", $left)
        );
    }

    public function test_no_action_control_carries_a_duplicate_class_attribute(): void
    {
        $duplicates = [];

        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            preg_match_all(self::TAG, $source, $tags, PREG_SET_ORDER);

            foreach ($tags as $tag) {
                if (preg_match_all('/\bclass=/', $tag[2]) > 1) {
                    $duplicates[] = $relative . ': ' . trim(preg_replace('/\s+/', ' ', substr($tag[0], 0, 140)));
                }
            }
        }

        $this->assertSame(
            [],
            $duplicates,
            "a tag carries two `class` attributes.\n"
            . "MEASURED in Chromium: the parser keeps the FIRST and discards the second, so the\n"
            . "second is dead markup that reads as live. `deliveryboy/` and `store/` each shipped\n"
            . "one for as long as the files existed, and a conversion that edited the first\n"
            . "attribute looked applied while the file still said `btn-secondary`.\n  "
            . implode("\n  ", $duplicates)
        );
    }

    public function test_every_icon_in_an_action_cell_is_hidden_from_assistive_technology(): void
    {
        $exposed = [];
        $hidden = 0;

        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            /*
                 * THE CLASS ATTRIBUTE MAY CARRY MORE THAN THE ICON NAME, and requiring it not to
                 * is how three icons in `store/` stayed announced: they are written
                 * `class="ti ti-trophy py-1"`, and the converter's pattern AND this test's first
                 * pattern both required the attribute to end after the icon name. Two blind spots
                 * with the same shape agree with each other, which reads as a pass.
                 */
            preg_match_all('/<i\s+class="ti ti-[a-z0-9-]+[^"]*"([^>]*)>/', $source, $icons, PREG_SET_ORDER);

            foreach ($icons as $icon) {
                if (str_contains($icon[1], 'aria-hidden="true"')) {
                    $hidden++;
                    continue;
                }

                $exposed[] = $relative . ': ' . $icon[0];
            }
        }

        $this->assertGreaterThanOrEqual(84, $hidden, 'fewer decorative icons than the 84 measured');

        $this->assertSame(
            [],
            $exposed,
            "an action icon is exposed to assistive technology.\n"
            . "The control now carries the name, so an announced icon is a second, meaningless\n"
            . "announcement of the same control.\n  " . implode("\n  ", $exposed)
        );
    }

    public function test_every_delete_control_sits_inside_a_form(): void
    {
        $orphans = [];
        $checked = 0;

        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            /*
             * BOTH FORM SPELLINGS. Most partials use `Form::open()`/`Form::close()`, but
             * `customer/action.blade.php` writes a raw `<form>` — a check that knew only the
             * facade reported that file's delete control as orphaned, which it is not.
             */
            $spans = $this->formSpans($source);

            foreach ($this->offsetsOf('/class="[^"]*\bshow_confirm\b/', $source) as $offset) {
                $checked++;

                $inside = false;
                foreach ($spans as [$from, $to]) {
                    if ($offset >= $from && $offset <= $to) {
                        $inside = true;
                        break;
                    }
                }

                if (! $inside) {
                    $orphans[] = $relative . ' @ ' . $offset;
                }
            }
        }

        $this->assertGreaterThanOrEqual(20, $checked, 'fewer delete controls than the partials hold');

        $this->assertSame(
            [],
            $orphans,
            "a `.show_confirm` delete control is outside every form in its file.\n"
            . "`public/js/custom.js` submits `closest('form')`, so such a control opens the\n"
            . "confirmation dialog and then deletes nothing.\n  " . implode("\n  ", $orphans)
        );
    }

    public function test_every_design_system_variant_used_is_defined_in_the_stylesheet(): void
    {
        $css = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        $used = [];
        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            preg_match_all('/\bclass="([^"]*)"/', $source, $attrs);

            foreach ($attrs[1] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) ?: [] as $class) {
                    if (str_starts_with($class, 'ss-btn')) {
                        $used[$class] = true;
                    }
                }
            }
        }

        $this->assertNotSame([], $used, 'no DS button class is used at all, so this proves nothing');

        $undefined = [];
        foreach (array_keys($used) as $class) {
            if (! str_contains($css, '.' . $class)) {
                $undefined[] = $class;
            }
        }

        $this->assertSame(
            [],
            $undefined,
            'a design-system button class is used in a view and defined in no stylesheet, so the'
            . " control renders unstyled: " . implode(', ', $undefined)
        );
    }

    public function test_every_label_these_partials_compose_is_translated_in_both_languages(): void
    {
        $en = json_decode((string) file_get_contents(base_path('resources/lang/en.json')), true);
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertIsArray($en);
        $this->assertIsArray($ar);
        $this->assertGreaterThan(3000, count($en), 'en.json did not parse into the real catalogue');
        $this->assertGreaterThan(3000, count($ar), 'ar.json did not parse into the real catalogue');

        $keys = [];
        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            /* The message key of every composed accessible name, and every noun interpolated into
               one -- both halves go through __(), so both have to exist in both files. */
            preg_match_all('/aria-label="\{\{\s*__\(\'([^\']+)\'/', $source, $messages);
            preg_match_all('/\'thing\'\s*=>\s*__\(\'([^\']+)\'\)/', $source, $nouns);

            foreach (array_merge($messages[1], $nouns[1]) as $key) {
                $keys[$key] = $relative;
            }
        }

        $this->assertGreaterThanOrEqual(60, count($keys), 'fewer label keys than these partials compose');

        $untranslated = [];
        foreach ($keys as $key => $relative) {
            $missing = [];
            if (! array_key_exists($key, $en)) {
                $missing[] = 'en';
            }
            if (! array_key_exists($key, $ar)) {
                $missing[] = 'ar';
            }
            if ($missing !== []) {
                $untranslated[] = sprintf('%s (%s) — %s', $key, implode(' + ', $missing), $relative);
            }
        }

        $this->assertSame(
            [],
            $untranslated,
            "an accessible name is composed from a key that is in no language file.\n"
            . "`__()` FALLS BACK TO THE KEY, so this does not fail — it renders the English key with\n"
            . "an Arabic noun substituted into it: `Delete عربة`. In English it reads correctly BY\n"
            . "ACCIDENT, because the key is English, which is why only an Arabic reader would ever\n"
            . "have found it. This slice shipped nine such keys before the check existed.\n  "
            . implode("\n  ", $untranslated)
        );
    }

    public function test_no_accessible_name_is_a_bare_verb(): void
    {
        /* A name has to say what the control acts ON. In a fifty-row table, fifty controls
           announcing "Delete" is the defect this slice set out to fix, not a fix of it. */
        $verbs = [
            'delete', 'edit', 'show', 'view', 'create', 'add', 'update',
            'reply', 'accept', 'reject', 'refund', 'cancel', 'approve', 'remove',
        ];

        $bare = [];
        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            preg_match_all('/aria-label="\{\{\s*__\(\'([^\']+)\'\)\s*\}\}"/', $source, $labels);

            foreach ($labels[1] as $label) {
                if (in_array(strtolower(trim($label)), $verbs, true)) {
                    $bare[] = $relative . ': ' . $label;
                }
            }
        }

        $this->assertSame(
            [],
            $bare,
            "an accessible name is a bare verb with no object.\n"
            . "Three of these shipped in this slice, because the converter preferred each file's\n"
            . "own `data-title` and in those three that attribute is just the verb.\n  "
            . implode("\n  ", $bare)
        );
    }

    /**
     * NO ACTION PARTIAL MAY CARRY A `<style>`, AND EVERY INLINE SVG ROOT MUST TAKE ITS COLOUR
     * FROM THE CASCADE. Two measured defects, one markup cause.
     * ======================================================================================
     *
     * WHAT SHIPPED. `cart/action.blade.php` and `wishlist/action.blade.php` each inlined the
     * WhatsApp glyph as `<svg …><style>svg{fill:#ffffff}</style><path …/></svg>` — no `fill` on
     * the root and none on the `<path>`, so that one declaration was the only thing colouring the
     * glyph. It was correct under the ORIGINAL markup: the button was `.btn-primary`, a solid
     * blue, and `public/css/custom.css:2460`/`:2469` (mirrored `public/css/rtl-custom.css:2480`)
     * still hold the `.btn-primary:hover svg path { fill:#fff }` rules it was paired with. The
     * P2-03 conversion to `.ss-btn--ghost` removed `.btn-primary`, orphaned those host rules, and
     * left the inline `<style>` painting white on a white card.
     *
     * DEFECT ONE — THE CONTROL ITSELF, LIGHT MODE ONLY. `.ss-btn--ghost { color:
     * var(--ss-fg-muted) }` (`public/css/store-syria-ds.css:680`) over `.ss-dt-card-body
     * { --ss-dt-card-bg: #ffffff }` (:3316). MEASURED with the WCAG 2.x relative-luminance
     * formula, not quoted: #ffffff on #ffffff = 1.00:1 — an empty box, 10 of them per page
     * (DataTables' default `pageLength`; `buildDataTable` at app/Helper/helper.php sets only a
     * `lengthMenu` label). Dark was never affected: the card is #292a33 (:3317) where #ffffff
     * measures 14.25:1.
     *
     * DEFECT TWO — THE WHOLE DOCUMENT. A `<style>` inside an SVG inlined into HTML is NOT scoped
     * to that SVG; it is a document-level stylesheet. The bare `svg` TYPE selector therefore
     * repainted every other svg on the page, and an author rule outranks an element's own
     * presentation attribute, so it beat `fill="none"`. The exposed set is SIX icons, not five:
     * `resources/views/partision/header.blade.php:59,196,202,243,310` carry `fill="none"` on the
     * root with every child (`path`, `circle`, `rect`, `polyline`, `line`) inheriting, and :134
     * carries no `fill` attribute at all. The header is included on every admin page
     * (`resources/views/layouts/app.blade.php`), and `AbandonCartDataTable::rawColumns()` ships
     * the `<style>` into the DOM unescaped.
     *
     * WHY THIS ASSERTS THE PROPERTY AND NOT THE COLOUR. The repair adds `fill="currentColor"`, so
     * the glyph inherits `.ss-btn--ghost`'s `color`: `--ss-fg-muted` = `--ss-grey-600` #5b5f66
     * (store-syria-ds.css:179, :244) = 6.42:1 on #ffffff in light, and #a7abb2 (:402, :450) =
     * 6.18:1 on #292a33 in dark — both measured here, both clear WCAG 1.4.11's 3:1. Pinning
     * either number would be the wrong assertion: the point of `currentColor` is that the glyph
     * has NO colour of its own, so a ratio would redden on a token re-bias the property survives.
     * Clause (b) pins the inheritance instead.
     *
     * BOTH HALVES, OR THE FIX INVERTS THE DEFECT. Deleting the `<style>` and adding nothing falls
     * back to SVG's initial `fill: black` — #000000 on the dark card #292a33 = 1.47:1, measured,
     * a WORSE defect in the other theme. So clause (b) refuses a root with no `fill` at all.
     * Keeping the `<style>` alongside `fill="currentColor"` is equally broken, because an author
     * rule beats a presentation attribute — clause (a) refuses that.
     *
     * `none` IS ALLOWED AND THAT IS NOT A LOOPHOLE. `resources/views/plans/action.blade.php` is
     * the THIRD partial with an inline svg: a Tabler outline icon, `fill="none"
     * stroke="currentColor"`, whose unfilled children inherit. Requiring `currentColor` there
     * would turn an outline glyph into a solid blob — a test that manufactured the defect it
     * exists to prevent. Both values take their ink from the cascade; a literal colour does not.
     *
     * NOT FIXED IN CSS, DELIBERATELY. `DesignSystemStylesheetScopeTest` already refuses a bare
     * `svg` selector entering `store-syria-ds.css`; a CONFINED `.ss-btn svg { fill: currentColor }`
     * would pass it and would fix the button, but would leave the document-wide repaint standing.
     * Because the repair is markup, neither `custom.css` nor `rtl-custom.css` is touched, so the
     * two-stylesheet LTR/RTL trap does not apply — `fill` is not a directional property.
     */
    public function test_no_action_partial_styles_its_icons_with_a_literal_colour(): void
    {
        $styleElements = [];
        $uncascaded = [];
        $inlineColours = [];
        $svgRoots = 0;
        $filesWithSvg = [];

        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            /* (a) The `<style>` ELEMENT. This is the clause that kills the page-wide repaint, and
               it holds whatever variant anyone later gives the button. */
            if (preg_match('/<style[\s>]/i', $source)) {
                $styleElements[] = $relative;
            }

            /* (b) EVERY SVG ROOT. The `/s` modifier is load-bearing: the `plans/` opening tag
               spans six lines, and a pattern without it sees two of the three files. */
            preg_match_all('/<svg\b((?:[^<>"\']|"[^"]*"|\'[^\']*\')*?)>/s', $source, $tags, PREG_SET_ORDER);

            foreach ($tags as $tag) {
                $svgRoots++;
                $filesWithSvg[$relative] = true;

                if (! preg_match('/\bfill="(currentColor|none)"/', $tag[1])) {
                    $uncascaded[] = $relative . ': ' . trim(preg_replace('/\s+/', ' ', substr($tag[0], 0, 120)));
                }
            }

            /* (c) AND THE DELETED RULE MUST NOT RETURN AS AN ATTRIBUTE. A literal `fill:` or
               `stroke:` inside an inline `style=` anywhere in an svg block is the same defect
               wearing different clothes. */
            preg_match_all('/<svg\b.*?<\/svg>/s', $source, $blocks);

            foreach ($blocks[0] as $block) {
                preg_match_all('/\bstyle="([^"]*)"/', $block, $inline);

                foreach ($inline[1] as $declaration) {
                    if (preg_match('/\b(fill|stroke)\s*:/i', $declaration)) {
                        $inlineColours[] = $relative . ': style="' . $declaration . '"';
                    }
                }
            }
        }

        /*
         * (e) THE FLOOR, for the reason documented at the `partials()` premise. A regex that
         * silently matches nothing passes every "none offending" assertion below, and that is the
         * live risk here rather than a theoretical one: a first census written as
         * `grep -o '<svg[^>]*>'` saw two of the three files, because `plans/` writes its opening
         * tag across six lines.
         */
        $this->assertGreaterThanOrEqual(
            3,
            $svgRoots,
            'fewer inline svg roots than the 3 measured in cart/, wishlist/ and plans/ action'
            . ' partials — the pattern missed a multi-line opening tag, so nothing below is'
            . ' measuring anything. Saw: ' . implode(', ', array_keys($filesWithSvg))
        );

        $this->assertSame(
            [],
            $styleElements,
            "an action partial contains a `<style>` element.\n"
            . "A `<style>` inside an inlined svg is NOT scoped to it — it is a document-level\n"
            . "stylesheet, and a bare `svg` type selector in it repaints every svg on the page,\n"
            . "beating `fill=\"none\"` because an author rule outranks a presentation attribute.\n"
            . "MEASURED: `svg{fill:#ffffff}` here white-filled the six header icons at\n"
            . "partision/header.blade.php:59,134,196,202,243,310 on every admin page.\n  "
            . implode("\n  ", $styleElements)
        );

        $this->assertSame(
            [],
            $uncascaded,
            "an inline svg root declares no `fill=\"currentColor\"` and no `fill=\"none\"`.\n"
            . "Both take the glyph's ink from the cascade, which is the whole requirement; a\n"
            . "literal colour, or NO `fill` at all, does not. No `fill` is the inverted defect:\n"
            . "SVG's initial value is `fill: black`, and #000000 on the dark DataTable card\n"
            . "#292a33 (store-syria-ds.css:3317) measures 1.47:1. With `currentColor` the glyph\n"
            . "takes `.ss-btn--ghost`'s `color` — #5b5f66 on #ffffff = 6.42:1 light, #a7abb2 on\n"
            . "#292a33 = 6.18:1 dark, both above WCAG 1.4.11's 3:1.\n  "
            . implode("\n  ", $uncascaded)
        );

        $this->assertSame(
            [],
            $inlineColours,
            "an svg in an action partial hard-codes `fill:` or `stroke:` in an inline `style=`.\n"
            . "That is the deleted `<style>svg{fill:#ffffff}</style>` returning as an attribute:\n"
            . "same literal, same failure, and it beats `currentColor` on specificity.\n  "
            . implode("\n  ", $inlineColours)
        );
    }

    /**
     * THE NEGATIVE CONTROL FOR THE REPAIR ABOVE: it must not take away what the shipped AJAX
     * depends on. `resources/views/cart/index.blade.php` binds a delegated handler on
     * `.bandoncartmess` and `resources/views/wishlist/index.blade.php` on `.bandonwishmess`; both
     * read `$(this).attr('data-id')` OFF THE BUTTON and POST it to `carts.messagesend` /
     * `wishlist.message.send`. Neither the duplicate-class nor the bootstrap-class method above
     * covers `data-id`, so a repair that rewrote the button tag while editing the svg inside it
     * would silently break the send, and every assertion in this file would still pass.
     *
     * The attribute is spelled `data-id =` in `cart/` and `data-id=` in `wishlist/` — both are
     * valid HTML and jQuery reads either, so the pattern tolerates the whitespace rather than
     * pinning one file's spelling.
     */
    public function test_the_abandoned_follow_up_buttons_still_carry_what_their_handler_reads(): void
    {
        $handlers = ['bandoncartmess' => 'cart', 'bandonwishmess' => 'wishlist'];

        $broken = [];
        $found = [];

        foreach ($this->partials() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            preg_match_all(self::TAG, $source, $tags, PREG_SET_ORDER);

            foreach ($tags as $tag) {
                foreach ($handlers as $handler => $entity) {
                    if (! preg_match('/\bclass="[^"]*\b' . $handler . '\b/', $tag[2])) {
                        continue;
                    }

                    $found[$handler] = $relative;

                    if (! preg_match('/\bdata-id\s*=\s*"\s*\{\{/', $tag[2])) {
                        $broken[] = $relative . ' (' . $handler . '): '
                            . trim(preg_replace('/\s+/', ' ', substr($tag[0], 0, 160)));
                    }
                }
            }
        }

        /* The premise: both handler buttons still exist to be measured. */
        $this->assertSame(
            ['bandoncartmess', 'bandonwishmess'],
            array_keys($found),
            'one of the two abandoned follow-up buttons is gone from its action partial, so the'
            . ' assertion below measures nothing'
        );

        $this->assertSame(
            [],
            $broken,
            "an abandoned follow-up button lost the `data-id` its delegated handler reads.\n"
            . "cart/index.blade.php and wishlist/index.blade.php both do\n"
            . "`\$(this).attr('data-id')` on the BUTTON and POST it; without it the request goes\n"
            . "out with an empty id and the merchant's follow-up silently sends to nobody.\n  "
            . implode("\n  ", $broken)
        );
    }

    /** @return list<array{int, int}> */
    private function formSpans(string $source): array
    {
        $events = [];
        foreach ($this->offsetsOf('/Form::open|<form\b/', $source) as $offset) {
            $events[] = [$offset, 1];
        }
        foreach ($this->offsetsOf('#Form::close|</form>#', $source) as $offset) {
            $events[] = [$offset, -1];
        }
        usort($events, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $spans = [];
        $depth = 0;
        $start = null;
        foreach ($events as [$offset, $delta]) {
            if ($delta === 1) {
                if ($depth === 0) {
                    $start = $offset;
                }
                $depth++;
                continue;
            }
            $depth--;
            if ($depth === 0 && $start !== null) {
                $spans[] = [$start, $offset];
                $start = null;
            }
        }

        return $spans;
    }

    /** @return list<int> */
    private function offsetsOf(string $pattern, string $source): array
    {
        preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $m): int => $m[1], $matches[0]);
    }
}
