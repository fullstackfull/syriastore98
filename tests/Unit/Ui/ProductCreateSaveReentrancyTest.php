<?php

namespace Tests\Unit\Ui;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * create-save-double-submit — THE CREATE-PRODUCT SAVE HAD NO RE-ENTRANCY GUARD AT ALL.
 * ====================================================================================
 *
 * `resources/views/product/create.blade.php` binds ~120 lines of FormData assembly to
 * `#submit-all`'s click, and the only thing it did on entry was
 * `$('#submit-all').attr('disabled', true)`. `#submit-all` is an `<a href="#">` (create.blade.php
 * line 17): the `disabled` CONTENT ATTRIBUTE IS NOT DEFINED FOR AN ANCHOR, so nothing reads it.
 * The jQuery handler still fires on every click, and the sticky action-bar Save reaches the same
 * handler through `data-ss-click-proxy="#submit-all"`, which `StoreSyriaDS.wireClickProxy()`
 * serves with an unconditional `target.click()` (public/js/store-syria-ds.js, no disabled or
 * aria-disabled test). Measured: the guard attribute is WRITTEN at seven sites in that view and
 * READ at none.
 *
 * Nothing downstream de-duplicates. `ProductController::store()` has no `unique` rule on
 * name/slug/sku, `Product::slugs()` appends `-1` on collision, and `products.slug` has no unique
 * index — so two clicks persist two products, the second re-uploads the cover image and the
 * gallery and is charged again against `Utility::updateStorageLimit`. The window is seconds wide
 * because the merchant got no feedback whatsoever during the multipart upload: the view calls
 * `$('#loader').fadeOut()` at all four response sites and never showed it.
 *
 * WHY THIS IS A SOURCE-STRUCTURE TEST. The defect is JavaScript re-entrancy. PHPUnit cannot click
 * an anchor, and the HTML this view renders is IDENTICAL before and after the repair — the fix
 * lives entirely in the pushed script block. What can be asserted is the structure the guard
 * needs, and the three placements that each have a measured failure mode of their own:
 *
 *   1. the latch is read as the FIRST statement of the handler body;
 *   2. it is SET at the two request sites, not at the top of the handler — there are three exits
 *      before the request (a `return false`, and two unguarded dereferences that can throw) and
 *      setting it early wedged Save dead for the life of the page with no visual sign, because
 *      `attr('disabled', …)` on an anchor does nothing;
 *   3. it is CLEARED as the first statement of all four response callbacks, ABOVE the existing
 *      `fadeOut()` — not at the end of the `else` branches, which sit downstream of
 *      `show_toastr()` and `StoreSyriaDS.applyFormErrors()`, either of which throwing leaves the
 *      latch stuck in exactly the state point 2 exists to prevent;
 *   4. and it is RE-SET on the navigating success branch, so the page stays latched across
 *      `window.location.href` — which is the duplicate this finding is about.
 *
 * COMMENTS ARE STRIPPED BEFORE EVERY STRUCTURAL ASSERTION (Tests\Support\StripsSourceComments).
 * This repository has had five ratchets pass against a comment describing the defect they were
 * meant to catch, so the prose above must not be able to satisfy the test below.
 */
class ProductCreateSaveReentrancyTest extends TestCase
{
    use StripsSourceComments;

    private const VIEW = 'resources/views/product/create.blade.php';

    /** The click binding every Save control on the create screen reaches. */
    private const HANDLER = "\$('#submit-all').on('click', function() {";

    private function code(): string
    {
        return $this->sourceWithoutComments(self::VIEW);
    }

    /** The handler body: from the single click binding to the end of the script. */
    private function handler(string $code): string
    {
        $at = strpos($code, self::HANDLER);
        $this->assertNotFalse($at, 'premise: the single save handler was not found in ' . self::VIEW);

        return substr($code, $at);
    }

    // =============================================================================================
    //  The five structural properties, each evaluated against an arbitrary source string so the
    //  negative control below can feed in a mutant and prove the detector still has teeth.
    // =============================================================================================

    /** @return array<string,bool> property => holds */
    private function properties(string $code): array
    {
        $handler = $this->handler($code);

        /* The latch is declared once, as a var at script scope (edit.blade.php does the same). */
        $declared = preg_match_all('/\bvar ssSaveInFlight = false;/', $code) === 1;

        /* Read as the FIRST statement of the handler body. */
        $guardsFirst = preg_match(
            '/\$\(\'#submit-all\'\)\.on\(\'click\', function\(\) \{\s*if \(ssSaveInFlight\) \{ return; \}/',
            $code
        ) === 1;

        /* Set immediately before each `$.ajax({` -- nothing but the loader show in between. */
        $setAtRequestSites = preg_match_all(
            '/ssSaveInFlight = true;[^;]{0,160};\s*\$\.ajax\(\{/s',
            $handler
        ) === 2;

        /* Cleared as the first statement of all four response callbacks, above the fadeOut. */
        $clearedFirst = preg_match_all(
            '/(?:success|error): function\(data\) \{\s*ssSaveInFlight = false;\s*\$\(\'#loader\'\)\.fadeOut\(\);/',
            $handler
        ) === 4;

        /*
         * Re-set on the navigating branch: at each `window.location.href` the latch must most
         * recently have been set, not cleared. A positional check rather than a literal one,
         * because what matters is the state of the latch when navigation starts.
         */
        $latchedThroughNavigation = true;
        $offset = 0;
        $navigations = 0;
        while (($at = strpos($handler, 'window.location.href', $offset)) !== false) {
            $before = substr($handler, 0, $at);
            $set = strrpos($before, 'ssSaveInFlight = true;');
            $clear = strrpos($before, 'ssSaveInFlight = false;');

            if ($set === false || ($clear !== false && $clear > $set)) {
                $latchedThroughNavigation = false;
            }

            $navigations++;
            $offset = $at + 1;
        }
        $this->assertSame(2, $navigations, 'premise: the two navigating success branches moved');

        return [
            'the latch is declared exactly once' => $declared,
            'the latch is read as the first statement of the handler' => $guardsFirst,
            'the latch is set at both request sites' => $setAtRequestSites,
            'the latch is cleared first in all four response callbacks' => $clearedFirst,
            'the latch is still set when the success branch navigates' => $latchedThroughNavigation,
        ];
    }

    // =============================================================================================
    //  1. POSITIVE — the repaired behaviour is present
    // =============================================================================================

    public function test_the_create_save_handler_cannot_be_entered_twice(): void
    {
        foreach ($this->properties($this->code()) as $property => $holds) {
            $this->assertTrue($holds, self::VIEW . ': ' . $property);
        }
    }

    /**
     * THE MERCHANT MUST SEE SOMETHING while the multipart upload runs, or the double click is
     * only discouraged rather than explained. The view already calls `fadeOut()` at all four
     * response sites; the repair adds the two matching shows at the request sites, so the pair is
     * balanced and no teardown code is introduced.
     *
     * `.css('display', 'flex')` and not `.fadeIn()`: jQuery restores a div's default
     * `display: block`, which beats `.loader-wrapper { display: flex }` from the inline style and
     * leaves `.site-loader { margin: auto }` with no flex context to centre in
     * (public/css/loader.css and its RTL twin public/css/rtl-loader.css are identical in this
     * respect, and layouts/app.blade.php links one or the other per direction -- so there is no
     * stylesheet edit and nothing to mirror).
     */
    public function test_the_save_shows_the_loader_it_already_hides(): void
    {
        $handler = $this->handler($this->code());

        $this->assertSame(2, preg_match_all('/\$\(\'#loader\'\)\.css\(\'display\', \'flex\'\);/', $handler),
            self::VIEW . ': the two request sites must raise the scrim the four response sites lower');

        $this->assertSame(4, preg_match_all('/\$\(\'#loader\'\)\.fadeOut\(\);/', $handler),
            self::VIEW . ': the four existing fadeOut calls must remain, or the scrim never lowers');
    }

    /**
     * A SUCCESSFUL SAVE IS NOT A LOSS OF WORK. `StoreSyriaDS.wireDirtyTracking` clears
     * `data-ss-dirty` only on the form's `submit` event, which this editor never fires -- it
     * posts a hand-built FormData by `$.ajax` -- so the DS `beforeunload` armed the browser's
     * leave-site dialog on every successful create, while loader.js raised the full-viewport
     * scrim on the same event with nothing left to lower it. A merchant who answered "stay" was
     * left on a bricked page whose product already existed.
     */
    public function test_the_success_branch_clears_the_dirty_flag_before_navigating(): void
    {
        $handler = $this->handler($this->code());

        $offset = 0;
        $checked = 0;
        while (($at = strpos($handler, 'window.location.href', $offset)) !== false) {
            $window = substr($handler, max(0, $at - 600), min(600, $at));

            $this->assertStringContainsString("\$('#choice_form').attr('data-ss-dirty', 'false');", $window,
                self::VIEW . ': a navigating success branch leaves the form marked dirty, so the '
                . 'browser arms its leave-site dialog over a save that already succeeded');

            $checked++;
            $offset = $at + 1;
        }

        $this->assertSame(2, $checked, 'premise: both navigating success branches were examined');
    }

    // =============================================================================================
    //  2. NEGATIVE CONTROL — the detector can still see the defect
    // =============================================================================================

    /**
     * Delete every latch line and the four positive properties must all fail. Without this, a
     * regex typo that matches nothing would read as success forever — the exact way five earlier
     * ratchets in this repository passed against the defect they were written for.
     */
    public function test_the_detector_still_fails_when_the_latch_is_removed(): void
    {
        $mutant = (string) preg_replace('/^.*ssSaveInFlight.*$\n?/m', '', $this->code());

        $this->assertStringNotContainsString('ssSaveInFlight', $mutant, 'premise: the mutation applied');
        $this->assertStringContainsString(self::HANDLER, $mutant, 'premise: the mutation kept the handler');

        foreach ($this->properties($mutant) as $property => $holds) {
            $this->assertFalse($holds,
                'the detector reports "' . $property . '" against a source with no latch at all, '
                . 'so that assertion proves nothing');
        }
    }

    // =============================================================================================
    //  3. NEGATIVE CONTROL — the repair took nothing away
    // =============================================================================================

    /**
     * BOTH Save controls must still save, and both must be guarded by the SAME latch. The guard
     * deliberately lives in the shared handler and not on the header anchor's own listener: no
     * test anywhere pins `data-ss-click-proxy` or `wireClickProxy`, so a `preventDefault` guard on
     * the header control would have left the sticky action-bar Save unguarded with nothing to
     * catch it.
     */
    public function test_both_save_controls_still_reach_the_one_guarded_handler(): void
    {
        $raw = (string) file_get_contents(base_path(self::VIEW));

        $this->assertSame(1, substr_count($raw, 'id="submit-all"'),
            self::VIEW . ': there must be exactly one Save implementation to guard');
        $this->assertStringContainsString('<a href="#" class="btn btn-badge btn-primary" id="submit-all"', $raw,
            self::VIEW . ': the header Save must stay an anchor. Converting it to a submit button '
            . 'would hand the save to native constraint validation, which already refuses this '
            . 'form on load (cost_price is required, empty, and hidden in variant mode)');
        $this->assertSame(1, substr_count($raw, 'data-ss-click-proxy="#submit-all"'),
            self::VIEW . ': the sticky action-bar Save must keep proxying to the same control');

        $code = $this->code();
        $this->assertSame(1, substr_count($code, self::HANDLER),
            self::VIEW . ': a second click binding would be a second, unguarded save path');
    }

    /**
     * The seven inert `attr('disabled', …)` writes STAY. They never guarded anything, but
     * `edit.blade.php` keeps its own and this repository's ratchets count source literals —
     * deleting seven lines is a larger diff than the defect.
     */
    public function test_the_inert_disabled_writes_are_left_alone(): void
    {
        $this->assertSame(7, preg_match_all(
            '/\$\(\'#submit-all\'\)\.attr\(\'disabled\', (?:true|false)\);/',
            $this->code()
        ), self::VIEW . ': the seven disabled writes changed count. They are inert for guarding '
            . '(the control is an anchor) and are not what this repair relies on');
    }

    /**
     * THE AJAX CONTRACT IS UNTOUCHED. No server code, no route, no schema: the same two POSTs to
     * `product.store`, the same multipart options, the same CSRF header, the same reading of
     * `flag`/`msg`/`errors`. The shipped Flutter app does not reach this route at all (it posts
     * to /addproduct and /updateproduct), so there is no client contract to break.
     */
    public function test_the_save_request_contract_is_unchanged(): void
    {
        $handler = $this->handler($this->code());

        $this->assertSame(2, preg_match_all('/\$\.ajax\(\{/', $handler),
            self::VIEW . ': still exactly two save requests, chosen by module');
        $this->assertSame(2, preg_match_all("/url: \"\{\{ route\('product\.store'\) \}\}\",/", $handler));
        $this->assertSame(2, preg_match_all("/'X-CSRF-TOKEN': \\\$\('meta\[name=\"csrf-token\"\]'\)\.attr\('content'\)/", $handler));
        $this->assertSame(2, preg_match_all('/contentType: false,/', $handler));
        $this->assertSame(2, preg_match_all('/processData: false,/', $handler));
        $this->assertSame(2, preg_match_all("/type: 'POST',/", $handler));
        $this->assertSame(2, preg_match_all('/data\.flag == "success"/', $handler));
        $this->assertSame(2, preg_match_all('/StoreSyriaDS\.applyFormErrors\(data\.errors, \{/', $handler));
    }

    /**
     * THE GUARD IS LOCAL. A latch in public/js/store-syria-ds.js or a `$(document).ajaxStart`
     * guard would block unrelated requests — this view alone fires four other `$.ajax` calls
     * (attribute and variant lookups) that must keep working mid-edit.
     */
    public function test_the_guard_did_not_leak_into_the_shared_bundle(): void
    {
        foreach (['public/js/store-syria-ds.js', 'public/js/loader.js'] as $shared) {
            $this->assertStringNotContainsString('ssSaveInFlight', (string) file_get_contents(base_path($shared)),
                $shared . ': the in-flight latch must stay in the one view that owns the handler');
        }

        $this->assertSame(6, preg_match_all('/\$\.ajax\(\{/', $this->code()),
            self::VIEW . ': the four non-save requests (attribute and variant lookups) plus the '
            . 'two save requests. A change here means the latch reached a request it should not');
    }

    /**
     * THE NEW COMMENTS MUST NOT TRIP THE RATCHETS THAT READ RAW SOURCE. Three of them do:
     * ValidationFeedbackVisibilityTest strips Blade comments only and counts `invalid-feedback`
     * and `@error(`; ArabicUiCoverageTest collects `__()` keys from the raw file and requires an
     * ar.json entry for each; NoBladeExpressionInsideCodeCommentTest treats `{{ … }}` or a
     * parenthesised directive inside a JS comment as live code, because Blade compiles it.
     */
    public function test_the_new_comments_cannot_trip_the_raw_source_ratchets(): void
    {
        $raw = (string) file_get_contents(base_path(self::VIEW));
        $comments = $this->commentText($raw);

        $this->assertNotSame('', trim($comments), 'premise: the view really does carry JS comments');

        foreach (['invalid-feedback', '@error(', "__('", '__("', '{{', '{!!'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $comments,
                self::VIEW . ': a JavaScript comment contains "' . $forbidden . '", which a raw-source '
                . 'ratchet counts as code');
        }

        $this->assertSame(0, preg_match('/(?<![\w@])@[a-zA-Z]+\s*\(/', $comments),
            self::VIEW . ': a parenthesised Blade directive inside a JS comment compiles');
    }

    /** The text inside this view's JavaScript block comments, Blade comments removed first. */
    private function commentText(string $raw): string
    {
        $raw = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);

        preg_match_all('#(?<![\w"\'/])/\*(.*?)\*/#s', $raw, $m);

        return implode("\n", $m[1] ?? []);
    }
}
