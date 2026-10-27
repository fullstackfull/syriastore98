<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2-06-9 — transient action feedback, announced to assistive technology.
 * =================================================================================================
 *
 * THE DEFECT IS MEASURED, NOT ASSUMED. `show_toastr()` is the merchant admin's transient feedback
 * surface — `layouts/app.blade.php` calls it for every flashed `success` and `error`, and 59 files
 * call it besides — and it delegates to `public/assets/js/plugins/notifier.js`. That file contains
 * ZERO occurrences of `aria-live`, `aria-atomic`, `role=` or any `aria-*` attribute, so every
 * success and failure message in the admin is invisible to a screen reader. A merchant using one
 * saves a product and is told nothing at all.
 *
 * THE FIX MIRRORS, IT DOES NOT REPLACE. The ledger's own constraint is that `show_toastr` "exists
 * and is the behaviour to preserve": 59 call sites and a visual toast that merchants already know.
 * So the message is additionally written into a live region, from the single `show_toastr`
 * definition, and nothing about the toast or its callers changes.
 *
 * AND IT CANNOT GO THROUGH `window.StoreSyriaDS`, which is the non-obvious constraint. The flash
 * calls at `layouts/app.blade.php:664` and `:671` are BARE INLINE SCRIPTS — not wrapped in a ready
 * handler — so they execute during parsing, while `store-syria-ds.js` is loaded with `defer` and
 * has not run yet. `window.StoreSyriaDS` is undefined at that moment. `custom.js` loads earlier
 * (`partision/footerlink.blade.php:64`, before the inline calls), so the announcement has to be
 * self-contained there and converge on the SAME region id the design system uses.
 *
 * TRANSIENT IS NOT THE SAME SURFACE AS THE INBOX, which is the other half of the acceptance. The
 * persistent notification inbox from P2-06-3 is `.ss-feed` plus the bell count; it is a list a
 * merchant VISITS, and giving it a live region would announce operational notifications as they
 * arrive, interrupting whatever they were doing. The two surfaces stay separate and this asserts
 * the separation rather than describing it.
 */
class TransientFeedbackContractTest extends TestCase
{
    private function repo(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    private function read(string $relative): string
    {
        $path = $this->repo($relative);

        $this->assertFileExists($path, "premise: {$relative} is missing");

        return (string) file_get_contents($path);
    }

    /**
     * Comments blanked before asserting. A source assertion that can be satisfied by prose ABOUT
     * the code is a test that cannot fail — measured twice in this phase, once on
     * `ColumnReorderContractTest` and once here.
     */
    private function withoutComments(string $source): string
    {
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~^[ \t]*//.*$~m', '', $source);
    }

    /**
     * PREMISE, and the reason the mirror exists at all. If a future upgrade of the notifier
     * plugin ships real ARIA, this fails and somebody re-reads the design instead of carrying a
     * second announcement forever.
     */
    public function test_premise_the_notifier_plugin_announces_nothing_itself(): void
    {
        $js = $this->read('public/assets/js/plugins/notifier.js');

        $this->assertSame(0, preg_match_all('~aria-[a-z]+~i', $js),
            'the notifier plugin now carries ARIA attributes of its own, so the mirrored '
            . 'announcement in show_toastr may be redundant or may now double-announce: '
            . 're-read P2-06-9 before leaving both in place');
    }

    /**
     * The transient surface announces — asserted as the CHAIN rather than through a byte window.
     *
     * The first version of this test read 2000 characters forward from `function show_toastr` and
     * looked for the region id in them. That failed against a correct implementation, because the
     * announcer is DEFINED ABOVE `show_toastr` and only CALLED from inside it. A window is the
     * wrong instrument for "these two things are connected": the two halves are asserted
     * separately and the call between them explicitly.
     */
    public function test_the_toast_announces_to_assistive_technology(): void
    {
        $js = $this->withoutComments($this->read('public/js/custom.js'));

        $start = strpos($js, 'function show_toastr');
        $this->assertNotFalse($start, 'premise: show_toastr was renamed or removed');

        // 1. the announcer exists and writes to a live region
        $this->assertStringContainsString('ss-live-region', $js,
            'nothing in custom.js writes to a live region, so every success and error in the '
            . 'merchant admin is silent for a screen reader');
        $this->assertStringContainsString("setAttribute('aria-live'", $js,
            'the region is created without an aria-live attribute, so it announces nothing');

        // 2. and show_toastr actually calls it — the half a window could not establish
        $body = substr($js, $start, 1200);
        $this->assertStringContainsString('ss_announce_transient(', $body,
            'show_toastr does not call the announcer, so the live region is never written to');
    }

    /**
     * ONE POLITE REGION, SHARED. Two live regions on a page compete: a screen reader may announce
     * either, in either order. `store-syria-ds.js` and `custom.js` therefore use the same id
     * rather than each creating their own.
     */
    public function test_the_design_system_and_the_toast_share_one_polite_region(): void
    {
        $ds = $this->withoutComments($this->read('public/js/store-syria-ds.js'));
        $custom = $this->withoutComments($this->read('public/js/custom.js'));

        $this->assertStringContainsString("'ss-live-region'", $ds,
            'premise: the design system no longer uses the shared polite region id');
        $this->assertStringContainsString('ss-live-region', $custom,
            'the toast does not use the shared region id, so there are two competing regions');
    }

    /**
     * AN ERROR IS ASSERTIVE, A SUCCESS IS POLITE. A failed save that waits for a pause in
     * speech can be missed entirely; a successful one that interrupts is noise. The two are
     * different regions because one element cannot be both.
     */
    public function test_an_error_is_announced_assertively(): void
    {
        $custom = $this->withoutComments($this->read('public/js/custom.js'));

        $this->assertStringContainsString("'assertive'", $custom,
            'nothing announces assertively, so an error toast waits for a pause in speech that '
            . 'may never come');
        $this->assertStringContainsString("'alert'", $custom,
            'the assertive region has no alert role to go with its aria-live');

        /* AND THE CHOICE IS DRIVEN BY THE TYPE, not fixed. A region that is always assertive
           would interrupt the merchant on every successful save. */
        $start = strpos($custom, 'function show_toastr');
        $body = substr($custom, (int) $start, 1200);

        $this->assertMatchesRegularExpression(
            "~ss_announce_transient\(\s*message\s*,\s*type\s*!=\s*'success'\s*\)~",
            $body,
            'the announcement urgency is not derived from the toast type, so either every '
            . 'success interrupts or no error does'
        );
    }

    /**
     * THE PERSISTENT INBOX IS A DIFFERENT SURFACE AND MUST NOT ANNOUNCE. P2-06-3's feed is a list
     * a merchant visits; a live region on it would read operational notifications aloud as they
     * arrive, over whatever they were doing.
     */
    public function test_the_notification_inbox_is_not_a_live_region(): void
    {
        foreach ([
            'resources/views/notification/index.blade.php',
            'resources/views/partision/header.blade.php',
        ] as $view) {
            $markup = (string) preg_replace('~\{\{--.*?--\}\}~s', '', $this->read($view));

            $this->assertStringNotContainsString('aria-live', $markup,
                $view . ' makes the persistent notification inbox a live region, so operational '
                . 'notifications are announced as they arrive rather than when the merchant '
                . 'chooses to read them — that is the surface P2-06-9 keeps distinct');
        }
    }
}
