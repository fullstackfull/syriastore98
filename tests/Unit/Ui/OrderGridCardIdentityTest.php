<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-ORD-7 / ORD-9 / ORD-11 — the order card could not say which order it was.
 * =================================================================================================
 *
 * Three confirmed ORDERS findings that are one defect seen from three angles, and the third is a
 * delete flow:
 *
 *  #7  `resources/views/order/grid.blade.php:143` gave the reference — the card's own title and its
 *      only identifying fact — `text-truncate`, inside a flex row it shared with a status badge
 *      that never shrinks. MEASURED in the committed captures: in `_order_grid__en_ltr_light.png`
 *      cards 1 and 2 both read `#P25CUSTFILTE…`, both `Omar Khalil`, both `$150.00`. The list view
 *      of the same two orders shows `#P25CUSTFILTER-2-2` and `#P25CUSTFILTER-2-1` — they differ in
 *      the LAST character, which is exactly what the ellipsis removed. Two cards that are one
 *      order to the eye were two orders with different money attached.
 *
 *  #9  The one affordance that could have revealed the elided characters was spent on a constant:
 *      `title="{{ __('Invoice ID') }}"`. Bootstrap 5.1.3's `_fixTitle` moves that into
 *      `data-bs-original-title` and `getTitle()` reads it first, so hover AND focus showed the
 *      words "Invoice ID" and never the id.
 *
 *  #11 The delete button (`:221-224`) passed only `data-confirm="Are You Sure?"` and
 *      `data-text="This action can not be undone…"`. So a merchant looking at two cards that read
 *      the same clicked the trash on one, was asked a question that named no order, confirmed, and
 *      `OrderController::destroy` removed it permanently. The full id existed only in the
 *      invisible `aria-label`.
 *
 * WHAT THE FIX IS
 *
 *   - the reference gets the card's full width on its own line and keeps `overflow-wrap: anywhere`
 *     instead of `text-truncate`, so a long reference WRAPS and no character is ever removed. The
 *     audit is explicit that shrinking the badge is not a fix: "the ids in this fixture differ only
 *     in their final character, so any truncation reintroduces the collision";
 *   - the status badge moves down beside the customer, still inside `card-header` — which is what
 *     `OrderGridStatusLabelTest` asserts, and that test is the reason it did not move out of the
 *     header altogether;
 *   - the tooltip carries the reference, and the words "Invoice ID" survive in the link's
 *     accessible name rather than being deleted;
 *   - the confirmation names the order.
 *
 * TWO HAZARDS THIS FILE PINS, both already paid for in this repository:
 *
 *   `>` INSIDE A BLADE EXPRESSION. `ConfirmationDialogContractTest` scans for
 *   `/<[^>]*\bshow_confirm\b[^>]*>/`, so an attribute containing `['id' => ...]` ahead of
 *   `data-confirm` ends that character class mid-tag and the dialog reads as carrying no
 *   translated message. `grid.blade.php:210-220` documents it; the `data-text` added here is
 *   therefore asserted to sit AFTER `data-confirm`.
 *
 *   THE REGION MARKERS. `OrderGridStatusLabelTest` and `OrderGridCardContentTest` find the header
 *   and the body by searching this view for their opening `class` attributes. Both strings are
 *   asserted intact below, so a future reflow of this card fails here — naming the cause — instead
 *   of surfacing as an unrelated status-badge failure.
 */
class OrderGridCardIdentityTest extends TestCase
{
    private const VIEW = 'resources/views/order/grid.blade.php';

    private function view(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::VIEW;

        $this->assertFileExists($path, 'premise: ' . self::VIEW);

        return (string) file_get_contents($path);
    }

    /**
     * The view with every Blade comment removed.
     *
     * THE FILE DOCUMENTS ITS OWN MARKERS, which is a trap this repository has already fallen into
     * once: `grid.blade.php` carries the note "AND THIS COMMENT MUST NOT SPELL THOSE TWO MARKERS
     * OUT, which the first draft did: writing them here put an earlier match in the file than the
     * real elements". The comments explaining ORD-7/9/11 name `show_confirm`, `text-truncate` and
     * `Invoice ID` for the same reason, so a scan of the raw file finds the explanation before the
     * element. Every scan below runs against this instead, and no comment can satisfy or break
     * any of them.
     */
    private function markup(): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $this->view());
    }

    public function test_premise_the_blade_comment_stripper_works(): void
    {
        $markup = $this->markup();

        $this->assertStringNotContainsString('MUST NOT SPELL THOSE TWO MARKERS OUT', $markup,
            'a Blade comment survived, so the scans below can read prose as markup');
        $this->assertStringContainsString('<div class="card-header p-3 border-bottom h-100">', $markup,
            'the stripper removed markup as well as comments');
    }

    /**
     * The whole of the first `<tag ...>` whose opening text contains $needle, QUOTE-AWARE.
     *
     * THIS FILE CANNOT USE `[^>]*` AND THAT IS THE POINT. Both elements it measures now carry a
     * Blade `['id' => ...]` array, so their attribute values contain a literal `>`. A `[^>]*`
     * or `.*?>` scanner stops there, mid-attribute, and reports the rest of the tag as absent --
     * which is exactly the hazard `grid.blade.php` documents, and which the first version of this
     * very test fell into on all three of its own assertions. So the tag is found by walking the
     * source and tracking whether the cursor is inside a quoted value.
     */
    private function tagContaining(string $needle): string
    {
        $src = $this->markup();
        $at = strpos($src, $needle);

        $this->assertNotFalse($at, 'premise: the view contains "' . $needle . '"');

        $open = strrpos(substr($src, 0, $at), '<');
        $this->assertNotFalse($open, 'premise: "' . $needle . '" sits inside a tag');

        $quote = null;
        $length = strlen($src);

        for ($i = $open; $i < $length; $i++) {
            $char = $src[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                return substr($src, $open, $i - $open + 1);
            }
        }

        $this->fail('the tag containing "' . $needle . '" is never closed');
    }

    public function test_premise_the_tag_reader_sees_past_a_blade_arrow(): void
    {
        /* Without this, the three assertions that use `tagContaining()` could pass by reading a
         * truncated tag -- and two of them would then be asserting about prose. */
        $tag = $this->tagContaining('ss-order-card__ref');

        $this->assertStringContainsString("['id' =>", $tag,
            'premise: the link really does carry a Blade array, so the hazard is live');
        $this->assertStringEndsWith('>', $tag);
        $this->assertStringContainsString('<bdi>', $this->markup(),
            'premise: the reference is still wrapped for bidi');
    }

    /** The `card-header` block, which is where the identity lives. */
    private function header(): string
    {
        $src = $this->markup();

        $start = strpos($src, '<div class="card-header p-3 border-bottom h-100">');
        $end = strpos($src, '<div class="card-body p-3 gap-1 d-flex align-items-center justify-content-end">');

        $this->assertNotFalse($start,
            'premise: the card-header opening tag is byte-identical to what '
            . 'OrderGridStatusLabelTest and OrderGridCardContentTest search for. If this failed, '
            . 'those two are now asserting against prose.');
        $this->assertNotFalse($end, 'premise: the card-body opening tag is unchanged');
        $this->assertGreaterThan($start, $end, 'premise: the body follows the header');

        return substr($src, $start, $end - $start);
    }

    // =============================================================================================
    //  #7 — the reference is never elided
    // =============================================================================================

    public function test_the_order_reference_is_not_truncated(): void
    {
        $header = $this->header();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="[^"]*ss-card__title[^"]*"/',
            $header,
            'premise: the reference is still the card title'
        );

        if (preg_match('/<a[^>]*class="([^"]*ss-card__title[^"]*)"/', $header, $m) !== 1) {
            $this->fail('could not read the title link\'s class list');
        }

        $this->assertStringNotContainsString('text-truncate', $m[1],
            'the order reference still carries `text-truncate`. Two orders in the shipped fixture '
            . 'differ only in their final character, so an ellipsis makes them the same card: '
            . $m[1]);
    }

    public function test_the_reference_carries_the_class_that_lets_it_wrap(): void
    {
        /* Dropping `text-truncate` alone leaves a long unbroken Latin reference to overflow the
         * card rather than wrap, which trades an invisible character for a broken layout. */
        $this->assertStringContainsString('ss-order-card__ref', $this->header(),
            'the reference has no wrapping hook, so removing text-truncate lets it overflow');

        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/store-syria-ds.css');

        $this->assertStringContainsString('.ss-order-card__ref', $css,
            'the class is on the element but the design system does not define it');
        $this->assertMatchesRegularExpression(
            '/\.ss-order-card__ref\s*\{[^}]*overflow-wrap:\s*anywhere/s',
            $css,
            'the rule exists but does not let a long reference wrap'
        );
    }

    public function test_the_status_badge_no_longer_competes_with_the_title_for_width(): void
    {
        $header = $this->header();

        $titleAt = strpos($header, 'ss-card__title');
        $badgeAt = strpos($header, 'OrderStatusTone::badge');

        $this->assertNotFalse($titleAt, 'premise: the title is in the header');
        $this->assertNotFalse($badgeAt,
            'premise: the badge is still rendered inside the header — OrderGridStatusLabelTest '
            . 'asserts exactly that, so it must not have been moved out of the region');

        $between = substr($header, $titleAt, $badgeAt - $titleAt);

        $this->assertStringContainsString('</a>', $between,
            'premise: the title link closes before the badge');
        $this->assertStringContainsString('ss-card__meta', $between,
            'the badge still sits in the title\'s own row. It must come after the meta line, so '
            . 'the reference has the full content width.');
    }

    // =============================================================================================
    //  #9 — the tooltip shows the id, and the label survives
    // =============================================================================================

    public function test_the_tooltip_carries_the_order_reference_and_not_a_constant(): void
    {
        $link = $this->tagContaining('ss-order-card__ref');

        $this->assertStringContainsString('data-bs-toggle="tooltip"', $link,
            'premise: the title still has a tooltip');

        $this->assertDoesNotMatchRegularExpression(
            "/title=\"\\{\\{ __\\('Invoice ID'\\) \\}\\}\"/",
            $link,
            'the tooltip is still the constant words "Invoice ID", so the elided characters of the '
            . 'reference remain unreadable: ' . $link
        );

        $this->assertStringContainsString('product_order_id', $link,
            'the tooltip does not show the reference: ' . $link);
    }

    public function test_the_words_invoice_id_are_not_simply_deleted(): void
    {
        /* The label was the only thing naming the link for a screen reader. Taking it out of the
         * tooltip must not take it out of the accessibility tree: a bare `#P25CUSTFILTER-2-2` is
         * announced as an identifier of nothing. */
        $link = $this->tagContaining('ss-order-card__ref');

        $this->assertStringContainsString("__('Invoice ID", $link,
            'the link no longer carries the translated words "Invoice ID" anywhere: ' . $link);
        $this->assertStringContainsString('aria-label', $link,
            'the label must reach the accessibility tree, not just the markup');
    }

    // =============================================================================================
    //  #11 — the delete dialog names its target
    // =============================================================================================

    public function test_the_delete_confirmation_names_the_order(): void
    {
        $button = $this->tagContaining('show_confirm');

        $this->assertStringContainsString('product_order_id', $button,
            'the delete confirmation still names no order, so a destructive action is confirmed '
            . 'against a target the merchant cannot identify: ' . $button);

        $this->assertDoesNotMatchRegularExpression(
            "/data-text=\"\\{\\{ __\\('This action can not be undone\\. Do you want to continue\\?'\\) \\}\\}\"/",
            $button,
            'data-text is still the generic sentence'
        );
    }

    public function test_the_delete_buttons_attribute_order_keeps_the_dialog_scanner_working(): void
    {
        /* The hazard documented at grid.blade.php:210-220, as an assertion rather than a comment.
         * `ConfirmationDialogContractTest` reads `/<[^>]*\bshow_confirm\b[^>]*>/`, so a `>` from a
         * Blade `['id' => ...]` array ahead of `data-confirm` truncates the match and that test
         * reports this button as having no translated confirmation — a false i18n failure whose
         * real cause is attribute order. */
        $button = $this->tagContaining('show_confirm');

        $confirmAt = strpos($button, 'data-confirm=');
        $this->assertNotFalse($confirmAt, 'premise: the button carries data-confirm');

        foreach (["=>", "'id'"] as $needle) {
            $at = strpos($button, $needle);
            if ($at === false) {
                continue;
            }

            $this->assertGreaterThan($confirmAt, $at,
                'an attribute containing "' . $needle . '" (and therefore a `>`) sits BEFORE '
                . 'data-confirm. ConfirmationDialogContractTest\'s [^>]* stops at it and then '
                . 'reports this dialog as untranslated.');
        }
    }

    public function test_the_scanner_that_hazard_is_about_still_matches_this_button(): void
    {
        /* The premise for the test above: if the regex stopped matching for any other reason, the
         * ordering assertion would be guarding nothing. */
        $src = $this->view();

        $this->assertSame(1, preg_match('/<[^>]*\bshow_confirm\b[^>]*>/', $src),
            'ConfirmationDialogContractTest\'s own pattern no longer finds this button');
    }
}
