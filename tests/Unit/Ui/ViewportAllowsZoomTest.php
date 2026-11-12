<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P2.5-WAVE-21 — the merchant shell does not ask the browser to disable pinch-zoom.
 * ================================================================================================
 *
 * FOUND BY MEASURING SOMETHING ELSE. A phone-width pass over the merchant list screens read the
 * document's own `<meta name="viewport">` off the rendered page and it carried:
 *
 *     width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui
 *
 * `user-scalable=0` asks the browser to prevent the user from zooming. Where it is honoured --
 * Chrome on Android honours it unless the person has turned on "Force enable zoom" -- a merchant
 * who needs to magnify cannot, which is WCAG 2.1 SC 1.4.4 Resize Text (AA). The ui-ux-pro-max
 * rule this project vendors names it directly: "viewport-meta -- width=device-width
 * initial-scale=1 (never disable zoom)".
 *
 * STATED HONESTLY: Safari on iOS has ignored `user-scalable=no` since iOS 10, so this is not a
 * failure on every device. It is a declaration asking for a failure, it is honoured somewhere,
 * and removing it cannot regress anything -- zoom becoming available is never a loss.
 *
 * `minimal-ui` goes with it. It was an iOS 7.1-only token, removed in iOS 8 in 2014, and has done
 * nothing in any browser for a decade.
 *
 * SCOPE. This covers the two layouts that ARE the Phase 2.5 Merchant Experience: layouts/app
 * (every merchant screen) and layouts/guest (sign-in).
 *
 * TWELVE MORE VIEWS CARRY THE SAME TOKEN, and the list below is MEASURED rather than grepped by
 * hand -- a hand search found six of the fourteen and this test found the rest on its first run.
 * They are recorded, not changed: Phase 06 owns the plan-purchase and marketplace views, Phase 03
 * owns the storefront, the gateway hand-off pages are the gateway's own flow, and
 * partision/head.blade.php is DEAD -- it is reached only from layouts/main.blade.php and MEASURED:
 * zero views extend layouts.main.
 */
class ViewportAllowsZoomTest extends TestCase
{
    /** The two layouts this phase owns. */
    private const MERCHANT_LAYOUTS = [
        'resources/views/layouts/app.blade.php',
        'resources/views/layouts/guest.blade.php',
    ];

    /**
     * Out of this phase's scope, asserted so the list cannot quietly grow and so the next phase
     * inherits a measured fact. Each entry is a view that still disables zoom.
     */
    private const OUT_OF_PHASE = [
        // Phase 06 — SaaS plan purchase and the marketplace. Several of these ALSO carry
        // `maximum-scale=1`, which is stricter than user-scalable=0.
        'marketplace/marketplace.blade.php',
        'plans/Braintreepayment.blade.php',
        'plans/Powertranz-payment.blade.php',
        'plans/Powertranz-planpayment.blade.php',
        'plans/cybersourcerequest.blade.php',
        'plans/request.blade.php',

        // Phase 03 — storefront. `front_end/layouts/app.blade.php` WAS here and is now FIXED
        // (P3-M2-E): it carried `maximum-scale=1`, axe flagged meta-viewport critical on every
        // storefront page, and the three theme copies under themes/*/views carried the same line.
        // All four are fixed and the theme copies are asserted below.
        //
        // The NMI hand-off stays. It was considered in M2-E and deliberately left: it is a payment
        // gateway's own page, and the owner's standing restrictions put payment flows outside an
        // incidental accessibility sweep. It is a real WCAG 1.4.4 failure and it is still owed.
        'front_end/payment/nmi-payment.blade.php',

        // Vendored payment-gateway hand-off pages, each a standalone document with its own
        // <html>. They are the gateway's own flow, not a Phase 2.5 surface.
        'DPO/pay.blade.php',
        'payment/authorizenet.blade.php',
        'payment/nmi-payment.blade.php',

        // DEAD, and kept in the list rather than fixed because fixing it would change nothing.
        // partision/head.blade.php is reached only from layouts/main.blade.php, and MEASURED:
        // ZERO views extend layouts.main. Deleting dead code is a different slice.
        'partision/head.blade.php',
    ];

    /**
     * P3-M2-E. The theme copies are the ones a shopper actually loads.
     *
     * `stores.theme_id` drives `Theme::set()`, which `prependLocation()`s the theme's own view
     * directory, so `front_end/layouts/app.blade.php` resolves to the THEME's copy and the one in
     * `resources/views` is only the fallback for a store whose theme is empty. All four existed
     * with `maximum-scale=1`. The scan below covers `resources/views` only, so without this the
     * three theme copies could be re-broken and nothing would notice.
     */
    private const STOREFRONT_LAYOUTS = [
        'themes/stylique/views/front_end/layouts/app.blade.php',
        'themes/techzonix/views/front_end/layouts/app.blade.php',
        'themes/greentic/views/front_end/layouts/app.blade.php',
        'resources/views/front_end/layouts/app.blade.php',
    ];

    public function test_premise_every_storefront_layout_declares_a_viewport(): void
    {
        foreach (self::STOREFRONT_LAYOUTS as $layout) {
            $this->assertMatchesRegularExpression(
                '/<meta\s+name="viewport"/i', $this->read($layout),
                $layout . ' no longer declares a viewport at all, so the assertions below are '
                . 'vacuous.');
        }
    }

    public function test_no_storefront_layout_disables_zoom(): void
    {
        foreach (self::STOREFRONT_LAYOUTS as $layout) {
            $tag = $this->viewportTag($layout);

            $this->assertDoesNotMatchRegularExpression('/user-scalable\s*=\s*(0|no)/i', $tag,
                $layout . ': the storefront viewport asks the browser to disable zoom. Tag: ' . $tag);

            $this->assertDoesNotMatchRegularExpression('/maximum-scale\s*=\s*1(\.0)?\b/i', $tag,
                $layout . ': `maximum-scale=1` caps zoom at 100%. This is what axe reported as '
                . 'meta-viewport (critical) on every storefront page. Tag: ' . $tag);
        }
    }

    public function test_every_storefront_layout_keeps_a_responsive_viewport(): void
    {
        foreach (self::STOREFRONT_LAYOUTS as $layout) {
            $tag = $this->viewportTag($layout);

            $this->assertMatchesRegularExpression('/width\s*=\s*device-width/i', $tag,
                $layout . ': the viewport no longer sets width=device-width.');
            $this->assertMatchesRegularExpression('/initial-scale\s*=\s*1/i', $tag,
                $layout . ': the viewport no longer sets initial-scale=1.');
        }
    }

    /** No theme view may reintroduce the token the merchant shell and the storefront shed. */
    public function test_no_theme_view_disables_zoom(): void
    {
        $root  = dirname(__DIR__, 3) . '/themes';
        $found = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));

            if (preg_match('/<meta[^>]*name="viewport"[^>]*>/i', $source, $m)
                && preg_match('/user-scalable\s*=\s*(0|no)|maximum-scale\s*=\s*1(\.0)?\b/i', $m[0])) {
                $found[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        sort($found);

        $this->assertSame([], $found,
            "A theme view disables pinch-zoom. The three storefront layouts were fixed in M2-E; "
            . "this is a regression or a new theme that copied the old line.\n  found: "
            . implode(', ', $found));
    }

    public function test_premise_both_merchant_layouts_declare_a_viewport(): void
    {
        foreach (self::MERCHANT_LAYOUTS as $layout) {
            $this->assertMatchesRegularExpression(
                '/<meta\s+name="viewport"/i', $this->read($layout),
                $layout . ' no longer declares a viewport at all, so the assertions below are '
                . 'vacuous. A responsive admin needs one.');
        }
    }

    public function test_neither_merchant_layout_disables_zoom(): void
    {
        foreach (self::MERCHANT_LAYOUTS as $layout) {
            $tag = $this->viewportTag($layout);

            $this->assertDoesNotMatchRegularExpression('/user-scalable\s*=\s*(0|no)/i', $tag,
                $layout . ': the viewport asks the browser to disable zoom, which fails WCAG 2.1 '
                . 'SC 1.4.4 wherever it is honoured. Tag: ' . $tag);

            $this->assertDoesNotMatchRegularExpression('/maximum-scale\s*=\s*1(\.0)?\b/i', $tag,
                $layout . ': `maximum-scale=1` caps zoom at 100%, which is the same failure by '
                . 'another route. Tag: ' . $tag);
        }
    }

    /** It still has to BE a responsive viewport — removing the wrong token is not the whole job. */
    public function test_both_merchant_layouts_keep_a_responsive_viewport(): void
    {
        foreach (self::MERCHANT_LAYOUTS as $layout) {
            $tag = $this->viewportTag($layout);

            $this->assertMatchesRegularExpression('/width\s*=\s*device-width/i', $tag,
                $layout . ': the viewport no longer sets width=device-width.');
            $this->assertMatchesRegularExpression('/initial-scale\s*=\s*1/i', $tag,
                $layout . ': the viewport no longer sets initial-scale=1.');
        }
    }

    /** A decade-dead iOS 7.1 token. Asserted gone so it is not re-pasted from a vendored view. */
    public function test_the_dead_minimal_ui_token_is_gone_from_the_merchant_layouts(): void
    {
        foreach (self::MERCHANT_LAYOUTS as $layout) {
            $this->assertStringNotContainsString('minimal-ui', $this->viewportTag($layout),
                $layout . ': `minimal-ui` was removed in iOS 8 in 2014 and does nothing anywhere.');
        }
    }

    /**
     * The out-of-phase list is asserted in BOTH directions: a NEW view that disables zoom fails
     * here, and so does one of these being fixed without its entry being deleted.
     */
    public function test_the_out_of_phase_list_is_exactly_the_views_that_still_disable_zoom(): void
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $found = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));

            if (preg_match('/<meta[^>]*name="viewport"[^>]*>/i', $source, $m)
                && preg_match('/user-scalable\s*=\s*(0|no)|maximum-scale\s*=\s*1(\.0)?\b/i', $m[0])) {
                $found[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        sort($found);
        $expected = self::OUT_OF_PHASE;
        sort($expected);

        $this->assertSame($expected, $found,
            "The set of views that disable zoom has moved. If one was FIXED, delete its entry "
            . "here; if a NEW one appeared, it is a regression.\n  expected: "
            . implode(', ', $expected) . "\n  found:    " . implode(', ', $found));
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function viewportTag(string $layout): string
    {
        $this->assertTrue(
            (bool) preg_match('/<meta[^>]*name="viewport"[^>]*>/i', $this->read($layout), $m),
            $layout . ' has no viewport meta tag.');

        return $m[0];
    }
}
