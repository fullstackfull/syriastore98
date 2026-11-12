<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P2.5-WAVE-17 — no merchant view may close a `<div>` it did not open.
 * ================================================================================================
 *
 * A Blade view that emits more `</div>` than `<div>` closes an element its PARENT opened: for a
 * `@section('content')` that is one of layouts/app.blade.php's own wrappers, and for an AJAX modal
 * partial it is `#commanModel .modal-body`.
 *
 * HOW BAD IS IT TODAY? MEASURED, AND THE HONEST ANSWER IS "NOT VISIBLY AT ALL".
 * The DOM of /coupon (which carried a stray closer) was compared element by element against
 * /category (which did not): identical datatable depth, identical ancestor chain, identical footer
 * placement, identical direct children of <body>. The HTML parser discards an unmatched close tag
 * when the element it would close is not open in the fragment, and the layout emits nothing between
 * `@yield('content')` and its own two closers, so there was nothing to escape.
 *
 * SO THIS IS A LATENT FAULT, NOT A LIVE DEFECT, and it is recorded that way rather than dressed up:
 * the five removed in P2.5-WAVE-17 changed no pixel. What they change is the next edit. The moment
 * anything is added after the content yield -- a toast region, a sticky action bar, a footer slot --
 * every one of these views would push it out of its container, and the symptom would appear on
 * pages whose own markup nobody touched.
 *
 * FIVE WERE REMOVED: coupon/index, faq/create, blog/create, blog-category/index (one each) and
 * menu/edit (two).
 *
 * THE TWO EXEMPTIONS ARE STOREFRONT, NOT MERCHANT. front_end/* is Phase 03 scope by the approved
 * six-phase plan, so they are declared here by name rather than silently skipped -- a third one
 * fails this test, and fixing either of these two also fails it, which is the point: the list is
 * a statement about what is known, and it has to be edited deliberately.
 */
class BladeDivBalanceTest extends TestCase
{
    /**
     * Storefront views carrying one extra closer each. Phase 03, not Phase 2.5 Merchant Experience.
     * Resolve one and DELETE its entry -- do not leave it here.
     */
    private const PHASE_03_EXEMPT = [
        'front_end/pages/contact.blade.php',
        'front_end/pages/reply_support_ticket.blade.php',
    ];

    /** The census must have actually read the view tree, or every assertion below is vacuous. */
    private const MINIMUM_VIEWS_PARSED = 400;

    public function test_premise_the_census_really_reads_the_view_tree(): void
    {
        [$parsed, ] = $this->census();

        $this->assertGreaterThan(self::MINIMUM_VIEWS_PARSED, $parsed,
            'The census parsed ' . $parsed . ' views. It is meant to walk the whole tree; a number '
            . 'this low means the walk broke and the balance assertions below prove nothing.');
    }

    /**
     * Bite-tested: adding a bare `</div>` to resources/views/tag/create.blade.php fails this with
     * that file named.
     */
    public function test_no_merchant_view_closes_a_div_it_did_not_open(): void
    {
        [, $offenders] = $this->census();

        $unexpected = array_diff(array_keys($offenders), self::PHASE_03_EXEMPT);

        $this->assertSame([], array_values($unexpected),
            "These views emit more </div> than <div>, so each closes an element its parent opened:\n  "
            . implode("\n  ", array_map(
                static fn ($f) => $f . ' (+' . $offenders[$f] . ')', $unexpected)));
    }

    /**
     * The exemptions are asserted in BOTH directions. If one is fixed, this fails and the entry has
     * to be deleted -- which is how a stale exemption list is prevented from outliving its reason.
     */
    public function test_every_declared_exemption_is_still_unbalanced(): void
    {
        [, $offenders] = $this->census();

        foreach (self::PHASE_03_EXEMPT as $exempt) {
            $this->assertArrayHasKey($exempt, $offenders,
                $exempt . ' is balanced now, so its exemption is stale. DELETE the entry rather '
                . 'than leaving this test asserting a fault that no longer exists.');
        }
    }

    /**
     * Views with more OPENERS than closers are legitimate: a partial opens a wrapper its includer
     * closes. Asserted so the census is not mistaken for a claim about those.
     */
    public function test_views_that_open_more_than_they_close_are_not_treated_as_offenders(): void
    {
        [, $offenders] = $this->census();

        foreach ($offenders as $file => $extra) {
            $this->assertGreaterThan(0, $extra,
                $file . ' was recorded as an offender with a non-positive surplus, so the census '
                . 'is counting the wrong direction.');
        }
    }

    /**
     * @return array{0:int, 1:array<string,int>} views parsed, and file => surplus closers
     */
    private function census(): array
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $parsed = 0;
        $offenders = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $parsed++;
            $css = (string) file_get_contents($file->getPathname());

            // Blade and HTML comments are stripped FIRST so the census cannot count its own prose
            // -- the "detector matching its own comment" failure this project has had before.
            $css = preg_replace('/\{\{--.*?--\}\}/s', '', $css);
            $css = preg_replace('/<!--.*?-->/s', '', $css);

            $open = preg_match_all('/<div\b/i', $css);
            $close = preg_match_all('/<\/div\s*>/i', $css);

            if ($close > $open) {
                $offenders[str_replace($root . '/', '', $file->getPathname())] = $close - $open;
            }
        }

        return [$parsed, $offenders];
    }
}
